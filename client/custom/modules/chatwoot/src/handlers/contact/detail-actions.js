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
 * Detail action handler for Contact.
 *
 * Provides "Send Message" button that opens a channel picker modal
 * for sending messages via Chatwoot channels linked to the contact.
 */
define('chatwoot:handlers/contact/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        /**
         * Send Message is available when the current user has a chatSsoUrl
         * (meaning they have a linked ChatwootUser with valid SSO).
         * This is synchronous — no API call needed.
         */
        isSendMessageAvailable() {
            return !!this.view.getHelper().getAppParam('chatSsoUrl');
        }

        /**
         * Opens the channel picker modal for sending messages.
         *
         * @param {Object} data - data-* attributes from the DOM element
         * @param {Event} event - the DOM click event
         */
        sendMessage(data, event) {
            const chatwootAccountEntityId = this.view.getHelper().getAppParam('chatwootAccountEntityId');
            const chatwootAccountName = this.view.getHelper().getAppParam('chatwootAccountName');

            if (!chatwootAccountEntityId) {
                Espo.Ui.error(
                    this.view.translate('No channels available', 'labels', 'Contact')
                );
                return;
            }

            this.view.createView(
                'sendMessageChannelPicker',
                'chatwoot:views/contact/modals/send-message-channel-picker',
                {
                    contactId: this.view.model.id,
                    contactName: this.view.model.get('name'),
                    chatwootAccountEntityId: chatwootAccountEntityId,
                    chatwootAccountName: chatwootAccountName,
                    contactPhoneNumber: this.view.model.get('phoneNumber'),
                },
                (view) => {
                    view.render();
                }
            );
        }
    };
});
