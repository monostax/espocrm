/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("global:views/opportunity/record/list", [
    "crm:views/opportunity/record/list",
], function (Dep) {
    return Dep.extend({
        mandatorySelectAttributeList: ["opportunityStageName", "opportunityStageStyle"],
    });
});
