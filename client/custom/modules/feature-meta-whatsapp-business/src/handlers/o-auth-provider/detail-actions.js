/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('feature-meta-whatsapp-business:handlers/o-auth-provider/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        /**
         * Show the button only for meta-whatsapp OAuthProvider rows that are
         * persisted and have the minimum required credentials populated.
         */
        isConfigureMetaWhatsAppWebhookAvailable() {
            const model = this.view.model;

            if (!model.id) {
                return false;
            }

            if (model.get('provider') !== 'meta-whatsapp') {
                return false;
            }

            if (!model.get('clientId')) {
                return false;
            }

            return true;
        }

        configureMetaWhatsAppWebhook() {
            const view = this.view;
            const model = view.model;

            Espo.Ui.confirm(
                view.translate('confirmConfigureMetaWhatsAppWebhook', 'messages', 'OAuthProvider'),
                {
                    confirmText: view.translate('Configure', 'labels', 'OAuthProvider'),
                    cancelText: view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(
                        view.translate('Configuring WhatsApp webhook...', 'labels', 'OAuthProvider')
                    );

                    Espo.Ajax.postRequest('MetaWhatsAppWebhook/configure', {
                        oAuthProviderId: model.id,
                    })
                        .then(response => {
                            this._renderResult(response);
                        })
                        .catch(xhr => {
                            let errorMsg = view.translate('whatsappWebhookConfigFailed', 'messages', 'OAuthProvider');
                            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                                errorMsg = xhr.responseJSON.message;
                            } else if (xhr && xhr.statusText) {
                                errorMsg = xhr.statusText;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }

        _renderResult(response) {
            const view = this.view;
            const chatwootResults = (response && response.chatwoot) || [];
            const failures = chatwootResults.filter(r => r.status !== 'success');

            if (failures.length === 0) {
                Espo.Ui.success(
                    view.translate('whatsappWebhookConfigured', 'messages', 'OAuthProvider')
                );
                return;
            }

            const lines = failures
                .map(r => `• ${r.platformName || r.platformId}: ${r.error || 'unknown error'}`)
                .join('\n');

            Espo.Ui.warning(
                view.translate('whatsappWebhookConfiguredChatwootFailed', 'messages', 'OAuthProvider') +
                '\n' + lines
            );
        }
    };
});
