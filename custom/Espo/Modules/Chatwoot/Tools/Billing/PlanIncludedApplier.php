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
 * Applies monthly plan franchise to per-grain billable metrics.
 *
 * Commercial match to Pricing.html:
 *   - package unit = 1 "conversa IA" (typically pack of packSize turns)
 *   - monthly included 200 / 600 / 1000 depending on plan
 *   - overage charged at unit price
 *
 * Units and franchise source per model:
 *   - pack199  → packs,   RateCard::planIncludedUsage
 *   - extra049 → bases,   RateCard::planIncludedUsage
 *   - credit   → credits, RateCard::planIncludedCredits
 *
 * Free units is FIFO chronological per Tenant × calendar month within the
 * grain set (run full-month report windows for correct franchise consumption).
 */
final class PlanIncludedApplier
{
    public const MODEL_PACK = 'pack199';
    public const MODEL_EXTRA = 'extra049';
    public const MODEL_CREDIT = 'credit';

    // Each model gets its own franchise / billed column keys so the label can
    // name the actual unit ("pacotes", "conversas", "créditos") instead of a
    // generic "uso" the customer has to decode.
    public const COL_PACKS_INCLUDED = 'packsIncluded';
    public const COL_PACKS_BILLABLE = 'packsBillable';
    public const COL_CONVERSATIONS_INCLUDED = 'conversationsIncluded';
    public const COL_CONVERSATIONS_BILLABLE = 'conversationsBillable';
    public const COL_CREDITS_INCLUDED = 'creditsIncluded';
    public const COL_CREDITS_BILLABLE = 'creditsBillable';

    /**
     * Franchise / billed column keys emitted by {@see apply()} for a model.
     *
     * @param 'pack199'|'extra049'|'credit' $pricingModel
     * @return array{0: string, 1: string} [includedColumn, billedColumn]
     */
    public static function outputColumns(string $pricingModel): array
    {
        return match ($pricingModel) {
            self::MODEL_PACK => [self::COL_PACKS_INCLUDED, self::COL_PACKS_BILLABLE],
            self::MODEL_CREDIT => [self::COL_CREDITS_INCLUDED, self::COL_CREDITS_BILLABLE],
            default => [
                self::COL_CONVERSATIONS_INCLUDED,
                self::COL_CONVERSATIONS_BILLABLE,
            ],
        };
    }

    /**
     * @param list<array{
     *     dayBucket: string,
     *     tenantId: string,
     *     rates: RateCard,
     *     metrics: array<string, int|float>
     * }> $rows
     * @param 'pack199'|'extra049'|'credit' $pricingModel
     * @return list<array{dayBucket: string, tenantId: string, metrics: array<string, int|float>}>
     */
    public static function apply(array $rows, string $pricingModel): array
    {
        if ($rows === []) {
            return [];
        }

        [$includedColumn, $billableColumn] = self::outputColumns($pricingModel);

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
                $remaining[$monthKey] = max(0, self::monthlyFranchise($rates, $pricingModel));
            }

            $usage = self::usageUnits($metrics, $pricingModel);
            $free = min($usage, $remaining[$monthKey]);
            $remaining[$monthKey] -= $free;
            $billable = $usage - $free;

            $metrics[$includedColumn] = $free;
            $metrics[$billableColumn] = $billable;

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

    private static function monthlyFranchise(RateCard $rates, string $pricingModel): int
    {
        return $pricingModel === self::MODEL_CREDIT
            ? $rates->planIncludedCredits
            : $rates->planIncludedUsage;
    }

    /**
     * @param array<string, int|float> $metrics
     */
    private static function usageUnits(array $metrics, string $pricingModel): int
    {
        $key = match ($pricingModel) {
            self::MODEL_PACK => 'packs',
            self::MODEL_CREDIT => 'credits',
            default => 'bases',
        };

        return max(0, (int) ($metrics[$key] ?? 0));
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

        if ($pricingModel === self::MODEL_PACK) {
            return Pricing::roundMoney($billable * $rates->packUnitPrice);
        }

        if ($pricingModel === self::MODEL_CREDIT) {
            return Pricing::roundMoney($billable * $rates->creditUnitPrice);
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
