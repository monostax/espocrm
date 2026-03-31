/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('feature-integration-medx:controllers/no-create-route', [
    'controllers/record',
], function (Dep) {

    return Dep.extend({
        actionCreate: function () {
            this.getRouter().navigate('#' + this.name, {trigger: true});
        },
    });
});
