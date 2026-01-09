/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('waha:handlers/communication-channel/record-detail-setup', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        process() {
            const model = this.view.model;

            // Listen for status changes to update UI
            this.view.listenTo(model, 'change:status', () => {
                this.view.reRender();
            });

            // Hide error panel if no error
            this.view.listenTo(model, 'sync', () => {
                const errorMessage = model.get('errorMessage');
                const errorPanel = this.view.getView('middle')?.panelList?.find(p => p.label === 'Error');
                
                if (errorPanel) {
                    if (!errorMessage) {
                        errorPanel.hidden = true;
                    } else {
                        errorPanel.hidden = false;
                    }
                }
            });
        }
    };
});

