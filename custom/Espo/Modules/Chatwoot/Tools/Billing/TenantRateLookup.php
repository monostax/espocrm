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

namespace Espo\Modules\Chatwoot\Tools\Billing;

use Espo\Core\Currency\ConfigDataProvider;
use Espo\ORM\EntityManager;

/**
 * Batch-loads per-Tenant AI billing rate cards with effective-date history.
 *
 * Source of truth: {@see TenantAiBillingRate} periods (`effectiveFrom`/`effectiveTo`).
 * Legacy {@see Tenant} flat AI Billing fields remain a fallback when no period covers the day.
 * Unknown / no-tenant keys resolve to {@see RateCard::defaults()}.
 */
final class TenantRateLookup
{
    private const TENANT_ENTITY = 'Tenant';
    private const RATE_ENTITY = 'TenantAiBillingRate';

    public function __construct(
        private EntityManager $entityManager,
        private ConfigDataProvider $currencyConfig,
    ) {}

    /**
     * @param list<string|null> $tenantIds
     */
    public function forTenants(array $tenantIds): TenantRateBook
    {
        $ids = [];

        foreach ($tenantIds as $id) {
            if ($id === null || $id === '' || $id === ConversationDayGrainFetcher::noTenantKey()) {
                continue;
            }

            $ids[$id] = true;
        }

        $ids = array_keys($ids);
        $fallbackCurrency = $this->fallbackCurrency();

        if ($ids === []) {
            return new TenantRateBook([], [], $fallbackCurrency);
        }

        return new TenantRateBook(
            $this->loadPeriods($ids, $fallbackCurrency),
            $this->loadLegacy($ids, $fallbackCurrency),
            $fallbackCurrency,
        );
    }

    /**
     * @deprecated Prefer {@see forTenants()} + {@see TenantRateBook::get()}. Kept for call-site continuity.
     * @param TenantRateBook|array<string, RateCard> $map
     */
    public function get(TenantRateBook|array $map, ?string $tenantId, ?string $dayBucket = null): RateCard
    {
        if ($map instanceof TenantRateBook) {
            return $map->get($tenantId, $dayBucket);
        }

        // Legacy array map (unit tests / old call sites).
        if (
            $tenantId === null
            || $tenantId === ''
            || $tenantId === ConversationDayGrainFetcher::noTenantKey()
        ) {
            return RateCard::defaults($this->fallbackCurrency());
        }

        return $map[$tenantId] ?? RateCard::defaults($this->fallbackCurrency());
    }

    /**
     * @param list<string> $ids
     * @return array<string, list<array{from: string, to: ?string, card: RateCard}>>
     */
    private function loadPeriods(array $ids, string $fallbackCurrency): array
    {
        if (!$this->entityManager->hasRepository(self::RATE_ENTITY)) {
            return [];
        }

        $rows = $this->entityManager
            ->getRDBRepository(self::RATE_ENTITY)
            ->where(['tenantId' => $ids])
            ->select([
                'id',
                'tenantId',
                'effectiveFrom',
                'effectiveTo',
                'currency',
                'packUnitPrice',
                'packSize',
                'extraBasePrice',
                'extraUnitPrice',
                'extraIncludedTurns',
                'planIncludedUsage',
            ])
            ->order('effectiveFrom', 'DESC')
            ->find();

        $out = [];

        foreach ($rows as $row) {
            $tenantId = (string) $row->get('tenantId');
            $from = $this->asDate($row->get('effectiveFrom'));

            if ($tenantId === '' || $from === null) {
                continue;
            }

            $currencyRaw = $row->get('currency');

            $out[$tenantId][] = [
                'from' => $from,
                'to' => $this->asDate($row->get('effectiveTo')),
                'card' => RateCard::fromNullable(
                    $this->asFloat($row->get('packUnitPrice')),
                    $this->asFloat($row->get('extraBasePrice')),
                    $this->asFloat($row->get('extraUnitPrice')),
                    $this->asInt($row->get('packSize')),
                    $this->asInt($row->get('extraIncludedTurns')),
                    is_string($currencyRaw) ? $currencyRaw : null,
                    $fallbackCurrency,
                    $this->asInt($row->get('planIncludedUsage')),
                ),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     * @return array<string, RateCard>
     */
    private function loadLegacy(array $ids, string $fallbackCurrency): array
    {
        $rows = $this->entityManager
            ->getRDBRepository(self::TENANT_ENTITY)
            ->where(['id' => $ids])
            ->select([
                'id',
                'aiBillingPackUnitPrice',
                'aiBillingExtraBasePrice',
                'aiBillingExtraUnitPrice',
                'aiBillingPackSize',
                'aiBillingExtraIncludedTurns',
                'aiBillingPlanIncludedUsage',
                'aiBillingCurrency',
            ])
            ->find();

        $out = [];

        foreach ($rows as $row) {
            $id = (string) $row->getId();
            $currencyRaw = $row->get('aiBillingCurrency');

            $out[$id] = RateCard::fromNullable(
                $this->asFloat($row->get('aiBillingPackUnitPrice')),
                $this->asFloat($row->get('aiBillingExtraBasePrice')),
                $this->asFloat($row->get('aiBillingExtraUnitPrice')),
                $this->asInt($row->get('aiBillingPackSize')),
                $this->asInt($row->get('aiBillingExtraIncludedTurns')),
                is_string($currencyRaw) ? $currencyRaw : null,
                $fallbackCurrency,
                $this->asInt($row->get('aiBillingPlanIncludedUsage')),
            );
        }

        return $out;
    }

    private function fallbackCurrency(): string
    {
        $code = strtoupper(trim((string) $this->currencyConfig->getDefaultCurrency()));

        if ($code === '' || strlen($code) !== 3) {
            return RateCard::DEFAULT_CURRENCY;
        }

        return $code;
    }

    private function asFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function asInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function asDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $raw = substr((string) $value, 0, 10);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        return $raw;
    }
}
