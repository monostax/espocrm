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

namespace Espo\Modules\Chatwoot\Hooks\TenantAiBillingRate;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Keeps Tenant flat AI Billing fields as a live snapshot of the open-ended
 * (current) rate period so list/detail readouts stay useful without editing them.
 */
class MirrorCurrentOntoTenant
{
    public static int $order = 20;

    private const RATE_ENTITY = 'TenantAiBillingRate';
    private const TENANT_ENTITY = 'Tenant';

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        $tenantId = (string) ($entity->get('tenantId') ?? '');

        if ($tenantId === '') {
            return;
        }

        $this->mirror($tenantId);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function afterRemove(Entity $entity, array $options): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        $tenantId = (string) ($entity->get('tenantId') ?? '');

        if ($tenantId === '') {
            return;
        }

        $this->mirror($tenantId);
    }

    private function mirror(string $tenantId): void
    {
        $tenant = $this->entityManager->getEntityById(self::TENANT_ENTITY, $tenantId);

        if ($tenant === null) {
            return;
        }

        $current = $this->entityManager
            ->getRDBRepository(self::RATE_ENTITY)
            ->where([
                'tenantId' => $tenantId,
                'effectiveTo' => null,
            ])
            ->order('effectiveFrom', 'DESC')
            ->findOne();

        if ($current === null) {
            $current = $this->entityManager
                ->getRDBRepository(self::RATE_ENTITY)
                ->where(['tenantId' => $tenantId])
                ->order('effectiveFrom', 'DESC')
                ->findOne();
        }

        if ($current === null) {
            return;
        }

        $tenant->set([
            'aiBillingCurrency' => $current->get('currency'),
            'aiBillingPackUnitPrice' => $current->get('packUnitPrice'),
            'aiBillingPackSize' => $current->get('packSize'),
            'aiBillingExtraBasePrice' => $current->get('extraBasePrice'),
            'aiBillingExtraUnitPrice' => $current->get('extraUnitPrice'),
            'aiBillingExtraIncludedTurns' => $current->get('extraIncludedTurns'),
            'aiBillingPlanIncludedUsage' => $current->get('planIncludedUsage'),
            'aiBillingCreditUnitPrice' => $current->get('creditUnitPrice'),
            'aiBillingPlanIncludedCredits' => $current->get('planIncludedCredits'),
        ]);

        $this->entityManager->saveEntity($tenant, ['silent' => true]);
    }
}
