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
 * Side panel for OAuthAccount that replaces the generic OAuth "Connect"
 * button with Meta's Embedded Signup v4 launcher when the underlying
 * OAuthProvider.provider is `meta-whatsapp` or `meta-whatsapp-coexistence`.
 *
 * Flow:
 *   1. User clicks "Connect with Embedded Signup".
 *   2. We lazy-load the FB JS SDK (https://connect.facebook.net/en_US/sdk.js)
 *      and `FB.init({ appId: clientId, version: 'v22.0' })`.
 *   3. We attach a `window.message` listener for the `WA_EMBEDDED_SIGNUP`
 *      events postMessage'd by the popup (sessionInfoVersion 3).
 *   4. We call `FB.login(callback, {
 *          config_id, response_type: 'code', override_default_response_type: true,
 *          extras: { setup: {}, featureType, sessionInfoVersion: '3' }
 *      })`.
 *      `featureType` is:
 *         - empty string ('') for the legacy Cloud-API-only flow
 *           (`provider=meta-whatsapp`), or
 *         - 'whatsapp_business_app_onboarding' for Coexistence
 *           (`provider=meta-whatsapp-coexistence`).
 *   5. From the postMessage events we capture `waba_id`, `phone_number_id`,
 *      and which `FINISH*` event fired (determines onboardingType).
 *   6. From the FB.login callback we get the authorization-code grant `code`.
 *   7. We POST that code to the standard `OAuth/{id}/connection` endpoint
 *      (same as EspoCRM's generic OAuth flow) — this stores the access
 *      token on the OAuthAccount via TokenSetter.
 *   8. THEN we POST to `WhatsAppEmbeddedSignup/finish` with the captured
 *      session_info so the backend can persist `whatsappBusinessAccountId`,
 *      `whatsappPhoneNumberId`, and (for Coexistence) kick off the
 *      `smb_app_data` sync inside Meta's 24h deadline.
 *
 * We keep the standard "Disconnect" button identical to the parent.
 */
define(
    'feature-meta-whatsapp-business:views/o-auth-account/records/panels/connection',
    ['views/o-auth-account/records/panels/connection'],
    function (Dep) {

    const WHATSAPP_PROVIDERS = ['meta-whatsapp', 'meta-whatsapp-coexistence'];
    const SYSTEM_USER_PROVIDER = 'meta-system-user';
    const FB_SDK_URL = 'https://connect.facebook.net/en_US/sdk.js';
    const FB_API_VERSION = 'v22.0';

    return Dep.extend({

        // language=Handlebars
        templateContent: `
            {{#if hasDisconnect}}
                <div class="margin-bottom">
                    {{#if isLiveConnected}}
                        <span class="label label-success label-md">{{translate 'Connected' scope='ExternalAccount'}}</span>
                    {{else}}
                        <span class="label label-{{liveStatusStyle}} label-md">{{liveStatusLabel}}</span>
                    {{/if}}
                </div>
                <button class="btn btn-default" data-action="disconnect">{{translate 'Disconnect' scope='ExternalAccount'}}</button>
                {{#if isSystemUserConnected}}
                    <button class="btn btn-default" data-action="generateSystemUserToken" style="margin-left: 6px;">
                        {{translate 'Regenerate Token (Scopes)' category='labels' scope='OAuthAccount'}}
                    </button>
                {{/if}}
            {{/if}}

            {{#if hasEmbeddedSignup}}
                <div class="margin-bottom">
                    <span class="label label-default label-md">{{translate 'Disconnected' scope='ExternalAccount'}}</span>
                </div>
                <button class="btn btn-primary" data-action="connectEmbeddedSignup">
                    {{embeddedSignupLabel}}
                </button>
                {{#if isMissingConfigId}}
                    <div class="text-danger small margin-top">
                        {{translate 'embeddedSignupMissingConfigId' category='messages' scope='OAuthProvider'}}
                    </div>
                {{/if}}
                {{#if isMissingClientId}}
                    <div class="text-danger small margin-top">
                        {{translate 'embeddedSignupMissingClientId' category='messages' scope='OAuthProvider'}}
                    </div>
                {{/if}}
            {{/if}}

            {{#if hasSystemUserToken}}
                <div class="margin-bottom">
                    <span class="label label-default label-md">{{translate 'Disconnected' scope='ExternalAccount'}}</span>
                </div>
                <button class="btn btn-danger" data-action="setSystemUserToken">
                    {{translate 'Set System User Token' category='labels' scope='OAuthAccount'}}
                </button>
                {{#if isMissingClientId}}
                    <div class="text-danger small margin-top">
                        {{translate 'systemUserMissingClientId' category='messages' scope='OAuthAccount'}}
                    </div>
                {{/if}}
            {{/if}}

            {{#if hasFallbackConnect}}
                <div class="margin-bottom">
                    <span class="label label-default label-md">{{translate 'Disconnected' scope='ExternalAccount'}}</span>
                </div>
                <button class="btn btn-default" data-action="connect">{{translate 'Connect' scope='ExternalAccount'}}</button>
            {{/if}}
        `,

        inProcess: false,
        fbSdkPromise: null,
        sessionInfo: null,
        finishEvent: null,

        setup: function () {
            Dep.prototype.setup.call(this);

            this.addActionHandler('connectEmbeddedSignup', () => this.actionConnectEmbeddedSignup());
            this.addActionHandler('setSystemUserToken', () => this.actionSetSystemUserToken());
        },

        data: function () {
            const isSet = this.model.attributes.hasAccessToken !== undefined;
            const providerType = this._getProviderType();
            const isWhatsAppProvider = WHATSAPP_PROVIDERS.indexOf(providerType) !== -1;
            const isSystemUserProvider = providerType === SYSTEM_USER_PROVIDER;

            const hasDisconnect = !this.inProcess && isSet && this.model.attributes.hasAccessToken;

            const canConnect = !this.inProcess
                && isSet
                && !this.model.attributes.hasAccessToken
                && this.model.attributes.providerIsActive;

            const oauthData = this.model.attributes.data || {};

            const isMissingClientId =
                (isWhatsAppProvider || isSystemUserProvider) && canConnect && !oauthData.clientId;
            const isMissingConfigId = isWhatsAppProvider && canConnect &&
                providerType === 'meta-whatsapp-coexistence' &&
                !this._getConfigurationId();

            const hasEmbeddedSignup = isWhatsAppProvider && canConnect;
            const hasSystemUserToken = isSystemUserProvider && canConnect;
            const hasFallbackConnect = !isWhatsAppProvider && !isSystemUserProvider && canConnect;

            const embeddedSignupLabel = providerType === 'meta-whatsapp-coexistence'
                ? this.translate('connectWithEmbeddedSignupCoexistence', 'labels', 'OAuthProvider')
                : this.translate('connectWithEmbeddedSignup', 'labels', 'OAuthProvider');

            // Real-time, validity-based status (computed server-side by the
            // ConnectionStatus loaders). Unlike `hasAccessToken` (mere token
            // presence) this reflects whether the token is actually usable, so
            // a dead-but-present token no longer shows a green "Connected"
            // badge. Falls back gracefully when the attribute is absent.
            const liveStatus = this.model.get('connectionStatus') || null;
            const isLiveConnected = !liveStatus || liveStatus === 'connected';

            const liveStatusStyleMap = {
                expired: 'danger',
                revoked: 'danger',
                disconnected: 'default',
                providerInactive: 'warning',
                unknown: 'default',
            };
            const liveStatusStyle = liveStatusStyleMap[liveStatus] || 'default';
            const liveStatusLabel = liveStatus
                ? this.getLanguage().translateOption(liveStatus, 'connectionStatus', 'OAuthAccount')
                : '';

            return {
                hasDisconnect,
                hasEmbeddedSignup,
                hasSystemUserToken,
                hasFallbackConnect,
                isMissingClientId,
                isMissingConfigId,
                embeddedSignupLabel,
                isLiveConnected,
                liveStatusStyle,
                liveStatusLabel,
            };
        },

        /**
         * @private
         * @return {?string} The OAuthProvider.provider enum value, read
         *   from the foreign field exposed by the WhatsApp module's
         *   OAuthAccount entityDef extension.
         */
        _getProviderType: function () {
            return this.model.get('providerType') || null;
        },

        /**
         * @private
         * @return {?string} The Facebook Login for Business configuration
         *   ID, read from the foreign field exposed by the WhatsApp module's
         *   OAuthAccount entityDef extension.
         */
        _getConfigurationId: function () {
            return this.model.get('embeddedSignupConfigurationId') || null;
        },

        /**
         * Prompt the admin to paste a Meta System User access token and send
         * it to the backend for validation + encrypted storage.
         *
         * Unlike WhatsApp/Lead Ads/Google, System User tokens are not obtained
         * through a browser authorization-code popup — an admin generates them
         * in Business Manager (or via the System Users API) and pastes them
         * here. The backend (POST /MetaSystemUserToken/set) validates the token
         * against Meta's /debug_token endpoint and stores it on the same
         * accessToken field the rest of the OAuth subsystem reads from.
         *
         * @private
         */
        actionSetSystemUserToken: function () {
            const model = this.model;

            const escapeAttr = (s) => String(s)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            const helpText = this.translate('confirmSetSystemUserToken', 'messages', 'OAuthAccount');
            const promptLabel = this.translate('systemUserTokenPrompt', 'messages', 'OAuthAccount');
            const businessLabel = this.translate('metaBusinessId', 'fields', 'OAuthAccount');
            const existingBusinessId = escapeAttr(model.get('metaBusinessId') || '');

            const body =
                '<p>' + escapeAttr(helpText) + '</p>' +
                '<div class="form-group">' +
                    '<label class="control-label">' + escapeAttr(promptLabel) + '</label>' +
                    '<textarea class="form-control" data-name="systemUserToken" rows="4" ' +
                        'style="font-family: var(--font-family-monospace);" autocomplete="off"></textarea>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label class="control-label">' + escapeAttr(businessLabel) + '</label>' +
                    '<input type="text" class="form-control" data-name="metaBusinessId" ' +
                        'value="' + existingBusinessId + '" autocomplete="off">' +
                '</div>';

            const dialog = Espo.Ui.dialog({
                backdrop: 'static',
                className: 'dialog-confirm',
                headerText: this.translate('Meta — System User Token', 'labels', 'OAuthAccount'),
                body: body,
                buttonList: [
                    {
                        name: 'save',
                        text: this.translate('Save Token', 'labels', 'OAuthAccount'),
                        style: 'danger',
                        onClick: (d) => {
                            const $el = d.$el ? d.$el : $(d.el);
                            const token = ($el.find('[data-name="systemUserToken"]').val() || '').trim();
                            const businessId = ($el.find('[data-name="metaBusinessId"]').val() || '').trim();

                            if (!token) {
                                Espo.Ui.error(
                                    this.translate('systemUserTokenEmpty', 'messages', 'OAuthAccount')
                                );

                                return;
                            }

                            this._submitSystemUserToken(token, businessId, d);
                        },
                    },
                    {
                        name: 'cancel',
                        text: this.translate('Cancel'),
                        onClick: (d) => d.close(),
                    },
                ],
            });

            dialog.show();
        },

        /**
         * @private
         */
        _submitSystemUserToken: async function (token, businessId, dialog) {
            this.inProcess = true;
            await this.reRender();
            Espo.Ui.notifyWait();

            try {
                await Espo.Ajax.postRequest('MetaSystemUserToken/set', {
                    oAuthAccountId: this.model.id,
                    token: token,
                    businessId: businessId || null,
                }).then(response => {
                    dialog.close();

                    Espo.Ui.success(
                        this.translate('systemUserTokenSet', 'messages', 'OAuthAccount')
                    );

                    if (response && response.missingScopes && response.missingScopes.length) {
                        Espo.Ui.warning(
                            this.translate('systemUserTokenMissingScopes', 'messages', 'OAuthAccount')
                                .replace('{scopes}', response.missingScopes.join(', ')),
                            { closeButton: true }
                        );
                    }
                });

                await this.model.fetch();
            } catch (xhr) {
                let errorMsg = this.translate('systemUserTokenFailed', 'messages', 'OAuthAccount');

                if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = xhr.responseJSON.error;
                } else if (xhr && xhr.getResponseHeader) {
                    const header = xhr.getResponseHeader('X-Status-Reason');

                    if (header) {
                        errorMsg = header;
                    }
                }

                Espo.Ui.error(errorMsg);
            } finally {
                this.inProcess = false;
                await this.reRender();
            }
        },

        /**
         * Lazy-load the FB JS SDK.
         *
         * @private
         * @param {string} clientId Meta App ID (OAuthProvider.clientId).
         * @return {Promise<void>}
         */
        _loadFbSdk: function (clientId) {
            if (this.fbSdkPromise) {
                return this.fbSdkPromise;
            }

            this.fbSdkPromise = new Promise((resolve, reject) => {
                if (window.FB) {
                    try {
                        window.FB.init({
                            appId: clientId,
                            version: FB_API_VERSION,
                            xfbml: false,
                            cookie: false,
                        });
                    } catch (e) {
                        // Re-init is harmless; ignore.
                    }

                    resolve();
                    return;
                }

                window.fbAsyncInit = function () {
                    try {
                        window.FB.init({
                            appId: clientId,
                            version: FB_API_VERSION,
                            xfbml: false,
                            cookie: false,
                        });
                        resolve();
                    } catch (e) {
                        reject(e);
                    }
                };

                const script = document.createElement('script');
                script.src = FB_SDK_URL;
                script.async = true;
                script.defer = true;
                script.crossOrigin = 'anonymous';
                script.onerror = () => reject(new Error('Failed to load Meta FB JS SDK.'));
                document.head.appendChild(script);
            });

            return this.fbSdkPromise;
        },

        /**
         * Attach a one-shot postMessage listener that captures
         * session_info from the Embedded Signup popup. The listener is
         * removed when the FB.login callback fires (success or failure).
         *
         * @private
         * @return {function(): void} A function to remove the listener.
         */
        _attachSessionInfoListener: function () {
            this.sessionInfo = null;
            this.finishEvent = null;

            const handler = (event) => {
                // Meta's Embedded Signup posts from facebook.com.
                if (!event.origin || event.origin.indexOf('facebook.com') === -1) {
                    return;
                }

                let data;

                try {
                    data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                } catch (e) {
                    return;
                }

                if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') {
                    return;
                }

                const payload = data.data || {};

                if (payload.event === 'FINISH' ||
                    payload.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING' ||
                    payload.event === 'FINISH_ONLY_WABA') {
                    // Capture the asset IDs and the discriminator event.
                    this.sessionInfo = payload;
                    this.finishEvent = payload.event;
                }
            };

            window.addEventListener('message', handler);

            return () => window.removeEventListener('message', handler);
        },

        /**
         * @private
         */
        async actionConnectEmbeddedSignup() {
            const oauthData = this.model.attributes.data || {};
            const clientId = oauthData.clientId;
            const providerType = this._getProviderType();
            const configurationId = this._getConfigurationId();

            if (!clientId) {
                Espo.Ui.error(this.translate('embeddedSignupMissingClientId', 'messages', 'OAuthProvider'));
                return;
            }

            const isCoexistence = providerType === 'meta-whatsapp-coexistence';

            if (isCoexistence && !configurationId) {
                Espo.Ui.error(this.translate('embeddedSignupMissingConfigId', 'messages', 'OAuthProvider'));
                return;
            }

            this.inProcess = true;
            await this.reRender();
            Espo.Ui.notifyWait();

            let removeListener = null;

            try {
                await this._loadFbSdk(clientId);

                removeListener = this._attachSessionInfoListener();

                const loginResult = await new Promise((resolve) => {
                    window.FB.login((response) => resolve(response), {
                        config_id: configurationId,
                        response_type: 'code',
                        override_default_response_type: true,
                        extras: {
                            setup: {},
                            featureType: isCoexistence ? 'whatsapp_business_app_onboarding' : '',
                            sessionInfoVersion: '3',
                        },
                    });
                });

                if (removeListener) {
                    removeListener();
                    removeListener = null;
                }

                const code = loginResult && loginResult.authResponse && loginResult.authResponse.code;

                if (!code) {
                    this.inProcess = false;
                    Espo.Ui.notify();
                    await this.reRender();

                    // User probably closed the popup.
                    return;
                }

                // Step 1: standard EspoCRM authorization-code exchange.
                try {
                    await Espo.Ajax.postRequest(`OAuth/${this.model.id}/connection`, { code });
                } catch (e) {
                    this.inProcess = false;
                    Espo.Ui.error(this.translate('Error'));
                    await this.reRender();
                    return;
                }

                // Step 2: persist session_info + run Coexistence sync (if applicable).
                if (this.sessionInfo && this.sessionInfo.waba_id && this.sessionInfo.phone_number_id) {
                    const onboardingType =
                        this.finishEvent === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'
                            ? 'coexistence'
                            : 'cloud_api';

                    try {
                        const finishResult = await Espo.Ajax.postRequest('WhatsAppEmbeddedSignup/finish', {
                            oAuthAccountId: this.model.id,
                            onboardingType,
                            wabaId: this.sessionInfo.waba_id,
                            phoneNumberId: this.sessionInfo.phone_number_id,
                            sessionInfo: this.sessionInfo,
                        });

                        if (onboardingType === 'coexistence' &&
                            finishResult && finishResult.sync &&
                            finishResult.sync.status === 'pending') {
                            Espo.Ui.warning(
                                this.translate('coexistencePendingInAppStep', 'messages', 'OAuthProvider')
                            );
                        }
                    } catch (e) {
                        // Token exchange succeeded; this is a soft failure.
                        // The job we queued server-side will retry.
                        Espo.Ui.warning(
                            this.translate('embeddedSignupFinishFailed', 'messages', 'OAuthProvider')
                        );
                    }
                } else {
                    Espo.Ui.warning(
                        this.translate('embeddedSignupNoSessionInfo', 'messages', 'OAuthProvider')
                    );
                }

                await this.model.fetch();
                Espo.Ui.notify();
            } catch (e) {
                Espo.Ui.error(e && e.message ? e.message : this.translate('Error'));
            } finally {
                if (removeListener) {
                    removeListener();
                }

                this.inProcess = false;
                await this.reRender();
            }
        },
    });
});
