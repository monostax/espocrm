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
 * Detail-view action for OAuthAccount, scoped to the Meta System User provider.
 *
 * Exposes a "Set System User Token" button that:
 *   1. Prompts the admin to paste a Meta System User access token (and an
 *      optional Business Manager id).
 *   2. POSTs to /api/v1/MetaSystemUserToken/set { oAuthAccountId, token, businessId }.
 *   3. The backend validates the token against Meta's /debug_token endpoint,
 *      encrypts it, and stores it on the OAuthAccount (same storage the rest
 *      of the OAuth subsystem reads from).
 *
 * The button is only visible when the linked OAuthProvider's name indicates
 * the Meta System User provider (seeded name "Meta (System User)").
 */
define('feature-oauth-enhanced:handlers/o-auth-account/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        /**
         * Visible when the account row is persisted and its provider is the
         * Meta System User provider.
         */
        isSetSystemUserTokenAvailable() {
            const model = this.view.model;

            if (!model.id) {
                return false;
            }

            // `providerType` is the OAuthProvider.provider discriminator,
            // exposed as a foreign field on OAuthAccount. Fall back to the
            // provider's display name for resilience.
            const providerType = model.get('providerType') || '';

            if (providerType === 'meta-system-user') {
                return true;
            }

            const providerName = (model.get('providerName') || '').toLowerCase();

            return providerName.indexOf('system user') !== -1;
        }

        setSystemUserToken() {
            const view = this.view;
            const model = view.model;

            const escapeAttr = (s) => String(s)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            const promptLabel = view.translate('systemUserTokenPrompt', 'messages', 'OAuthAccount');
            const businessLabel = view.translate('metaBusinessId', 'fields', 'OAuthAccount');
            const helpText = view.translate('confirmSetSystemUserToken', 'messages', 'OAuthAccount');

            const existingBusinessId = escapeAttr(model.get('metaBusinessId') || '');

            const body =
                '<p>' + escapeAttr(helpText) + '</p>' +
                '<div class="form-group">' +
                    '<label class="control-label">' + escapeAttr(promptLabel) + '</label>' +
                    '<textarea class="form-control" data-name="systemUserToken" rows="4" ' +
                        'style="font-family: monospace;" autocomplete="off"></textarea>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label class="control-label">' + escapeAttr(businessLabel) + '</label>' +
                    '<input type="text" class="form-control" data-name="metaBusinessId" ' +
                        'value="' + existingBusinessId + '" autocomplete="off">' +
                '</div>';

            const dialog = Espo.Ui.dialog({
                backdrop: 'static',
                className: 'dialog-confirm',
                headerText: view.translate('Meta — System User Token', 'labels', 'OAuthAccount'),
                body: body,
                buttonList: [
                    {
                        name: 'save',
                        text: view.translate('Save Token', 'labels', 'OAuthAccount'),
                        style: 'danger',
                        onClick: (d) => {
                            const $el = d.$el ? d.$el : $(d.el);
                            const token = ($el.find('[data-name="systemUserToken"]').val() || '').trim();
                            const businessId = ($el.find('[data-name="metaBusinessId"]').val() || '').trim();

                            if (!token) {
                                Espo.Ui.error(
                                    view.translate('systemUserTokenEmpty', 'messages', 'OAuthAccount')
                                );

                                return;
                            }

                            this._submit(token, businessId, d);
                        },
                    },
                    {
                        name: 'cancel',
                        text: view.translate('Cancel'),
                        onClick: (d) => d.close(),
                    },
                ],
            });

            dialog.show();
        }

        _submit(token, businessId, dialog) {
            const view = this.view;
            const model = view.model;

            Espo.Ui.notify(view.translate('pleaseWait', 'messages'));

            Espo.Ajax.postRequest('MetaSystemUserToken/set', {
                oAuthAccountId: model.id,
                token: token,
                businessId: businessId || null,
            })
                .then(() => {
                    dialog.close();

                    Espo.Ui.success(
                        view.translate('systemUserTokenSet', 'messages', 'OAuthAccount')
                    );

                    model.fetch();
                })
                .catch(xhr => {
                    let errorMsg = view.translate('systemUserTokenFailed', 'messages', 'OAuthAccount');

                    if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    } else if (xhr && xhr.getResponseHeader) {
                        const header = xhr.getResponseHeader('X-Status-Reason');

                        if (header) {
                            errorMsg = header;
                        }
                    }

                    Espo.Ui.error(errorMsg);
                });
        }
    };
});
