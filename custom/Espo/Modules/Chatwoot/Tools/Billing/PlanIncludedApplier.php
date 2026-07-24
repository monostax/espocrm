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
 * Applies monthly plan franchise (planIncludedUsage) to per-grain billable metrics.
 *
 * Commercial match to Pricing.html:
 *   - package unit = 1 "conversa IA" (typically pack of packSize turns)
 *   - monthly included 200 / 600 / 1000 depending on plan
 *   - overage charged at unit price
 *
 * Units: pack model → packs; extra model → bases (conversation-days).
 * Free units is FIFO chronological per Tenant × calendar month within the
 * grain set (run full-month report windows for correct franchise consumption).
 */
final class PlanIncludedApplier
{
    public const COL_PLAN_INCLUDED_USED = 'planIncludedUsed';
    public const COL_BILLABLE_USAGE = 'billableUsage';

    /**
     * @param list<array{
     *     dayBucket: string,
     *     tenantId: string,
     *     rates: RateCard,
     *     metrics: array<string, int|float>
     * }> $rows
     * @param 'pack199'|'extra049' $pricingModel
     * @return list<array{dayBucket: string, tenantId: string, metrics: array<string, int|float>}>
     */
    public static function apply(array $rows, string $pricingModel): array
    {
        if ($rows === []) {
            return [];
        }

        $indexed = [];

        foreach ($rows as $i => $row) {
            $indexed[] = ['i' => $i, 'row' => $row];
        }

        usort($indexed, static function (array $a, array $b): int {
            $ta = (string) $a['row']['tenantId'];
            $tb = (string) $b['row']['tenantId'];
            $c = strcmp($ta, $tb);

            if ($c !== 0) {
                return $c;
            }

            return strcmp((string) $a['row']['dayBucket'], (string) $b['row']['dayBucket']);
        });

        /** @var array<string, int> $remaining tenant|YYYY-MM → free units left */
        $remaining = [];
        $out = [];

        foreach ($indexed as $item) {
            $row = $item['row'];
            /** @var RateCard $rates */
            $rates = $row['rates'];
            $metrics = $row['metrics'];
            $day = substr((string) $row['dayBucket'], 0, 10);
            $monthKey = (string) $row['tenantId'] . '|' . substr($day, 0, 7);

            if (!isset($remaining[$monthKey])) {
                $remaining[$monthKey] = max(0, $rates->planIncludedUsage);
            }

            $usage = self::usageUnits($metrics, $pricingModel);
            $free = min($usage, $remaining[$monthKey]);
            $remaining[$monthKey] -= $free;
            $billable = $usage - $free;

            $metrics[self::COL_PLAN_INCLUDED_USED] = $free;
            $metrics[self::COL_BILLABLE_USAGE] = $billable;

            $deal = self::billableAmountDeal($metrics, $rates, $pricingModel, $billable, $usage);
            $metrics['amountDeal'] = $deal;
            // amount (system currency) left for caller to overwrite via FX; keep deal key stable
            $metrics['__dealRaw'] = $deal;
            $metrics['__currency'] = $rates->currency;

            $out[$item['i']] = [
                'dayBucket' => $row['dayBucket'],
                'tenantId' => $row['tenantId'],
                'metrics' => $metrics,
            ];
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * @param array<string, int|float> $metrics
     */
    private static function usageUnits(array $metrics, string $pricingModel): int
    {
        if ($pricingModel === 'pack199') {
            return max(0, (int) ($metrics['packs'] ?? 0));
        }

        return max(0, (int) ($metrics['bases'] ?? 0));
    }

    /**
     * @param array<string, int|float> $metrics
     */
    private static function billableAmountDeal(
        array $metrics,
        RateCard $rates,
        string $pricingModel,
        int $billable,
        int $usage,
    ): float {
        if ($billable <= 0) {
            return 0.0;
        }

        if ($pricingModel === 'pack199') {
            return Pricing::roundMoney($billable * $rates->packUnitPrice);
        }

        // Extra: whole conversation-day is free or fully charged (bases is 0/1 per grain).
        if ($usage > 0 && $billable >= $usage) {
            return Pricing::roundMoney((float) ($metrics['amountDeal'] ?? $metrics['amount'] ?? 0.0));
        }

        if ($usage > 0 && $billable > 0 && $billable < $usage) {
            // Should not happen for bases ∈ {0,1}; proportional fallback.
            $gross = (float) ($metrics['amountDeal'] ?? $metrics['amount'] ?? 0.0);

            return Pricing::roundMoney($gross * ($billable / $usage));
        }

        return 0.0;
    }
}
