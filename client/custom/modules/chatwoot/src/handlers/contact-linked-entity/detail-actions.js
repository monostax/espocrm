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
 * Detail action handler for entities linked to Contact.
 *
 * Provides "Send Message" button that opens a channel picker modal
 * for sending messages via Chatwoot channels linked to the related contact.
 */
define('chatwoot:handlers/contact-linked-entity/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        /**
         * Send Message is available when:
         * 1) User has Chatwoot access (chatSsoUrl),
         * 2) Current record has a linked Contact,
         * 3) User can read Contact.
         */
        isSendMessageAvailable() {
            const hasChatAccess = !!this.view.getHelper().getAppParam('chatSsoUrl');
            const contactId = this.view.model.get('contactId');
            const canReadContact = this.view.getAcl().check('Contact', 'read');

            return hasChatAccess && !!contactId && canReadContact;
        }

        /**
         * Opens the channel picker modal for the linked Contact.
         *
         * @param {Object} data - data-* attributes from the DOM element
         * @param {Event} event - the DOM click event
         */
        async sendMessage(data, event) {
            const chatwootAccountEntityId = this.view.getHelper().getAppParam('chatwootAccountEntityId');
            const contactId = this.view.model.get('contactId');
            const contactName = this.view.model.get('contactName');

            if (!chatwootAccountEntityId) {
                Espo.Ui.error(
                    this.view.translate('No channels available', 'labels', 'Contact')
                );
                return;
            }

            if (!contactId) {
                return;
            }

            let contactPhoneNumber = null;

            try {
                const contact = await Espo.Ajax.getRequest('Contact/' + contactId);

                contactPhoneNumber = contact.phoneNumber || null;
            } catch (e) {
                contactPhoneNumber = null;
            }

            this.view.createView(
                'sendMessageChannelPicker',
                'chatwoot:views/contact/modals/send-message-channel-picker',
                {
                    contactId: contactId,
                    contactName: contactName,
                    chatwootAccountEntityId: chatwootAccountEntityId,
                    contactPhoneNumber: contactPhoneNumber,
                },
                (view) => {
                    view.render();
                }
            );
        }
    };
});
