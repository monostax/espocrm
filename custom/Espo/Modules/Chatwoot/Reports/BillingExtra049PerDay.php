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

namespace Espo\Modules\Chatwoot\Reports;

/**
 * Negotiation model — base + extra unit — totals per calendar day.
 *
 * Base: one per conversation-day with any AI engagement.
 * Included: up to N customer-message turns (Tenant AI Billing).
 * Extras (unit price each): customer-message turns beyond N + every
 * non-customer-message run (mention, follow-up, scheduled, …).
 * Rates come from each Tenant's AI Billing fields (platform defaults if unset).
 */
class BillingExtra049PerDay extends AbstractBillingGrid
{
    protected function pricingModel(): string
    {
        return 'extra049';
    }

    protected function byTenant(): bool
    {
        return false;
    }
}
