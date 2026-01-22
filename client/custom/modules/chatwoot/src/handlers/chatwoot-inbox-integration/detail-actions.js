/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('chatwoot:handlers/chatwoot-inbox-integration/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        isActivateAvailable() {
            const status = this.view.model.get('status');
            return ['DRAFT', 'FAILED'].includes(status);
        }

        isDisconnectAvailable() {
            const status = this.view.model.get('status');
            return ['ACTIVE', 'PENDING_QR', 'CONNECTING'].includes(status);
        }

        isReconnectAvailable() {
            const status = this.view.model.get('status');
            return status === 'DISCONNECTED';
        }

        activate() {
            const model = this.view.model;

            Espo.Ui.confirm(
                this.view.translate('confirmActivate', 'messages', 'ChatwootInboxIntegration'),
                {
                    confirmText: this.view.translate('Activate', 'labels', 'ChatwootInboxIntegration'),
                    cancelText: this.view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(this.view.translate('Creating resources', 'labels', 'ChatwootInboxIntegration'));

                    Espo.Ajax.postRequest(`ChatwootInboxIntegration/${model.id}/activate`)
                        .then(response => {
                            Espo.Ui.success(this.view.translate('channelActivated', 'messages', 'ChatwootInboxIntegration'));
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch(xhr => {
                            let errorMsg = 'Activation failed';
                            if (xhr?.responseJSON?.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }

        disconnect() {
            const model = this.view.model;

            Espo.Ui.confirm(
                this.view.translate('confirmDisconnect', 'messages', 'ChatwootInboxIntegration'),
                {
                    confirmText: this.view.translate('Disconnect', 'labels', 'ChatwootInboxIntegration'),
                    cancelText: this.view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(this.view.translate('Disconnecting...'));

                    Espo.Ajax.postRequest(`ChatwootInboxIntegration/${model.id}/disconnect`)
                        .then(response => {
                            Espo.Ui.success(this.view.translate('channelDisconnected', 'messages', 'ChatwootInboxIntegration'));
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch(xhr => {
                            let errorMsg = 'Disconnect failed';
                            if (xhr?.responseJSON?.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }

        reconnect() {
            const model = this.view.model;

            Espo.Ui.notify(this.view.translate('Reconnecting...'));

            Espo.Ajax.postRequest(`ChatwootInboxIntegration/${model.id}/reconnect`)
                .then(response => {
                    if (response.status === 'ACTIVE') {
                        Espo.Ui.success(this.view.translate('channelConnected', 'messages', 'ChatwootInboxIntegration'));
                    } else {
                        Espo.Ui.success(this.view.translate('channelActivated', 'messages', 'ChatwootInboxIntegration'));
                    }
                    model.set(response);
                    this.view.reRender();
                })
                .catch(xhr => {
                    let errorMsg = 'Reconnect failed';
                    if (xhr?.responseJSON?.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    Espo.Ui.error(errorMsg);
                });
        }
    };
});
