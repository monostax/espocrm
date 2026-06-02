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

        // The WAHA send companion can be (re-)linked for a coexistence channel
        // once its Cloud inbox exists. We expose it whenever the channel is in a
        // settled coexistence state (ACTIVE / pending Meta handshake) or already
        // mid-link (PENDING_WAHA_LINK, to allow re-issuing the QR).
        isLinkWahaCompanionAvailable() {
            const model = this.view.model;
            if (model.get('channelType') !== 'whatsappCoexistence') {
                return false;
            }
            const status = model.get('status');
            return ['ACTIVE', 'PENDING_COEXISTENCE_CONFIRMATION', 'PENDING_WAHA_LINK'].includes(status)
                && !!model.get('chatwootInboxId');
        }

        linkWahaCompanion() {
            const model = this.view.model;

            Espo.Ui.notify(this.view.translate('Loading QR Code', 'labels', 'ChatwootInboxIntegration'));

            Espo.Ajax.postRequest(`ChatwootInboxIntegration/${model.id}/linkWahaCompanion`)
                .then(response => {
                    Espo.Ui.notify(false);
                    model.set(response);
                    this.view.reRender();
                })
                .catch(xhr => {
                    let errorMsg = 'Failed to link WhatsApp companion';
                    if (xhr?.responseJSON?.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    Espo.Ui.error(errorMsg);
                });
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
