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
 * One billable conversation or opportunity × calendar-day grain.
 */
final class ConversationDayGrain
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $dayBucket,
        public readonly ?string $tenantId,
        public readonly int $customerMessageTurns,
        public readonly int $nonCustomerTurns,
        public readonly ?string $opportunityId = null,
    ) {}

    /**
     * Merge kind-level SQL counts without mixing tenants, days, or scope types.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<self>
     */
    public static function fromGroupedCounts(array $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            $scopeId = (string) ($row['scopeId'] ?? '');
            $scopeType = (string) ($row['scopeType'] ?? '');
            $count = (int) ($row['cnt'] ?? 0);
            if ($scopeId === '' || !in_array($scopeType, ['conversation', 'opportunity'], true) || $count <= 0) {
                continue;
            }
            $day = (string) ($row['dayBucket'] ?? '-');
            $tenant = $row['tenantId'] ?? null;
            $key = implode("\0", [$scopeType, $scopeId, $day, $tenant ?? '']);
            $merged[$key] ??= [
                'scopeType' => $scopeType, 'scopeId' => $scopeId, 'day' => $day,
                'tenant' => $tenant, 'customer' => 0, 'nonCustomer' => 0,
            ];
            $kind = $scopeType === 'conversation' && ($row['kind'] ?? '') === Pricing::CUSTOMER_MESSAGE_KIND
                ? 'customer' : 'nonCustomer';
            $merged[$key][$kind] += $count;
        }
        return array_values(array_map(static fn (array $row) => new self(
            $row['scopeType'] === 'conversation' ? $row['scopeId'] : null,
            $row['day'], $row['tenant'], $row['customer'], $row['nonCustomer'],
            $row['scopeType'] === 'opportunity' ? $row['scopeId'] : null,
        ), $merged));
    }

    public function totalTurns(): int
    {
        return $this->customerMessageTurns + $this->nonCustomerTurns;
    }

    /**
     * @return array{
     *     bases: int,
     *     customerTurns: int,
     *     turnOverages: int,
     *     kindExtras: int,
     *     extras: int,
     *     amount: float
     * }
     */
    public function priceExtra049(?RateCard $rates = null): array
    {
        return Pricing::extra049(
            $this->customerMessageTurns,
            $this->nonCustomerTurns,
            $rates
        );
    }

    /**
     * @return array{turns: int, packs: int, amount: float}
     */
    public function pricePack199(?RateCard $rates = null): array
    {
        return Pricing::pack199($this->totalTurns(), $rates);
    }

    /**
     * @return array{
     *     credits: int,
     *     replyCredits: int,
     *     mentionCredits: int,
     *     amount: float
     * }
     */
    public function priceCredit(?RateCard $rates = null): array
    {
        return Pricing::credit(
            $this->customerMessageTurns,
            $this->nonCustomerTurns,
            $rates
        );
    }
}
