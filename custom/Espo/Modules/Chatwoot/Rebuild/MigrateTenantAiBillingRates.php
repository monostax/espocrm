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

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * One-shot: copy legacy Tenant flat AI Billing fields into an open-ended
 * TenantAiBillingRate period (effectiveFrom = 2000-01-01) when the tenant
 * has no rate history yet. Safe to re-run.
 *
 * @noinspection PhpUnused
 */
class MigrateTenantAiBillingRates implements RebuildAction
{
    private const RATE_ENTITY = 'TenantAiBillingRate';
    private const TENANT_ENTITY = 'Tenant';
    private const LEGACY_FROM = '2000-01-01';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        if (!$this->entityManager->hasRepository(self::RATE_ENTITY)) {
            $this->log->info('MigrateTenantAiBillingRates: rate entity not ready; skip');

            return;
        }

        $tenants = $this->entityManager
            ->getRDBRepository(self::TENANT_ENTITY)
            ->select([
                'id',
                'name',
                'aiBillingCurrency',
                'aiBillingPackUnitPrice',
                'aiBillingPackSize',
                'aiBillingExtraBasePrice',
                'aiBillingExtraUnitPrice',
                'aiBillingExtraIncludedTurns',
                'aiBillingPlanIncludedUsage',
                'aiBillingCreditUnitPrice',
                'aiBillingPlanIncludedCredits',
            ])
            ->find();

        $created = 0;
        $skipped = 0;

        foreach ($tenants as $tenant) {
            $tenantId = (string) $tenant->getId();

            $existing = $this->entityManager
                ->getRDBRepository(self::RATE_ENTITY)
                ->where(['tenantId' => $tenantId])
                ->findOne();

            if ($existing !== null) {
                $skipped++;

                continue;
            }

            if (!$this->hasAnyRate($tenant)) {
                $skipped++;

                continue;
            }

            $rate = $this->entityManager->getNewEntity(self::RATE_ENTITY);
            $rate->set([
                'name' => self::LEGACY_FROM . ' → ∞',
                'tenantId' => $tenantId,
                'effectiveFrom' => self::LEGACY_FROM,
                'effectiveTo' => null,
                'currency' => $tenant->get('aiBillingCurrency'),
                'packUnitPrice' => $tenant->get('aiBillingPackUnitPrice'),
                'packSize' => $tenant->get('aiBillingPackSize'),
                'extraBasePrice' => $tenant->get('aiBillingExtraBasePrice'),
                'extraUnitPrice' => $tenant->get('aiBillingExtraUnitPrice'),
                'extraIncludedTurns' => $tenant->get('aiBillingExtraIncludedTurns'),
                'planIncludedUsage' => $tenant->get('aiBillingPlanIncludedUsage'),
                'creditUnitPrice' => $tenant->get('aiBillingCreditUnitPrice'),
                'planIncludedCredits' => $tenant->get('aiBillingPlanIncludedCredits'),
            ]);

            // silent: avoid expire/mirror side-effects during bulk migrate
            $this->entityManager->saveEntity($rate, ['silent' => true]);
            $created++;
        }

        $this->log->info(
            "MigrateTenantAiBillingRates: created={$created} skipped={$skipped}"
        );
    }

    private function hasAnyRate(\Espo\ORM\Entity $tenant): bool
    {
        foreach ([
            'aiBillingCurrency',
            'aiBillingPackUnitPrice',
            'aiBillingPackSize',
            'aiBillingExtraBasePrice',
            'aiBillingExtraUnitPrice',
            'aiBillingExtraIncludedTurns',
            'aiBillingPlanIncludedUsage',
            'aiBillingCreditUnitPrice',
            'aiBillingPlanIncludedCredits',
        ] as $field) {
            $v = $tenant->get($field);

            if ($v !== null && $v !== '') {
                return true;
            }
        }

        return false;
    }
}
