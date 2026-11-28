<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\Accounts;
use Espo\Modules\FeatureCredits\Billing\Checkout;
use Espo\Modules\FeatureCredits\Billing\Creation;
use Espo\Modules\FeatureCredits\Billing\OfferTerms;
use Espo\Modules\FeatureCredits\Billing\PlanTerms;
use Espo\Modules\FeatureCredits\Billing\Subscriptions;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;

/**
 * Changing the attendants or monthly credits of an active plan (decided
 * 2026-11-27; there are no one-off top-ups). Increases take effect now and are
 * paid now, on a one-off invoice:
 *  - a credit step costs its full monthly price and brings all its credits at
 *    once (credits are a pool: a team that ran out mid-month gets what it needs);
 *  - an attendant costs its price for the time left in the paid period and
 *    brings that share of its included credits (adding attendants and removing
 *    them again gains nothing).
 * Decreases wait for the renewal and refund nothing. From the renewal on, the
 * same card subscription charges the new monthly price (Subscriptions applies
 * the change once the difference is paid).
 *
 * Monthly credits go no lower than PlanOffers::UPGRADE_MIN_CREDITS (decided
 * 2026-11-28); a plan bought with fewer (attendants only) may keep what it has
 * while it changes attendants.
 */
class PlanChange
{
    /** Asaas charges no less than this. */
    private const MIN_CHARGE = '5.00';

    /** Queued changes not applied yet. */
    private const QUEUED = ['awaiting_payment', 'ready', 'dispatched'];

    public function __construct(
        private EntityManager $entityManager,
        private Accounts $accounts,
        private Checkout $checkout,
        private Creation $creation,
        private Subscriptions $subscriptions,
        private PlanOffers $offers,
        private SeatRoster $seatRoster,
        private Clock $clock,
        private Log $log,
    ) {}

    /**
     * What the change screen needs: the plan now (what the workspace has in the
     * paid period), what renews, the steps and prices, and the time left in the
     * period. The screen previews with the same rules; `start` decides.
     */
    public function offer(string $tenantId, ?string $actorId = null): array
    {
        $this->settle($tenantId);
        $plan = $this->plan($tenantId);

        if ($plan === null) {
            return ['canChange' => false, 'blockedReason' => 'NO_ACTIVE_SUBSCRIPTION'];
        }

        if ($plan['period'] === null) {
            return ['canChange' => false, 'blockedReason' => 'RENEWAL_PENDING'];
        }

        $next = PlanTerms::latest($plan['accepted'], $plan['record']);
        $catalog = $this->offers->list($tenantId, self::minCredits($next));
        $roster = $this->seatRoster->read($tenantId, $actorId);
        $level = $this->level($plan);
        $queued = self::queued($plan['record']);

        return [
            'canChange' => true,
            'currency' => $catalog['currency'],
            'plan' => [
                'seatPrice' => $catalog['plan']['seatPrice'],
                'includedCredits' => $catalog['plan']['includedCredits'],
                'minSeats' => max($catalog['plan']['minSeats'], $roster['minimumSeats']),
                'maxSeats' => $catalog['plan']['maxSeats'],
            ],
            'credits' => array_map(static fn (array $step) => array_intersect_key($step, array_flip(['key', 'credits', 'price'])),
                $catalog['credits']),
            'current' => ['seats' => $level['seats'], 'credits' => self::whole($level['baseCredits']), 'basePrice' => $level['basePrice']],
            'next' => ['seats' => $next['seats']['quantity'], 'credits' => self::whole(PlanTerms::baseCredits($next)),
                'price' => $next['price'], 'from' => $plan['period']['end']],
            'period' => $plan['period'] + ['remaining' => self::seconds($plan['now'], $plan['period']['end']),
                'length' => self::seconds($plan['period']['start'], $plan['period']['end'])],
            'pending' => $queued === null ? null : [
                'state' => $queued['state'] === 'awaiting_payment' ? 'awaiting_payment' : 'applying',
                'seats' => $queued['terms']['seats']['quantity'],
                'credits' => self::whole(PlanTerms::baseCredits($queued['terms'])),
            ],
            'seatAccessPending' => $roster['pending'],
        ];
    }

