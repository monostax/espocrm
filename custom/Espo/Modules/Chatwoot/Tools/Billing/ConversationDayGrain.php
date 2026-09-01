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
 * One billable conversation × calendar-day grain (before price math).
 */
final class ConversationDayGrain
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $dayBucket,
        public readonly ?string $tenantId,
        public readonly int $customerMessageTurns,
        public readonly int $nonCustomerTurns,
    ) {}

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
