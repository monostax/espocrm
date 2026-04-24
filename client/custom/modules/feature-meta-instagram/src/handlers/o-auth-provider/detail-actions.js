/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('feature-meta-instagram:handlers/o-auth-provider/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        /**
         * Show the button only for meta-instagram OAuthProvider rows that are
         * persisted and have the minimum required credentials populated.
         */
        isConfigureMetaInstagramWebhookAvailable() {
            const model = this.view.model;

            if (!model.id) {
                return false;
            }

            if (model.get('provider') !== 'meta-instagram') {
                return false;
            }

            if (!model.get('clientId')) {
                return false;
            }

            return true;
        }

        configureMetaInstagramWebhook() {
            const view = this.view;
            const model = view.model;

            Espo.Ui.confirm(
                view.translate('confirmConfigureMetaInstagramWebhook', 'messages', 'OAuthProvider'),
                {
                    confirmText: view.translate('Configure', 'labels', 'OAuthProvider'),
                    cancelText: view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(
                        view.translate('Configuring Meta webhook...', 'labels', 'OAuthProvider')
                    );

                    Espo.Ajax.postRequest('MetaInstagramWebhook/configure', {
                        oAuthProviderId: model.id,
                    })
                        .then(response => {
                            this._renderResult(response);
                            // The provider row may have had a verify_token generated — reload.
                            model.fetch();
                        })
                        .catch(xhr => {
                            let errorMsg = view.translate('metaWebhookConfigFailed', 'messages', 'OAuthProvider');
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
            const chatwootFailures = chatwootResults.filter(r => r.status !== 'success');
            const verifyToken = (response && response.verifyToken) || '';
            const callbackUrl = (response && response.callbackUrl) || '';

            // Build the "manual Meta Dashboard steps" dialog body. This is the
            // core UX of this action: the admin copies these two values into
            // Meta App Dashboard → Products → Instagram → Webhooks.
            const instructionsHeader = response && response.verifyTokenGenerated
                ? view.translate('metaWebhookConfiguredWithNewToken', 'messages', 'OAuthProvider')
                : view.translate('metaWebhookConfigured', 'messages', 'OAuthProvider');

            const manualStepsTitle = view.translate('metaWebhookManualStepsTitle', 'messages', 'OAuthProvider');
            const callbackUrlLabel = view.translate('metaWebhookCallbackUrlLabel', 'messages', 'OAuthProvider');
            const verifyTokenLabel = view.translate('metaWebhookVerifyTokenLabel', 'messages', 'OAuthProvider');

            const escapeHtml = (s) => String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');

            let body = '<p>' + escapeHtml(instructionsHeader) + '</p>'
                + '<p><strong>' + escapeHtml(manualStepsTitle) + '</strong></p>'
                + '<div style="margin-bottom:10px;">'
                + '<div style="font-size:85%;color:#888;">' + escapeHtml(callbackUrlLabel) + '</div>'
                + '<code style="display:block;padding:6px 8px;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:4px;word-break:break-all;">'
                + escapeHtml(callbackUrl) + '</code>'
                + '</div>'
                + '<div>'
                + '<div style="font-size:85%;color:#888;">' + escapeHtml(verifyTokenLabel) + '</div>'
                + '<code style="display:block;padding:6px 8px;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:4px;word-break:break-all;">'
                + escapeHtml(verifyToken) + '</code>'
                + '</div>';

            if (chatwootFailures.length > 0) {
                const lines = chatwootFailures
                    .map(r => '<li>' + escapeHtml(r.platformName || r.platformId)
                        + ': ' + escapeHtml(r.error || 'unknown error') + '</li>')
                    .join('');
                body += '<hr><p><strong>'
                    + escapeHtml(view.translate('metaWebhookChatwootFailedHeader', 'messages', 'OAuthProvider'))
                    + '</strong></p><ul>' + lines + '</ul>';
            }

            Espo.Ui.dialog({
                backdrop: 'static',
                className: 'dialog-confirm',
                headerText: view.translate('Instagram Webhook', 'labels', 'OAuthProvider'),
                body,
                buttonList: [
                    {
                        name: 'close',
                        label: view.translate('Close'),
                        style: 'primary',
                        onClick: (d) => d.close(),
                    },
                ],
            }).show();

            if (chatwootFailures.length === 0) {
                Espo.Ui.success(instructionsHeader);
            } else {
                Espo.Ui.warning(
                    view.translate('metaWebhookConfiguredChatwootFailed', 'messages', 'OAuthProvider')
                );
            }
        }
    };
});
