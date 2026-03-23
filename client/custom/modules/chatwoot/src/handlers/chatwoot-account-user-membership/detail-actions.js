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
 * Detail action handler for ChatwootAccountUserMembership.
 *
 * Provides "Enable AI Profile" and "Disable AI Profile" toggle buttons.
 * The isAI state is read directly from the membership model (no separate agent entity).
 *
 * Enable: sets isAI=true on the membership via API action.
 * Disable: sets isAI=false on the membership via API action.
 */
define('chatwoot:handlers/chatwoot-account-user-membership/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        /**
         * Enable AI Profile is available when isAI is not true.
         */
        isEnableAiProfileAvailable() {
            return this.view.model.get('isAI') !== true;
        }

        /**
         * Disable AI Profile is available when isAI is true.
         */
        isDisableAiProfileAvailable() {
            return this.view.model.get('isAI') === true;
        }

        enableAiProfile() {
            const model = this.view.model;

            Espo.Ui.confirm(
                this.view.translate('confirmEnableAiProfile', 'messages', 'ChatwootAccountUserMembership'),
                {
                    confirmText: this.view.translate('Enable AI Profile', 'labels', 'ChatwootAccountUserMembership'),
                    cancelText: this.view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(this.view.translate('Please wait...'));

                    Espo.Ajax.postRequest(`ChatwootAccountUserMembership/${model.id}/enableAiProfile`)
                        .then(response => {
                            Espo.Ui.success(
                                this.view.translate('aiProfileEnabled', 'messages', 'ChatwootAccountUserMembership')
                            );
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch(xhr => {
                            let errorMsg = 'Failed to enable AI profile';
                            if (xhr?.responseJSON?.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }

        disableAiProfile() {
            const model = this.view.model;

            Espo.Ui.confirm(
                this.view.translate('confirmDisableAiProfile', 'messages', 'ChatwootAccountUserMembership'),
                {
                    confirmText: this.view.translate('Disable AI Profile', 'labels', 'ChatwootAccountUserMembership'),
                    cancelText: this.view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(this.view.translate('Please wait...'));

                    Espo.Ajax.postRequest(`ChatwootAccountUserMembership/${model.id}/disableAiProfile`)
                        .then(response => {
                            Espo.Ui.success(
                                this.view.translate('aiProfileDisabled', 'messages', 'ChatwootAccountUserMembership')
                            );
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch(xhr => {
                            let errorMsg = 'Failed to disable AI profile';
                            if (xhr?.responseJSON?.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }
    };
});
