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
use Espo\Modules\FeatureCredits\Billing\Subscriptions;
use Espo\ORM\EntityManager;
use PDO;

/**
 * The workspace's plan subscription for Settings › Planos & Assinatura: what
 * it pays for and until when, and cancelling it. A cancelled plan keeps its
 * access until the end of the period already paid; billing then freezes the
 * workspace after the usual grace (WorkspaceAccess reads paid periods, not the
 * subscription's state). Changing attendants or credits on a live
 * subscription is not built yet.
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
     *         includedCredits: int, totalCredits: int, price: string, currency: string, paidThrough: ?string}}
     */
    public function read(string $tenantId): array
    {
        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
        $current = $this->current($tenantId);
        $subscription = null;

        if ($current !== null) {
            ['row' => $row, 'terms' => $terms] = $current;
            $seats = $terms['seats'];
            $subscription = [
                'state' => (string) $row['state'],
                'seats' => (int) $seats['quantity'],
                'seatPrice' => (string) $seats['unitPrice'],
                'monthlyCredits' => (int) round((float) ($seats['baseCredits'] ?? $terms['credits'])),
                'includedCredits' => isset($seats['includedCredits'])
                    ? (int) round((float) $seats['includedCredits'] * (int) $seats['quantity']) : 0,
                'totalCredits' => (int) round((float) $terms['credits']),
                'price' => (string) $terms['price'],
                'currency' => (string) $terms['currency'],
                'paidThrough' => $row['paid_through'] !== null ? (string) $row['paid_through'] : null,
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
            "SELECT o.id, o.state, o.terms_snapshot, o.created_at, MAX(p.ends_at) AS paid_through
             FROM credit_billing_obligation o
             LEFT JOIN credit_billing_period p ON p.obligation_id = o.id AND p.paid_payment_id IS NOT NULL AND p.ends_at IS NOT NULL
             WHERE o.tenant_id = ? AND o.deleted = FALSE AND o.operation = 'subscription'
               AND o.state IN ('pending', 'active', 'cancelled')
             GROUP BY o.id, o.state, o.terms_snapshot, o.created_at"
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
