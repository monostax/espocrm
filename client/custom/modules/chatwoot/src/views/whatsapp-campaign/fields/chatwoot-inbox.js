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
 * Link field for WhatsAppCampaign.chatwootInbox.
 *
 * Restricts selection to active WhatsApp inboxes and, on change, derives
 * chatwootAccount / credential / wabaId / oAuthAccount / channelType from the
 * inbox's ChatwootInboxIntegration so templates and send use a single source.
 *
 * The candidate list depends on messageMode: Template mode only lists
 * template-capable (Meta Cloud API / Coexistence) inboxes, while Free Text
 * additionally lists QR Code (WAHA) inboxes, which carry no Meta identity.
 */
define('chatwoot:views/whatsapp-campaign/fields/chatwoot-inbox', ['views/fields/link'], function (Dep) {

    return Dep.extend({

        createDisabled: true,

        setup: function () {
            Dep.prototype.setup.call(this);

            // Soft-required while editable (Draft): entity required=false so legacy
            // sends/saves without an inbox column still succeed.
            if (this.model.get('status') === 'Draft' || !this.model.get('id')) {
                this.params.required = true;
            }

            this.listenTo(this.model, 'change:chatwootInboxId', () => {
                if (!this.model.hasChanged('chatwootInboxId')) {
                    return;
                }

                this.deriveFromInbox();
            });

            // Switching to Template mode can invalidate an already-picked QR
            // inbox; clear it rather than letting the save fail server-side.
            this.listenTo(this.model, 'change:messageMode', () => {
                if (
                    this.model.get('messageMode') === 'Template' &&
                    this.model.get('channelType') === 'whatsappQrcode'
                ) {
                    this.clearLink();
                }
            });
        },

        /**
         * Free text works on every WhatsApp channel; templates are Meta-only.
         */
        getSelectPrimaryFilterName: function () {
            return this.model.get('messageMode') === 'FreeText'
                ? 'whatsappAll'
                : 'whatsappCloudApi';
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (
                this.isEditMode() &&
                this.model.get('chatwootInboxId') &&
                !this.model.get('chatwootAccountId') &&
                !this._deriving
            ) {
                this.deriveFromInbox();
            }
        },

        clearLink: function () {
            Dep.prototype.clearLink.call(this);

            this.clearDerivedAttrs();
        },

        deriveFromInbox: function () {
            const inboxId = this.model.get('chatwootInboxId');

            if (!inboxId) {
                this.clearDerivedAttrs();
                return;
            }

            this._deriving = true;

            Espo.Ajax.getRequest('WhatsAppCampaign/action/resolveInbox', {
                chatwootInboxId: inboxId,
            })
                .then(result => {
                    this._deriving = false;

                    const attrs = {
                        chatwootAccountId: result.chatwootAccountId || null,
                        chatwootAccountName: result.chatwootAccountName || null,
                        credentialId: result.credentialId || null,
                        credentialName: result.credentialName || null,
                        wabaId: result.wabaId || null,
                        channelType: result.channelType || null,
                    };

                    // Transient attrs for template fetch (not storable on entity).
                    this.model.set('_oAuthAccountId', result.oAuthAccountId || null, {silent: true});
                    this.model.set('_businessAccountId', result.wabaId || null, {silent: true});

                    // A QR inbox cannot send templates: force the only mode it
                    // supports so the form can never submit an invalid pair.
                    if (!result.supportsTemplates) {
                        attrs.messageMode = 'FreeText';
                        attrs.templateName = null;
                        attrs.templateLanguage = null;
                        attrs.templateCategory = null;
                        attrs.templateBody = null;
                        attrs.parameterMapping = null;
                        attrs.headerMediaUrl = null;
                        attrs.headerMediaType = null;
                    }

                    this.model.set(attrs);

                    // Credential may be null (OAuth-only integrations). Still notify
                    // template field so it reloads via OAuth or credential path.
                    this.model.trigger('change:credentialId');
                })
                .catch(xhr => {
                    this._deriving = false;

                    let msg = 'Failed to resolve inbox.';
                    if (xhr?.responseJSON?.message) {
                        msg = xhr.responseJSON.message;
                    }

                    Espo.Ui.error(msg);
                    this.clearDerivedAttrs();
                });
        },

        clearDerivedAttrs: function () {
            this.model.set({
                chatwootAccountId: null,
                chatwootAccountName: null,
                credentialId: null,
                credentialName: null,
                wabaId: null,
                channelType: null,
                templateName: null,
                templateLanguage: null,
                templateCategory: null,
                templateBody: null,
                parameterMapping: null,
                headerMediaUrl: null,
                headerMediaType: null,
            });

            this.model.set('_oAuthAccountId', null, {silent: true});
            this.model.set('_businessAccountId', null, {silent: true});
            this.model.trigger('change:credentialId');
        },
    });
});
