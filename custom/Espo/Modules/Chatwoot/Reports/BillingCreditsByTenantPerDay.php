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
 * Credit model — pure linear credits — per Ambiente per calendar day.
 *
 * Same math as {@see BillingCreditsPerDay}, split by Tenant so each deal
 * currency and credit franchise is read out separately (day rollups only
 * sum the FX-normalised `amount`).
 */
class BillingCreditsByTenantPerDay extends AbstractBillingGrid
{
    protected function pricingModel(): string
    {
        return PlanIncludedApplier::MODEL_CREDIT;
    }

    protected function byTenant(): bool
    {
        return true;
    }
}
