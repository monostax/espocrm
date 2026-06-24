/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Opportunity mass-update modal.
 *
 * Flags itself with `isMassUpdate` so that funnel-scoped field views (e.g. the
 * opportunityStage link field) can detect the mass-update context and relax
 * their funnel-first selection guard. The blank model used by the mass-update
 * modal carries no funnel, so stages are offered across all funnels; on apply,
 * each record is moved to the chosen stage's owning funnel by
 * MassAction\Opportunity\MassUpdate.
 */
define("global:views/opportunity/modals/mass-update", [
    "views/modals/mass-update",
], function (Dep) {
    return Dep.extend({
        isMassUpdate: true,
    });
});
