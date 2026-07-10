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
 * Restricts selection to active Meta Cloud API (and Coexistence) inboxes and,
 * on change, derives chatwootAccount / credential / wabaId / oAuthAccount
 * from the inbox's ChatwootInboxIntegration so templates and send use a single source.
 */
define('chatwoot:views/whatsapp-campaign/fields/chatwoot-inbox', ['views/fields/link'], function (Dep) {

    return Dep.extend({

        selectPrimaryFilterName: 'whatsappCloudApi',
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
                    };

                    // Transient attrs for template fetch (not storable on entity).
                    this.model.set('_oAuthAccountId', result.oAuthAccountId || null, {silent: true});
                    this.model.set('_businessAccountId', result.wabaId || null, {silent: true});

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
