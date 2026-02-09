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
 * Setup handler for ChatwootAgent detail/edit views.
 * 
 * This handler restricts the Metadata tab visibility to administrators only.
 * The tab is also hidden on create view via dynamicLogicVisible in the layout.
 */
define('chatwoot:handlers/chatwoot-agent/record-detail-setup', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        process() {
            // Hide stream panel (audit log is still accessible via "View Audit Log" action)
            this.view.hidePanel('stream', true);

            // Hide metadata panels for non-admin users
            if (!this.view.getUser().isAdmin()) {
                this.view.hidePanel('metadata', true);
                this.view.hidePanel('syncInfo', true);
            }
        }
    };
});
