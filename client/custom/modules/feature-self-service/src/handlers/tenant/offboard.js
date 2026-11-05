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
 * "Offboard workspace" in the Tenant detail menu, for instance administrators.
 * Opens the confirmation (type the slug), then shows the offboarding record.
 */
import ActionHandler from 'action-handler';

export default class extends ActionHandler {

    isOffboardVisible() {
        return this.view.getUser().isAdmin();
    }

    async offboard() {
        const view = await this.view.createView('offboardDialog', 'feature-self-service:views/tenant/modals/offboard', {
            model: this.view.model,
        });

        this.view.listenToOnce(view, 'started', id => {
            this.view.getRouter().navigate(`#SelfServiceOffboarding/view/${id}`, {trigger: true});
        });

        await view.render();
    }
}
