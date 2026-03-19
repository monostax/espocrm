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
 * - Hides syncInfo panel for non-admin users (same as ChatwootAgent handler).
 * - Hides aiProfile panel when no agent is linked (chatwootAgentId is empty).
 *   Dynamically shows/hides on model changes (after enableAiProfile action).
 */
define('chatwoot:handlers/chatwoot-account-user-membership/record-detail-setup', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        process() {
            // Hide syncInfo panel for non-admin users
            if (!this.view.getUser().isAdmin()) {
                this.view.hidePanel('syncInfo', true);
            }

            // Hide aiProfile panel when no agent is linked
            this._updateAiProfilePanelVisibility();

            // Listen for changes to chatwootAgentId to dynamically show/hide
            this.view.listenTo(this.view.model, 'change:chatwootAgentId', () => {
                this._updateAiProfilePanelVisibility();
            });
        }

        /**
         * Show or hide the aiProfile panel based on chatwootAgentId presence.
         * @private
         */
        _updateAiProfilePanelVisibility() {
            const agentId = this.view.model.get('chatwootAgentId');

            if (agentId) {
                this.view.showPanel('aiProfile');
            } else {
                this.view.hidePanel('aiProfile', true);
            }
        }
    };
});