    /**
     * Changes the plan to $seats attendants and $credits monthly credits. With
     * something to pay now, returns the invoice to pay (the change applies once
     * paid); otherwise the change is scheduled for the renewal.
     *
     * @return array{state: string, paymentUrl: ?string}
     * @throws Conflict NO_ACTIVE_SUBSCRIPTION, RENEWAL_PENDING, SEAT_ACCESS_PENDING, SEATS_BELOW_ENABLED_USERS, PAYER_REQUIRED
     * @throws InvalidArgumentException unknown credits or seats, NO_CHANGE
     * @throws ServiceUnavailable the payment provider didn't confirm a step; retry
     */
    public function start(string $tenantId, string $actorId, int $seats, string $credits, ?string $returnUrl = null): array
    {
        $plan = $this->plan($tenantId) ?? throw new Conflict('NO_ACTIVE_SUBSCRIPTION');

        if ($plan['period'] === null) {
            throw new Conflict('RENEWAL_PENDING');
        }

        $this->seatRoster->assertCheckout($tenantId, $seats);
        $next = PlanTerms::latest($plan['accepted'], $plan['record']);
        $quote = $this->quote($tenantId, $plan, $seats, $credits, self::minCredits($next));
        $terms = $quote['terms'];

        if ($seats === $next['seats']['quantity'] && BigDecimal::of(PlanTerms::baseCredits($next))->isEqualTo($terms->seats['baseCredits'])) {
            throw new InvalidArgumentException('NO_CHANGE');
        }

        $obligationId = (string) $plan['obligation']['id'];
        $description = PlanCheckout::description($quote['offer'], $seats);

        // The same change waiting for its payment: continue its invoice.
        foreach (array_reverse($plan['record']['changes'] ?? []) as $change) {
            if ($change['state'] === 'awaiting_payment' && $change['terms']['seats']['quantity'] === $seats &&
                BigDecimal::of(PlanTerms::baseCredits($change['terms']))->isEqualTo($terms->seats['baseCredits'])) {
                return $this->invoice($tenantId, (string) $change['fundingObligationId'], $change['description'], $returnUrl);
            }
        }

        if (!BigDecimal::of($quote['payNow'])->isPositive()) {
            $this->subscriptions->requestChange($tenantId, $obligationId, self::key('plan-change'), $actorId, $terms, $description, null);
            $this->settle($tenantId);

            return ['state' => 'scheduled', 'paymentUrl' => null];
        }

        $context = $this->accounts->context($tenantId);
        $purchase = new OfferTerms('plan-change', 'v1', 'plan-change', 'v1', 'purchase', ['BR'], 'BRL',
            $quote['payNow'], $quote['creditsNow'], null, $tenantId);
        $receipt = $this->accounts->acceptPrivate($tenantId, self::key('plan-change-charge'), (int) $context['revision'],
            (string) $context['catalogId'], $purchase, $actorId);
        $this->subscriptions->requestChange($tenantId, $obligationId, self::key('plan-change'), $actorId, $terms, $description,
            $receipt['obligationId']);

        return $this->invoice($tenantId, $receipt['obligationId'], $description, $returnUrl);
    }

    /**
     * Applies queued changes whose difference is paid. Called whenever the plan
     * is read and by the workspace access job; a failed attempt is retried there.
     */
    public function settle(string $tenantId): void
    {
        $plan = $this->plan($tenantId);

        if ($plan === null || self::queued($plan['record']) === null) {
            return;
        }

        try {
            $this->subscriptions->applyChanges($tenantId, (string) $plan['obligation']['id']);
        } catch (ServiceUnavailable | Conflict $e) {
            $this->log->warning("SelfService: plan change of tenant {$tenantId} not applied yet: {$e->getMessage()}");
        }
    }

