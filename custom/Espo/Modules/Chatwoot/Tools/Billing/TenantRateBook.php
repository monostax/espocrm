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

/**
 * In-memory rate book for one report run: dated periods + legacy Tenant flat fields.
 *
 * Periods are preferred when a conversation-day falls inside [effectiveFrom, effectiveTo]
 * (effectiveTo null = open-ended). Otherwise fall back to legacy Tenant AI Billing fields,
 * then platform defaults.
 */
final class TenantRateBook
{
    /**
     * @param array<string, list<array{from: string, to: ?string, card: RateCard}>> $periodsByTenant
     *        periods per tenant, sorted by effectiveFrom DESC
     * @param array<string, RateCard> $legacyByTenant current Tenant flat-field rate cards
     */
    public function __construct(
        private array $periodsByTenant,
        private array $legacyByTenant,
        private string $fallbackCurrency,
    ) {}

    public function get(?string $tenantId, ?string $dayBucket = null): RateCard
    {
        if (
            $tenantId === null
            || $tenantId === ''
            || $tenantId === ConversationDayGrainFetcher::noTenantKey()
        ) {
            return RateCard::defaults($this->fallbackCurrency);
        }

        $periods = $this->periodsByTenant[$tenantId] ?? [];

        if ($periods !== []) {
            $day = $this->normalizeDay($dayBucket);

            if ($day !== null) {
                foreach ($periods as $period) {
                    if ($period['from'] > $day) {
                        continue;
                    }

                    if ($period['to'] !== null && $period['to'] < $day) {
                        continue;
                    }

                    return $period['card'];
                }
            } else {
                foreach ($periods as $period) {
                    if ($period['to'] === null) {
                        return $period['card'];
                    }
                }

                return $periods[0]['card'];
            }
        }

        return $this->legacyByTenant[$tenantId] ?? RateCard::defaults($this->fallbackCurrency);
    }

    private function normalizeDay(?string $dayBucket): ?string
    {
        if ($dayBucket === null || $dayBucket === '' || $dayBucket === '-') {
            return null;
        }

        // DAY:runAt may come as Y-m-d or Y-m-d H:i:s — take date part.
        $day = substr($dayBucket, 0, 10);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return null;
        }

        return $day;
    }
}
