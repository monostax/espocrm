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
 * Detail-view actions for OAuthProvider, scoped to Meta Lead Ads.
 *
 * Exposes a "Configure Lead Ads Webhook" button that:
 *   1. Generates a webhookVerifyToken on the OAuthProvider if missing.
 *   2. Builds the per-provider callback URL:
 *        {siteUrl}/api/v1/MetaLeadAds/webhook/{oAuthProviderId}
 *   3. Calls POST graph.facebook.com/{appId}/subscriptions to register the
 *      Lead Ads App-level webhook at Meta.
 *   4. Renders the resulting URL + verify_token in a dialog so the admin can
 *      visually confirm the registration (and copy them into the Meta App
 *      Dashboard if the auto-registration failed).
 *
 * BYOA: each tenant can have their own meta-leadads OAuthProvider row with
 * its own clientId/clientSecret. The per-provider callback URL keeps each
 * App's signature verification unambiguous.
 *
 * The button is visible only when:
 *   - The row is persisted (has an id).
 *   - provider === 'meta-leadads'.
 *   - clientId and clientSecret are set (Meta App credentials available).
 */
define('feature-meta-lead-ads:handlers/o-auth-provider/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        isConfigureMetaLeadAdsWebhookAvailable() {
            const model = this.view.model;

            if (!model.id) {
                return false;
            }

            if (model.get('provider') !== 'meta-leadads') {
                return false;
            }

            if (!model.get('clientId')) {
                return false;
            }

            // Note: we intentionally DO NOT check `clientSecret` here.
            // EspoCRM password-type fields are write-only on the FE model
            // (the value is never sent back to the client after save), so
            // a frontend truthiness check on `clientSecret` would always
            // return false and hide the button even when the secret is
            // correctly set. The backend WebhookConfigService validates
            // clientSecret on click and surfaces a clear error dialog if
            // missing — same pattern as the FeatureMetaInstagram handler.
            return true;
        }

        configureMetaLeadAdsWebhook() {
            const view = this.view;
            const model = view.model;

            Espo.Ui.confirm(
                view.translate('confirmConfigureMetaLeadAdsWebhook', 'messages', 'OAuthProvider'),
                {
                    confirmText: view.translate('Configure', 'labels', 'OAuthProvider'),
                    cancelText: view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(
                        view.translate('Configuring Meta Lead Ads webhook...', 'labels', 'OAuthProvider')
                    );

                    Espo.Ajax.postRequest('MetaLeadAdsWebhookConfig/configure', {
                        oAuthProviderId: model.id,
                    })
                        .then(response => {
                            this._renderResult(response);
                            // A new verify_token may have been generated — reload.
                            model.fetch();
                        })
                        .catch(xhr => {
                            let errorMsg = view.translate('metaLeadAdsWebhookConfigFailed', 'messages', 'OAuthProvider');
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
            const verifyToken = (response && response.verifyToken) || '';
            const callbackUrl = (response && response.callbackUrl) || '';
            const registered = !!(response && response.appSubscriptionRegistered);
            const warnings = (response && response.warnings) || [];
            const subscriptions = (response && response.subscriptions) || [];

            const escapeHtml = (s) => String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');

            const headerMsg = registered
                ? (response && response.verifyTokenGenerated
                    ? view.translate('metaLeadAdsWebhookRegisteredWithNewToken', 'messages', 'OAuthProvider')
                    : view.translate('metaLeadAdsWebhookRegistered', 'messages', 'OAuthProvider'))
                : view.translate('metaLeadAdsWebhookManualFallback', 'messages', 'OAuthProvider');

            const callbackUrlLabel = view.translate('metaLeadAdsWebhookCallbackUrlLabel', 'messages', 'OAuthProvider');
            const verifyTokenLabel = view.translate('metaLeadAdsWebhookVerifyTokenLabel', 'messages', 'OAuthProvider');

            let body = '<p>' + escapeHtml(headerMsg) + '</p>'
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

            if (subscriptions.length > 0) {
                const subLines = subscriptions.map(s => {
                    const fields = Array.isArray(s.fields)
                        ? s.fields.map(f => typeof f === 'string' ? f : (f && f.name) || '').filter(Boolean).join(', ')
                        : '';
                    return '<li>'
                        + '<strong>' + escapeHtml(s.object || '') + '</strong>'
                        + ' → <code>' + escapeHtml(s.callback_url || '') + '</code>'
                        + (fields ? ' [' + escapeHtml(fields) + ']' : '')
                        + (s.active === false ? ' <em>(inactive)</em>' : '')
                        + '</li>';
                }).join('');

                body += '<hr><p><strong>'
                    + escapeHtml(view.translate('metaLeadAdsWebhookSubscriptionsHeader', 'messages', 'OAuthProvider'))
                    + '</strong></p><ul style="padding-left:18px;">' + subLines + '</ul>';
            }

            if (warnings.length > 0) {
                const warnLines = warnings.map(w => '<li>' + escapeHtml(w) + '</li>').join('');
                body += '<hr><p><strong>'
                    + escapeHtml(view.translate('metaLeadAdsWebhookWarningsHeader', 'messages', 'OAuthProvider'))
                    + '</strong></p><ul style="padding-left:18px;">' + warnLines + '</ul>';
            }

            Espo.Ui.dialog({
                backdrop: 'static',
                className: 'dialog-confirm',
                headerText: view.translate('Lead Ads Webhook', 'labels', 'OAuthProvider'),
                body: body,
                buttonList: [
                    {
                        name: 'close',
                        label: view.translate('Close'),
                        style: 'primary',
                        onClick: (d) => d.close(),
                    },
                ],
            }).show();

            if (registered && warnings.length === 0) {
                Espo.Ui.success(headerMsg);
            } else if (warnings.length > 0) {
                Espo.Ui.warning(headerMsg);
            }
        }
    };
});