    /** Settles every workspace with a queued plan change. */
    public function settleAll(): void
    {
        $query = $this->entityManager->getPDO()->query(
            "SELECT DISTINCT tenant_id FROM credit_billing_obligation
             WHERE deleted = FALSE AND operation = 'subscription' AND state = 'active' AND subscription_record IS NOT NULL"
        );

        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $tenantId) {
            $this->settle((string) $tenantId);
        }
    }

    /**
     * The price and credits of a change, now and from the renewal.
     *
     * @return array{offer: OfferTerms, terms: OfferTerms, payNow: string, creditsNow: string}
     */
    private function quote(string $tenantId, array $plan, int $seats, string $credits, int $minCredits): array
    {
        $offer = $this->offers->find($tenantId, $credits, $minCredits) ?? throw new InvalidArgumentException('Unknown monthly credits.');
        $terms = $offer->withQuantity($seats);
        $level = $this->level($plan);
        $remaining = self::seconds($plan['now'], $plan['period']['end']);
        $length = self::seconds($plan['period']['start'], $plan['period']['end']);

        $pay = BigDecimal::zero();
        $creditsNow = BigDecimal::zero();
        $addedCredits = BigDecimal::of($terms->seats['baseCredits'])->minus($level['baseCredits']);

        if ($addedCredits->isPositive()) {
            $pay = BigDecimal::max(BigDecimal::of($terms->seats['basePrice'])->minus($level['basePrice']), BigDecimal::zero());
            $creditsNow = $addedCredits;
        }

        $addedSeats = max(0, $seats - $level['seats']);

        if ($addedSeats > 0) {
            $pay = $pay->plus(BigDecimal::of($terms->seats['unitPrice'])->multipliedBy($addedSeats)->multipliedBy($remaining)
                ->dividedBy($length, 2, RoundingMode::HALF_UP));
            $creditsNow = $creditsNow->plus(BigDecimal::of($terms->seats['includedCredits'])->multipliedBy($addedSeats)
                ->multipliedBy($remaining)->dividedBy($length, 4, RoundingMode::DOWN));
        }

        if ($pay->isPositive() && $pay->isLessThan(self::MIN_CHARGE)) {
            $pay = BigDecimal::of(self::MIN_CHARGE);
        }

        return ['offer' => $offer, 'terms' => $terms, 'payNow' => (string) $pay->toScale(2), 'creditsNow' => (string) $creditsNow->toScale(4)];
    }

    /** The invoice of a change's difference, created on first use. */
    private function invoice(string $tenantId, string $fundingId, string $description, ?string $returnUrl): array
    {
        $view = $this->checkout->read($tenantId, $fundingId);
        $customer = $view['customer'];

        if (!$customer || $customer['state'] !== 'bound') {
            // The plan's payer pays its changes.
            throw new Conflict('PAYER_REQUIRED');
        }

        if (!$view['charge'] || $view['charge']['state'] !== 'bound') {
            $credits = number_format((int) floor((float) $view['acceptance']['terms']['credits']), 0, ',', '.');
            $charge = $this->creation->charge($tenantId, $fundingId, $customer['creationId'], 'UNDEFINED', (string) $view['earliestDueDate'],
                ['description' => "Mudança de plano Monostax: {$credits} créditos de IA agora. A partir da renovação: {$description}"]
                    + ($returnUrl !== null ? ['successUrl' => $returnUrl] : []));

            if ($charge['state'] !== 'bound') {
                throw new ServiceUnavailable('The charge was not confirmed yet.');
            }
        }

        $payment = $this->checkout->payment($tenantId, $fundingId);

        if ($payment['state'] === 'paid') {
            $this->settle($tenantId);

            return ['state' => 'paid', 'paymentUrl' => null];
        }

        if (!$payment['invoiceUrl']) {
            throw new ServiceUnavailable('The payment page is not available yet.');
        }

        return ['state' => 'pending', 'paymentUrl' => $payment['invoiceUrl']];
    }

    /**
     * The active plan subscription with its record and current paid period
     * (null while the renewal of the current period is not paid).
     *
     * @return ?array{obligation: array, accepted: array, record: array, period: ?array{start: string, end: string}, now: string}
     */
    private function plan(string $tenantId): ?array
    {
        $pdo = $this->entityManager->getPDO();
        $query = $pdo->prepare(
            "SELECT * FROM credit_billing_obligation
             WHERE tenant_id = ? AND deleted = FALSE AND operation = 'subscription' AND state = 'active' ORDER BY created_at DESC"
        );
        $query->execute([$tenantId]);

        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accepted = $this->accounts->originalAcceptance($row)['terms'];

            if (!isset($accepted['seats']['quantity'])) {
                continue;
            }

            $periods = $pdo->prepare(
                'SELECT starts_at, ends_at FROM credit_billing_period
                 WHERE obligation_id = ? AND paid_payment_id IS NOT NULL AND ends_at IS NOT NULL ORDER BY starts_at DESC'
            );
            $periods->execute([$row['id']]);
            $paid = $periods->fetch(PDO::FETCH_ASSOC);
            $now = $this->clock->now();

            return [
                'obligation' => $row,
                'accepted' => $accepted,
                'record' => PlanTerms::record($row['subscription_record']),
                'period' => $paid && $paid['starts_at'] <= $now && $now < $paid['ends_at']
                    ? ['start' => (string) $paid['starts_at'], 'end' => (string) $paid['ends_at']] : null,
                'now' => $now,
            ];
        }

        return null;
    }

    private function level(array $plan): array
    {
        return PlanTerms::level($plan['accepted'], $plan['record'], $plan['period']['start'], $plan['period']['end']);
    }

    /** The newest queued change, if any. */
    private static function queued(array $record): ?array
    {
        foreach (array_reverse($record['changes'] ?? []) as $change) {
            if (in_array($change['state'], self::QUEUED, true)) {
                return $change;
            }
        }

        return null;
    }

    /** The smallest monthly credits a change may pick: the upgrade minimum, or what the plan renews with when that is less. */
    private static function minCredits(array $next): int
    {
        return min(PlanOffers::UPGRADE_MIN_CREDITS, self::whole(PlanTerms::baseCredits($next)));
    }

    private static function whole(string $credits): int
    {
        return (int) round((float) $credits);
    }

    private static function seconds(string $from, string $to): int
    {
        return max(0, strtotime($to . ' UTC') - strtotime($from . ' UTC'));
    }

    private static function key(string $purpose): string
    {
        return "self-service/{$purpose}/" . bin2hex(random_bytes(8));
    }
}
