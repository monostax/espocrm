<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\{Conflict, NotFound, ServiceUnavailable};
use Espo\Modules\FeatureCredits\Accounting\{GrantInput, RecordId};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Connections, Transport};
use PDO;

/**
 * Original-provider operations. Cancellation stops recurrence, never refunds paid credits.
 *
 * Plan changes (PlanTerms) are queued here and applied in order: Asaas gets the
 * new price on the same subscription (so the payer's card keeps renewing it),
 * and the change takes effect from the next unpaid period. A change with a
 * one-off difference to pay waits until that purchase is completed.
 */
final class Subscriptions
{
    /** Payment states of a period that has not been paid yet; their verified identity is redone at the new price. */
    private const UNPAID = ['unverified', 'pending', 'review_required', 'superseded'];

    public function __construct(private Accounts $accounts, private CreationStore $creations,
        private Connections $connections, private Transport $transport) {}

    public function cancel(string $tenant, string $obligation, string $key, string $actor): array
    {
        GrantInput::identity($actor);
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $key)) throw new \InvalidArgumentException('Invalid cancellation key.');
        return $this->run($tenant, $obligation, ['requestKey' => $key, 'actorId' => $actor]);
    }

    public function reconcile(string $tenant, string $obligation): array { return $this->run($tenant, $obligation, null); }

    private function run(string $tenant, string $obligation, ?array $cancel): array
    {
        GrantInput::identity($obligation);
        $claim = $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $obligation, $cancel): array {
            [$parent, $creation, $command, $record] = $this->original($pdo, $tenant, $obligation);
            $dispatch = false;
            if ($cancel !== null && $record['cancellation'] !== null &&
                array_intersect_key($record['cancellation'], $cancel) !== $cancel) throw new Conflict('Subscription cancellation already has an original command.');
            if ($parent['state'] === 'cancelled') return ['receipt' => $this->receipt($parent, $record)];
            // Resolve credentials before claiming. Lost replies and process death only permit GET recovery.
            $connection = $this->connections->get($creation['integration_id']);
            if ($connection->environment !== $creation['environment']) throw new Conflict('Original subscription environment changed.');
            if ($cancel !== null && $record['cancellation'] === null) {
                $record['cancellation'] = $cancel + ['requestedAt' => $now]; $dispatch = true;
            }
            $record['revision']++;
            $this->save($pdo, $parent, $record);
            return compact('parent', 'creation', 'command', 'record', 'connection', 'dispatch');
        });
        if (isset($claim['receipt'])) return $claim['receipt'];
        $creation = $claim['creation'];
        $method = $claim['dispatch'] ? 'DELETE' : 'GET';
        $response = $this->transport->send($claim['connection'], $method, '/subscriptions/' . $creation['external_id']);
        if ($response->status !== 200 || !is_array($response->data)) throw new ServiceUnavailable('Subscription operation is unresolved; reconcile the original subscription.');
        $data = $response->data;
        if (($data['id'] ?? null) !== $creation['external_id'] || !is_bool($data['deleted'] ?? null)) throw new Conflict('Subscription response has a different identity.');
        if ($method === 'DELETE') {
            if ($data['deleted'] !== true) throw new Conflict('Subscription removal is unconfirmed.');
            $status = 'DELETED';
        } else {
            $body = $claim['command']->body;
            if (($data['customer'] ?? null) !== $body['customer'] || ($data['externalReference'] ?? null) !== $body['externalReference'] ||
                ($data['cycle'] ?? null) !== 'MONTHLY' || !is_string($data['value'] ?? null) ||
                !preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $data['value']) ||
                !in_array(true, array_map(static fn (string $value) => BigDecimal::of($data['value'])->compareTo($value) === 0,
                    self::prices($claim['record'], $body['value'])), true)) throw new Conflict('Subscription terms differ from the original accepted terms.');
            $status = $data['deleted'] ? 'DELETED' : ($data['status'] ?? null);
            if (!in_array($status, ['ACTIVE', 'INACTIVE', 'EXPIRED', 'DELETED'], true)) throw new Conflict('Unknown subscription status.');
        }
        return $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $obligation, $claim, $status, $method): array {
            [$parent, $creation, , $record] = $this->original($pdo, $tenant, $obligation);
            // Only the latest read can change the projection. A verified removal is permanent.
            $applied = $record['revision'] === $claim['record']['revision'] && $parent['state'] !== 'cancelled';
            $this->accounts->subscriptionObservationLocked($pdo, $tenant, $obligation, [
                'revision' => $claim['record']['revision'], 'bindingHash' => $creation['result_hash'],
                'cancellation' => $claim['record']['cancellation'], 'status' => $status, 'method' => $method, 'applied' => $applied,
            ], $now);
            if (!$applied) return $this->receipt($parent, $record);
            $record['observation'] = ['status' => $status, 'method' => $method, 'observedAt' => $now];
            if ($status === 'DELETED') {
                $this->accounts->subscriptionRemovedLocked($pdo, $tenant, $obligation, $now);
                $parent['state'] = 'cancelled';
            }
            // INACTIVE/EXPIRED can retain unpaid charges; they do not unpin provider routing.
            $this->save($pdo, $parent, $record);
            return $this->receipt($parent, $record);
        });
    }

    /**
     * Queues a change of an active plan subscription to $terms (the same plan with
     * other seats or monthly credits). With $funding, it waits until that one-off
     * purchase (the difference) is completed. Queued changes not dispatched yet
     * are replaced; a replay of the same key returns the original change.
     */
    public function requestChange(string $tenant, string $obligation, string $key, string $actor, OfferTerms $terms,
        string $description, ?string $funding): array
    {
        GrantInput::identity($actor); GrantInput::identity($obligation);
        if ($funding !== null) GrantInput::identity($funding);
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $key)) throw new \InvalidArgumentException('Invalid change key.');
        if (trim($description) !== $description || $description === '' || mb_strlen($description) > 300 ||
            preg_match('/[\x00-\x1f\x7f]/', $description)) throw new \InvalidArgumentException('A bounded single-line description is required.');
        return $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $obligation, $key, $actor, $terms, $description, $funding): array {
            [$parent, , , $record] = $this->original($pdo, $tenant, $obligation);
            foreach ($record['changes'] ?? [] as $change) {
                if ($change['requestKey'] !== $key) continue;
                if ($change['terms']['termsId'] !== $terms->termsId || $change['fundingObligationId'] !== $funding) {
                    throw new Conflict('Plan change identity already has different terms.');
                }
                return $change;
            }
            $accepted = $this->accounts->originalAcceptance($parent)['terms'];
            $seats = $terms->seats;
            if ($parent['state'] !== 'active' || $terms->operation !== 'subscription' || $terms->interval !== 'month' ||
                !isset($seats['quantity'], $seats['baseCredits'], $accepted['seats']['plan']) || $seats['plan'] !== $accepted['seats']['plan'] ||
                $terms->currency !== $accepted['currency'] || !$terms->availableToTenant($tenant)) {
                throw new Conflict('Only an active plan subscription changes to other terms of its plan.');
            }
            if ($funding !== null) {
                $q = $pdo->prepare("SELECT operation FROM credit_billing_obligation WHERE id=? AND tenant_id=? AND deleted=FALSE");
                $q->execute([$funding, $tenant]);
                if ($q->fetchColumn() !== 'purchase') throw new Conflict('A plan change is funded by a purchase of the same workspace.');
            }
            foreach ($record['changes'] ?? [] as $i => $change) {
                if (in_array($change['state'], ['awaiting_payment', 'ready'], true)) $record['changes'][$i]['state'] = 'cancelled';
            }
            $change = ['id' => RecordId::generate(), 'requestKey' => $key, 'actorId' => $actor, 'requestedAt' => $now,
                'terms' => $terms->jsonSerialize(), 'description' => $description, 'fundingObligationId' => $funding,
                'state' => $funding === null ? 'ready' : 'awaiting_payment', 'effectiveFrom' => null, 'appliedAt' => null];
            $record['changes'][] = $change;
            $record['revision']++;
            $this->save($pdo, $parent, $record);
            return $change;
        });
    }

    /**
     * Applies queued changes in order: each once its difference is paid, from
     * the period after the paid one. Re-sending a dispatched change is safe
     * (Asaas takes the absolute price). Returns the subscription record's changes.
     *
     * @throws ServiceUnavailable Asaas did not confirm the new price; the change stays dispatched
     */
    public function applyChanges(string $tenant, string $obligation): array
    {
        GrantInput::identity($obligation);
        while (true) {
            $claim = $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $obligation): array {
                [$parent, $creation, , $record] = $this->original($pdo, $tenant, $obligation);
                $next = null; $dirty = false;
                foreach ($record['changes'] ?? [] as $i => $change) {
                    if ($change['state'] === 'awaiting_payment') {
                        $q = $pdo->prepare('SELECT state FROM credit_billing_obligation WHERE id=? AND tenant_id=?');
                        $q->execute([$change['fundingObligationId'], $tenant]); $funding = $q->fetchColumn();
                        if (in_array($funding, ['cancelled', 'failed'], true)) { $record['changes'][$i]['state'] = 'cancelled'; $dirty = true; }
                        if ($funding !== 'completed') continue;
                        $record['changes'][$i]['state'] = 'ready'; $dirty = true;
                    }
                    if (in_array($record['changes'][$i]['state'], ['ready', 'dispatched'], true)) { $next = $i; break; }
                }
                $from = $next === null ? null : $record['changes'][$next]['effectiveFrom'];
                // Not yet confirmed when its first period began: it starts with the next unpaid one instead.
                if ($next !== null && ($from === null || $from <= $now)) $from = $this->nextUnpaidPeriod($pdo, $tenant, $obligation, $now);
                // Nothing to apply, or the current period is not paid yet (its renewal is on its way): apply once it is.
                if ($from === null || $parent['state'] !== 'active') {
                    if ($dirty) { $record['revision']++; $this->save($pdo, $parent, $record); }
                    return ['changes' => $record['changes'] ?? []];
                }
                $record['changes'][$next]['effectiveFrom'] = $from;
                $record['changes'][$next]['state'] = 'dispatched';
                $connection = $this->connections->get($creation['integration_id']);
                if ($connection->environment !== $creation['environment']) throw new Conflict('Original subscription environment changed.');
                $record['revision']++;
                $dispatched = $record['changes'][$next];
                $this->save($pdo, $parent, $record);
                return ['change' => $dispatched, 'creation' => $creation, 'connection' => $connection, 'revision' => $record['revision']];
            });
            if (!isset($claim['change'])) return $claim['changes'];
            $change = $claim['change']; $creation = $claim['creation'];
            if (!preg_match('/^[0-9]{1,10}\.[0-9]{2}$/D', $change['terms']['price'])) throw new Conflict('Exact plan change price required.');
            $json = '{"value":' . $change['terms']['price'] . ',"description":' . json_encode($change['description'], JSON_THROW_ON_ERROR) .
                ',"updatePendingPayments":true}';
            $response = $this->transport->send($claim['connection'], 'POST', '/subscriptions/' . $creation['external_id'], $json);
            $data = $response->data;
            if ($response->status !== 200 || !is_array($data) || ($data['id'] ?? null) !== $creation['external_id'] ||
                !is_string($data['value'] ?? null) || !preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $data['value']) ||
                BigDecimal::of($data['value'])->compareTo($change['terms']['price']) !== 0) {
                throw new ServiceUnavailable('The new subscription price is unconfirmed; the change is applied on the next attempt.');
            }
            $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $obligation, $change, $claim): void {
                [$parent, $creation, , $record] = $this->original($pdo, $tenant, $obligation);
                foreach ($record['changes'] as $i => $saved) {
                    if ($saved['id'] !== $change['id'] || $saved['state'] !== 'dispatched') continue;
                    $record['changes'][$i]['state'] = 'applied';
                    $record['changes'][$i]['appliedAt'] = $now;
                    $record['revision']++;
                    $this->rebindPeriod($pdo, $parent, $change);
                    $this->accounts->subscriptionObservationLocked($pdo, $tenant, $obligation, [
                        'revision' => $claim['revision'], 'bindingHash' => $creation['result_hash'], 'cancellation' => null,
                        'status' => 'CHANGED', 'method' => 'POST', 'applied' => true,
                        'change' => ['id' => $change['id'], 'termsId' => $change['terms']['termsId'], 'price' => $change['terms']['price'],
                            'effectiveFrom' => $change['effectiveFrom'], 'actorId' => $change['actorId']],
                    ], $now);
                    $this->save($pdo, $parent, $record);
                }
            });
        }
    }

    /** Start of the period after the newest paid one, while that paid period is the current one. */
    private function nextUnpaidPeriod(PDO $pdo, string $tenant, string $obligation, string $now): ?string
    {
        $q = $pdo->prepare("SELECT 1 FROM credit_billing_payment WHERE obligation_id=? AND tenant_id=? AND state='awaiting_period'");
        $q->execute([$obligation, $tenant]);
        if ($q->fetchColumn()) return null;
        $q = $pdo->prepare('SELECT starts_at, ends_at FROM credit_billing_period
            WHERE obligation_id=? AND tenant_id=? AND paid_payment_id IS NOT NULL AND ends_at IS NOT NULL ORDER BY starts_at DESC');
        $q->execute([$obligation, $tenant]); $paid = $q->fetch(PDO::FETCH_ASSOC);
        return $paid && $paid['starts_at'] <= $now && $now < $paid['ends_at'] ? (string) $paid['ends_at'] : null;
    }

    /**
     * An unpaid period already seen for the change's first period (Asaas creates
     * the renewal invoice ahead of time) moves to the new terms, and its
     * payments are verified again at the new price.
     */
    private function rebindPeriod(PDO $pdo, array $parent, array $change): void
    {
        $q = $pdo->prepare('SELECT * FROM credit_billing_period WHERE obligation_id=? AND period_key=? AND tenant_id=? FOR UPDATE');
        $q->execute([$parent['id'], $change['effectiveFrom'], $parent['tenant_id']]); $period = $q->fetch(PDO::FETCH_ASSOC);
        if (!$period) return;
        if ($period['deleted'] || $period['paid_payment_id'] !== null || $period['receipt'] !== null) {
            throw new Conflict('The first period of a plan change is already paid.');
        }
        $binding = ['tenant_id' => $period['tenant_id'], 'obligation_id' => $period['obligation_id'], 'period_key' => $period['period_key'],
            'starts_at' => $period['starts_at'], 'ends_at' => $period['ends_at'], 'terms_id' => $change['terms']['termsId']];
        $pdo->prepare('UPDATE credit_billing_period SET terms_id=?, binding_hash=? WHERE id=?')
            ->execute([$binding['terms_id'], Payments::hash($binding), $period['id']]);
        $states = implode(',', array_fill(0, count(self::UNPAID), '?'));
        $pdo->prepare("UPDATE credit_billing_payment SET identity_hash=NULL WHERE period_id=? AND tenant_id=? AND state IN ($states)")
            ->execute([$period['id'], $parent['tenant_id'], ...self::UNPAID]);
    }

    /** Prices Asaas may be charging: the newest applied change's (else the original), and any being dispatched. */
    private static function prices(array $record, string $original): array
    {
        $prices = [$original];
        foreach ($record['changes'] ?? [] as $change) {
            if ($change['state'] === 'applied') $prices = [$change['terms']['price']];
        }
        foreach ($record['changes'] ?? [] as $change) {
            if ($change['state'] === 'dispatched') $prices[] = $change['terms']['price'];
        }
        return $prices;
    }

    private function original(PDO $pdo, string $tenant, string $obligation): array
    {
        $q = $pdo->prepare('SELECT * FROM credit_billing_obligation WHERE tenant_id=? AND id=?'); $q->execute([$tenant, $obligation]);
        $parent = $q->fetch(PDO::FETCH_ASSOC);
        if (!$parent || $parent['deleted']) throw new NotFound('Subscription is unavailable.');
        $accepted = $this->accounts->originalAcceptance($parent);
        if ($accepted['provider'] !== 'asaas' || $accepted['terms']['operation'] !== 'subscription' ||
            !in_array($parent['state'], ['pending', 'active', 'cancelled'], true)) throw new Conflict('Subscription has no bound lifecycle.');
        $q = $pdo->prepare("SELECT * FROM credit_billing_creation WHERE tenant_id=? AND obligation_id=? AND kind='subscription'");
        $q->execute([$tenant, $obligation]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) throw new Conflict('Subscription creation is unavailable.');
        $creation = $rows[0]; $command = $this->creations->verify($creation);
        if ($creation['state'] !== 'bound' || $creation['integration_id'] !== $accepted['integrationId'] ||
            $creation['provider'] !== $accepted['provider']) throw new Conflict('Original subscription binding mismatch.');
        return [$parent, $creation, $command, self::record($parent, $creation)];
    }

    /** Caller has already validated the original acceptance and creation binding. */
    public static function projection(array $parent, array $creation): array { return self::receipt($parent, self::record($parent, $creation)); }

    /** The verified subscription record, bound to the original creation. */
    public static function record(array $parent, array $creation): array
    {
        $record = ['revision' => 0, 'cancellation' => null, 'observation' => null, 'bindingHash' => $creation['result_hash']];
        if ($parent['subscription_record'] !== null) {
            try { $saved = json_decode($parent['subscription_record'], true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new Conflict('Invalid subscription lifecycle evidence.'); }
            if (!is_array($saved)) throw new Conflict('Invalid subscription lifecycle evidence.');
            $hash = $saved['hash'] ?? null; unset($saved['hash']);
            if ($hash !== Payments::hash($saved) || ($saved['bindingHash'] ?? null) !== $creation['result_hash'] ||
                !is_int($saved['revision'] ?? null) || $saved['revision'] < 1) throw new Conflict('Invalid subscription lifecycle evidence.');
            $record = $saved;
        }
        return $record;
    }

    private function save(PDO $pdo, array $parent, array $record): void
    {
        $record['hash'] = Payments::hash($record);
        $pdo->prepare('UPDATE credit_billing_obligation SET subscription_record=? WHERE id=? AND tenant_id=?')
            ->execute([json_encode($record, JSON_THROW_ON_ERROR), $parent['id'], $parent['tenant_id']]);
    }

    private static function receipt(array $parent, array $record): array
    {
        return ['tenantId' => $parent['tenant_id'], 'obligationId' => $parent['id'], 'state' => $parent['state'],
            'cancellationPending' => $record['cancellation'] !== null && $parent['state'] !== 'cancelled',
            'providerStatus' => $record['observation']['status'] ?? null, 'observedAt' => $record['observation']['observedAt'] ?? null];
    }
}
