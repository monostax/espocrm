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
    const FB_SDK_URL = 'https://connect.facebook.net/en_US/sdk.js';
    const FB_API_VERSION = 'v22.0';

    return Dep.extend({

        // language=Handlebars
        templateContent: `
            {{#if hasDisconnect}}
                <div class="margin-bottom">
                    <span class="label label-success label-md">{{translate 'Connected' scope='ExternalAccount'}}</span>
                </div>
                <button class="btn btn-default" data-action="disconnect">{{translate 'Disconnect' scope='ExternalAccount'}}</button>
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
        },

        data: function () {
            const isSet = this.model.attributes.hasAccessToken !== undefined;
            const providerType = this._getProviderType();
            const isWhatsAppProvider = WHATSAPP_PROVIDERS.indexOf(providerType) !== -1;

            const hasDisconnect = !this.inProcess && isSet && this.model.attributes.hasAccessToken;

            const canConnect = !this.inProcess
                && isSet
                && !this.model.attributes.hasAccessToken
                && this.model.attributes.providerIsActive;

            const oauthData = this.model.attributes.data || {};

            const isMissingClientId = isWhatsAppProvider && canConnect && !oauthData.clientId;
            const isMissingConfigId = isWhatsAppProvider && canConnect &&
                providerType === 'meta-whatsapp-coexistence' &&
                !this._getConfigurationId();

            const hasEmbeddedSignup = isWhatsAppProvider && canConnect;
            const hasFallbackConnect = !isWhatsAppProvider && canConnect;

            const embeddedSignupLabel = providerType === 'meta-whatsapp-coexistence'
                ? this.translate('connectWithEmbeddedSignupCoexistence', 'labels', 'OAuthProvider')
                : this.translate('connectWithEmbeddedSignup', 'labels', 'OAuthProvider');

            return {
                hasDisconnect,
                hasEmbeddedSignup,
                hasFallbackConnect,
                isMissingClientId,
                isMissingConfigId,
                embeddedSignupLabel,
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
                            version: 'v3',
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
