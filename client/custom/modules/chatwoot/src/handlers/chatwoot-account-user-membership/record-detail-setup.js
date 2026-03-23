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
 * Setup handler for ChatwootAccountUserMembership detail/edit views.
 *
 * - Hides metadata/sync panels for non-admin users (ported from ChatwootAgent handler).
 * - Hides aiConfiguration tab when isAI is not true.
 *   Dynamically shows/hides on model changes (after enableAiProfile/disableAiProfile actions).
 */
define('chatwoot:handlers/chatwoot-account-user-membership/record-detail-setup', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        process() {
            // Hide syncInfo and agentState panels for non-admin users
            if (!this.view.getUser().isAdmin()) {
                this.view.hidePanel('syncInfo', true);
                this.view.hidePanel('agentState', true);
            }

            // Hide aiConfiguration tab when isAI is not true
            this._updateAiConfigurationVisibility();

            // Listen for changes to isAI to dynamically show/hide
            this.view.listenTo(this.view.model, 'change:isAI', () => {
                this._updateAiConfigurationVisibility();
            });
        }

        /**
         * Show or hide the aiConfiguration panel based on isAI value.
         * @private
         */
        _updateAiConfigurationVisibility() {
            const isAI = this.view.model.get('isAI');

            if (isAI === true) {
                this.view.showPanel('aiConfiguration');
            } else {
                this.view.hidePanel('aiConfiguration', true);
            }
        }
    };
});
