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
 * Setup handler for ChatwootInboxIntegration detail/edit views.
 * 
 * Panel visibility is primarily handled via dynamicLogicVisible in the layout:
 * - whatsappConnection: visible only when record exists (id is not empty)
 * - whatsappMetadata: visible only when record exists AND channelType is whatsappQrcode
 * - error: visible only when errorMessage is not empty
 * 
 * This handler manages additional dynamic behaviors.
 */
define('chatwoot:handlers/chatwoot-inbox-integration/record-detail-setup', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        process() {
            const view = this.view;
            const model = view.model;

            // Listen for status changes to update UI
            view.listenTo(model, 'change:status', () => {
                view.reRender();
            });
        }
    };
});
