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
 * Pack model — totals per calendar day.
 * Every AI run kind counts as one turn; packs = ceil(turns / packSize).
 * Rates come from each Tenant's AI Billing fields (platform defaults if unset).
 */
class BillingPacksPerDay extends AbstractBillingGrid
{
    protected function pricingModel(): string
    {
        return PlanIncludedApplier::MODEL_PACK;
    }

    protected function byTenant(): bool
    {
        return false;
    }
}
