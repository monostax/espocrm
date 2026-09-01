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

use Espo\Modules\Chatwoot\Tools\Billing\PlanIncludedApplier;

/**
 * Credit model — pure linear credits — totals per calendar day.
 *
 * 1 AI engagement = 1 credit, whatever triggered it (customer reply, an
 * "@" mention from the team, follow-up, scheduled message). The monthly plan
 * franchise (planIncludedCredits) covers the first credits of the month
 * FIFO; everything above is billed at creditUnitPrice.
 *
 * Deliberately has no base / pack / conversation-day columns: the invoice
 * reads "credits consumed − credits included = credits billed × unit price".
 * Rates come from each Tenant's AI Billing periods (platform defaults if unset).
 */
class BillingCreditsPerDay extends AbstractBillingGrid
{
    protected function pricingModel(): string
    {
        return PlanIncludedApplier::MODEL_CREDIT;
    }

    protected function byTenant(): bool
    {
        return false;
    }
}
