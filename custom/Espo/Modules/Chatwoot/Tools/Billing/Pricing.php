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
 * Pure pricing math for AI agent run billing units.
 *
 * Two alternative commercial models. Both use a **calendar-day** window
 * (app timezone day bucket) and charge per conversation-day that had at
 * least one AI run. Unit prices come from a {@see RateCard} (per-Tenant
 * deal, or platform defaults).
 *
 * Model A — Extra (negotiation flavour):
 *   base   = extraBasePrice once per conversation-day
 *   pack   = up to extraIncludedCustomerTurns customer-message turns
 *   extras = extraUnitPrice × (customer-message turns beyond free pack
 *                        + every non-customer-message run)
 *
 * Model B — Pack (simplified):
 *   every run counts as one turn
 *   packs  = ceil(turns / packSize)
 *   amount = packs × packUnitPrice
 *
 * Model C — Credit (pure linear, customer-facing):
 *   every run = 1 credit, whatever its kind
 *   amount = credits × creditUnitPrice (gross; the monthly credit franchise
 *            is applied later by {@see PlanIncludedApplier})
 *   No conversation/base/pack concept at all — the invoice reads
 *   "credits consumed − credits included = credits billed".
 */
final class Pricing
{
    public const CUSTOMER_MESSAGE_KIND = 'customer-message';

    /** @deprecated Use RateCard::DEFAULT_* — kept for call-site continuity. */
    public const EXTRA_BASE_PRICE = RateCard::DEFAULT_EXTRA_BASE_PRICE;
    public const EXTRA_UNIT_PRICE = RateCard::DEFAULT_EXTRA_UNIT_PRICE;
    public const EXTRA_INCLUDED_CUSTOMER_TURNS = RateCard::DEFAULT_EXTRA_INCLUDED_CUSTOMER_TURNS;
    public const PACK_UNIT_PRICE = RateCard::DEFAULT_PACK_UNIT_PRICE;
    public const PACK_SIZE = RateCard::DEFAULT_PACK_SIZE;

    /**
     * @param int $customerMessageTurns Runs with kind = customer-message.
     * @param int $nonCustomerTurns     Runs with any other kind.
     * @return array{
     *     bases: int,
     *     customerTurns: int,
     *     turnOverages: int,
     *     kindExtras: int,
     *     extras: int,
     *     amount: float
     * }
     */
    public static function extra049(
        int $customerMessageTurns,
        int $nonCustomerTurns,
        ?RateCard $rates = null,
    ): array {
        $rates ??= RateCard::defaults();
        $customerMessageTurns = max(0, $customerMessageTurns);
        $nonCustomerTurns = max(0, $nonCustomerTurns);
        $total = $customerMessageTurns + $nonCustomerTurns;

        if ($total === 0) {
            return [
                'bases' => 0,
                'customerTurns' => 0,
                'turnOverages' => 0,
                'kindExtras' => 0,
                'extras' => 0,
                'amount' => 0.0,
            ];
        }

        $turnOverages = max(0, $customerMessageTurns - $rates->extraIncludedCustomerTurns);
        $kindExtras = $nonCustomerTurns;
        $extras = $turnOverages + $kindExtras;

        return [
            'bases' => 1,
            'customerTurns' => $customerMessageTurns,
            'turnOverages' => $turnOverages,
            'kindExtras' => $kindExtras,
            'extras' => $extras,
            'amount' => self::roundMoney(
                $rates->extraBasePrice + ($extras * $rates->extraUnitPrice)
            ),
        ];
    }

    /**
     * @return array{turns: int, packs: int, amount: float}
     */
    public static function pack199(int $turns, ?RateCard $rates = null): array
    {
        $rates ??= RateCard::defaults();
        $turns = max(0, $turns);

        if ($turns === 0) {
            return [
                'turns' => 0,
                'packs' => 0,
                'amount' => 0.0,
            ];
        }

        $packs = (int) ceil($turns / $rates->packSize);

        return [
            'turns' => $turns,
            'packs' => $packs,
            'amount' => self::roundMoney($packs * $rates->packUnitPrice),
        ];
    }

    /**
     * Pure linear credits: 1 AI engagement = 1 credit, no bundling.
     *
     * `replyCredits` / `mentionCredits` are a breakdown for the invoice
     * detail only — both cost the same and both are already inside
     * `credits`. Amount here is gross; the monthly credit franchise is
     * applied by {@see PlanIncludedApplier}.
     *
     * @param int $customerMessageTurns Runs with kind = customer-message.
     * @param int $nonCustomerTurns     Runs with any other kind
     *                                  (mention, follow-up, scheduled, …).
     * @return array{
     *     credits: int,
     *     replyCredits: int,
     *     mentionCredits: int,
     *     amount: float
     * }
     */
    public static function credit(
        int $customerMessageTurns,
        int $nonCustomerTurns,
        ?RateCard $rates = null,
    ): array {
        $rates ??= RateCard::defaults();
        $replyCredits = max(0, $customerMessageTurns);
        $mentionCredits = max(0, $nonCustomerTurns);
        $credits = $replyCredits + $mentionCredits;

        return [
            'credits' => $credits,
            'replyCredits' => $replyCredits,
            'mentionCredits' => $mentionCredits,
            'amount' => self::roundMoney($credits * $rates->creditUnitPrice),
        ];
    }

    public static function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
