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

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Billing\PlanTerms;
use Espo\Modules\FeatureCredits\Billing\Subscriptions;
use Espo\ORM\EntityManager;
use PDO;

/**
 * The workspace's plan subscription for Settings › Planos & Assinatura: what
 * it pays for and until when, and cancelling it. A cancelled plan keeps its
 * access until the end of the period already paid; billing then freezes the
 * workspace after the usual grace (WorkspaceAccess reads paid periods, not the
 * subscription's state). Attendants and credits change in PlanChange: the
 * plan shows what the workspace has in its paid period, and what renews when
 * a change waits for the renewal (`next`).
 */
class PlanSubscription
{
    public function __construct(
        private EntityManager $entityManager,
        private Subscriptions $subscriptions,
    ) {}

    /**
     * @return array{status: ?string, plan: ?string, paidThrough: ?string, trialEndsAt: ?string,
     *     subscription: ?array{state: string, seats: int, seatPrice: string, monthlyCredits: int,
     *         includedCredits: int, totalCredits: int, price: string, currency: string, paidThrough: ?string,
     *         next: ?array{seats: int, seatPrice: string, monthlyCredits: int, includedCredits: int, totalCredits: int,
     *             price: string, from: ?string},
     *         change: ?array{state: string, seats: int, monthlyCredits: int}}}
     */
    public function read(string $tenantId): array
    {
        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
        $current = $this->current($tenantId);
        $subscription = null;

        if ($current !== null) {
            ['row' => $row, 'terms' => $terms] = $current;
            $record = PlanTerms::record($row['subscription_record']);
            $next = PlanTerms::latest($terms, $record);
            $level = $row['paid_from'] !== null
                ? PlanTerms::level($terms, $record, (string) $row['paid_from'], (string) $row['paid_through'])
                : PlanTerms::level($terms, $record, (string) $row['created_at'], (string) $row['created_at']);
            $nextLevel = self::flat($next);
            $change = null;

            foreach (array_reverse($record['changes'] ?? []) as $queued) {
                if (in_array($queued['state'], ['awaiting_payment', 'ready', 'dispatched'], true)) {
                    $change = ['state' => $queued['state'] === 'awaiting_payment' ? 'awaiting_payment' : 'applying',
                        'seats' => $queued['terms']['seats']['quantity'],
                        'monthlyCredits' => (int) round((float) PlanTerms::baseCredits($queued['terms']))];
                    break;
                }
            }

            $subscription = ['state' => (string) $row['state']] + self::view($level) + [
                'currency' => (string) $terms['currency'],
                'paidThrough' => $row['paid_through'] !== null ? (string) $row['paid_through'] : null,
                'next' => $nextLevel['seats'] === $level['seats'] && $nextLevel['baseCredits'] === $level['baseCredits'] ? null
                    : self::view($nextLevel) + ['from' => $row['paid_through'] !== null ? (string) $row['paid_through'] : null],
                'change' => $change,
            ];
        }

        return [
            'status' => $tenant?->get('subscriptionStatus'),
            'plan' => $tenant?->get('plan') ?: null,
            'paidThrough' => $tenant?->get('paidThrough'),
            'trialEndsAt' => $tenant?->get('trialEndsAt'),
            'subscription' => $subscription,
        ];
    }

    /**
     * Cancels the active plan subscription with the payment provider. Nothing
     * more is charged; access stays until the paid period ends.
     *
     * @throws Conflict NO_ACTIVE_SUBSCRIPTION
     */
    public function cancel(string $tenantId, string $actorId): array
    {
        $current = $this->current($tenantId);

        if ($current === null || $current['row']['state'] !== 'active') {
            throw new Conflict('NO_ACTIVE_SUBSCRIPTION');
        }

        $this->subscriptions->cancel($tenantId, (string) $current['row']['id'],
            'self-service/cancel/' . bin2hex(random_bytes(8)), $actorId);

        return $this->read($tenantId);
    }

    /** The seats and credits of terms, as PlanTerms::level returns them. */
    private static function flat(array $terms): array
    {
        $included = $terms['seats']['includedCredits'] ?? '0.0000';

        return ['seats' => (int) $terms['seats']['quantity'], 'baseCredits' => PlanTerms::baseCredits($terms),
            'unitPrice' => (string) $terms['seats']['unitPrice'], 'includedCredits' => $included,
            'credits' => (string) $terms['credits'], 'price' => (string) $terms['price']];
    }

    private static function view(array $level): array
    {
        return [
            'seats' => (int) $level['seats'],
            'seatPrice' => (string) $level['unitPrice'],
            'monthlyCredits' => (int) round((float) $level['baseCredits']),
            'includedCredits' => (int) round((float) $level['includedCredits'] * (int) $level['seats']),
            'totalCredits' => (int) round((float) $level['credits']),
            'price' => (string) $level['price'],
        ];
    }

    /** The plan was paid for and then cancelled: access runs out with the paid period. */
    public function isCancelled(string $tenantId): bool
    {
        $current = $this->current($tenantId);

        return $current !== null && $current['row']['state'] === 'cancelled' && $current['row']['paid_through'] !== null;
    }

    /**
     * The subscription that describes the plan: the active one, else the latest
     * one that was paid, else the latest awaiting its first payment.
     *
     * @return ?array{row: array<string, mixed>, terms: array<string, mixed>}
     */
    private function current(string $tenantId): ?array
    {
        $query = $this->entityManager->getPDO()->prepare(
            "SELECT o.id, o.state, o.terms_snapshot, o.subscription_record, o.created_at,
                    MAX(p.starts_at) AS paid_from, MAX(p.ends_at) AS paid_through
             FROM credit_billing_obligation o
             LEFT JOIN credit_billing_period p ON p.obligation_id = o.id AND p.paid_payment_id IS NOT NULL AND p.ends_at IS NOT NULL
             WHERE o.tenant_id = ? AND o.deleted = FALSE AND o.operation = 'subscription'
               AND o.state IN ('pending', 'active', 'cancelled')
             GROUP BY o.id, o.state, o.terms_snapshot, o.subscription_record, o.created_at"
        );
        $query->execute([$tenantId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);

        usort($rows, static fn (array $a, array $b): int =>
            [(int) ($b['state'] === 'active'), (int) ($b['paid_through'] !== null), (string) $b['created_at']]
            <=> [(int) ($a['state'] === 'active'), (int) ($a['paid_through'] !== null), (string) $a['created_at']]);

        foreach ($rows as $row) {
            if ($row['state'] === 'cancelled' && $row['paid_through'] === null) {
                // Replaced or abandoned before paying: not a plan the workspace had.
                continue;
            }

            $terms = json_decode((string) $row['terms_snapshot'], true);

            if (is_array($terms) && isset($terms['seats']['quantity'], $terms['seats']['unitPrice'])) {
                return ['row' => $row, 'terms' => $terms];
            }
        }

        return null;
    }
}
