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
 * Channel Picker Modal for Contact "Send Message" action.
 *
 * Displays available Chatwoot channels (ContactInboxes) for a Contact,
 * allowing the user to open an existing conversation or create a new one
 * in either a new tab (SSO-authenticated) or the iframe drawer.
 *
 * Options:
 *   - contactId: EspoCRM Contact entity ID
 *   - contactName: Contact display name
 *   - chatwootAccountEntityId: EspoCRM ChatwootAccount entity ID (for filtering)
 */
define("chatwoot:views/contact/modals/send-message-channel-picker", [
    "views/modal",
], function (Dep) {
    return Dep.extend({
        className: "dialog dialog-record",

        template: "chatwoot:contact/modals/send-message-channel-picker",

        backdrop: true,

        /** @type {Array} Processed channel list for template rendering */
        channels: [],

        /** @type {boolean} Whether data is still loading */
        isLoading: true,

        /** @type {boolean} Whether an error occurred during loading */
        hasError: false,

        /** @type {string|null} Error message to display */
        errorMessage: null,

        data: function () {
            return {
                isLoading: this.isLoading,
                hasError: this.hasError,
                errorMessage: this.errorMessage,
                hasChannels: this.channels.length > 0,
                channels: this.channels,
            };
        },

        setup: function () {
            this.headerText = this.translate("Select Channel", "labels", "Contact");

            this.buttonList = [
                {
                    name: "close",
                    label: "Close",
                },
            ];

            this.contactId = this.options.contactId;
            this.contactName = this.options.contactName;
            this.chatwootAccountEntityId = this.options.chatwootAccountEntityId;
            this.chatwootAccountId = this.getHelper().getAppParam("chatwootAccountId");

            this.channels = [];
            this.isLoading = true;
            this.hasError = false;
            this.errorMessage = null;

            this._loadData();
        },

        /**
         * Fetch ChatwootContactInboxes and ChatwootConversations in parallel,
         * then merge them client-side.
         */
        _loadData: function () {
            var inboxPromise = Espo.Ajax.getRequest("ChatwootContactInbox", {
                where: [
                    {
                        type: "equals",
                        attribute: "contactId",
                        value: this.contactId,
                    },
                    {
                        type: "equals",
                        attribute: "chatwootAccountId",
                        value: this.chatwootAccountEntityId,
                    },
                ],
                select: "id,inboxName,inboxChannelType,chatwootInboxId",
                maxSize: 200,
            });

            var conversationPromise = Espo.Ajax.getRequest("ChatwootConversation", {
                where: [
                    {
                        type: "equals",
                        attribute: "contactId",
                        value: this.contactId,
                    },
                    {
                        type: "equals",
                        attribute: "chatwootAccountId",
                        value: this.chatwootAccountEntityId,
                    },
                ],
                orderBy: "lastActivityAt",
                order: "desc",
                select: "id,chatwootConversationId,chatwootAccountIdExternal,contactInboxId",
                maxSize: 200,
            });

            Promise.all([inboxPromise, conversationPromise])
                .then(
                    function (results) {
                        var inboxResponse = results[0];
                        var conversationResponse = results[1];

                        var inboxes = inboxResponse.list || [];
                        var conversations = conversationResponse.list || [];

                        this._processData(inboxes, conversations);

                        this.isLoading = false;
                        this.reRender();
                    }.bind(this)
                )
                .catch(
                    function (xhr) {
                        this.isLoading = false;
                        this.hasError = true;

                        if (xhr && xhr.status === 403) {
                            this.errorMessage = "Insufficient permissions";
                        } else {
                            this.errorMessage = "Failed to load channels";
                        }

                        this.reRender();
                    }.bind(this)
                );
        },

        /**
         * Process raw inbox and conversation data into the channels array
         * used by the template.
         *
         * Groups conversations by contactInboxId, takes the most recent
         * (first after desc sort) per inbox.
         */
        _processData: function (inboxes, conversations) {
            // Group conversations by contactInboxId — first one is the most recent
            var conversationMap = {};
            conversations.forEach(function (conv) {
                var key = conv.contactInboxId;
                if (key && !conversationMap[key]) {
                    conversationMap[key] = conv;
                }
            });

            this.channels = inboxes.map(
                function (inbox) {
                    var conversation = conversationMap[inbox.id] || null;
                    var normalizedType = this._normalizeChannelType(
                        inbox.inboxChannelType,
                        inbox.inboxName
                    );

                    return {
                        contactInboxId: inbox.id,
                        inboxName: inbox.inboxName || "Unknown Channel",
                        inboxChannelType: inbox.inboxChannelType,
                        channelTypeLabel: normalizedType,
                        iconClass: this._getChannelIcon(normalizedType),
                        hasConversation: !!conversation,
                        chatwootConversationId: conversation
                            ? conversation.chatwootConversationId
                            : null,
                        chatwootAccountIdExternal: conversation
                            ? conversation.chatwootAccountIdExternal
                            : null,
                        conversationEntityId: conversation
                            ? conversation.id
                            : null,
                    };
                }.bind(this)
            );
        },

        /**
         * Normalize channel type to a simple lowercase key.
         * Follows the inbox.js normalizeChannelType() pattern.
         */
        _normalizeChannelType: function (channelType, inboxName) {
            if (channelType) {
                var type = channelType.toLowerCase();
                if (type.includes("whatsapp")) return "whatsapp";
                if (type.includes("telegram")) return "telegram";
                if (type.includes("instagram")) return "instagram";
                if (type.includes("facebook") || type.includes("messenger"))
                    return "facebook";
                if (type.includes("email") || type.includes("mail"))
                    return "email";
                if (
                    type.includes("web") ||
                    type.includes("widget") ||
                    type.includes("live")
                )
                    return "web";
                if (type.includes("sms") || type.includes("twilio"))
                    return "sms";
                if (type.includes("api")) return "api";
            }

            if (inboxName) {
                var name = inboxName.toLowerCase();
                if (name.includes("whatsapp")) return "whatsapp";
                if (name.includes("telegram")) return "telegram";
                if (name.includes("instagram")) return "instagram";
                if (name.includes("facebook") || name.includes("messenger"))
                    return "facebook";
                if (name.includes("email") || name.includes("mail"))
                    return "email";
                if (
                    name.includes("web") ||
                    name.includes("widget") ||
                    name.includes("live")
                )
                    return "web";
            }

            return "default";
        },

        /**
         * Get the icon class for a normalized channel type.
         * Follows the inbox.js getChannelIcon() pattern.
         */
        _getChannelIcon: function (channelType) {
            var icons = {
                whatsapp: "fab fa-whatsapp",
                telegram: "fab fa-telegram",
                instagram: "fab fa-instagram",
                facebook: "fab fa-facebook-messenger",
                email: "fas fa-envelope",
                web: "fas fa-globe",
                sms: "fas fa-sms",
                api: "fas fa-plug",
                default: "fas fa-comment",
            };
            return icons[channelType] || icons["default"];
        },

        /**
         * Get a channel object from the channels array by its template index.
         */
        _getChannelByIndex: function (index) {
            return this.channels[index] || null;
        },

        /**
         * Build the cwPath for a Chatwoot conversation URL.
         */
        _buildConversationPath: function (chatwootAccountId, chatwootConversationId) {
            return (
                "/app/accounts/" +
                chatwootAccountId +
                "/inbox-view/conversation/" +
                chatwootConversationId
            );
        },

        /**
         * Open a conversation in a new tab using the SSO-authenticated
         * #Chatwoot?cwPath= route.
         */
        _openInNewTab: function (chatwootAccountId, chatwootConversationId) {
            var cwPath = this._buildConversationPath(
                chatwootAccountId,
                chatwootConversationId
            );
            window.open("#Chatwoot?cwPath=" + cwPath, "_blank");
        },

        /**
         * Open a conversation in the iframe drawer modal.
         * Follows the kanban-item.js actionQuickView() pattern.
         */
        _openInDrawer: function (
            chatwootConversationId,
            chatwootAccountIdExternal,
            conversationEntityId
        ) {
            this.createView(
                "conversationDrawer",
                "chatwoot:views/chatwoot-conversation/modals/conversation-drawer",
                {
                    chatwootConversationId: chatwootConversationId,
                    chatwootAccountIdExternal: chatwootAccountIdExternal,
                    contactName: this.contactName,
                    recordId: conversationEntityId,
                },
                function (view) {
                    view.render();
                }
            );
        },

        /**
         * Create a new conversation for a ContactInbox, then invoke the callback
         * with the created conversation data.
         *
         * Shows a loading spinner on the channel row during creation.
         */
        _createConversation: function (index, callback) {
            var channel = this._getChannelByIndex(index);
            if (!channel) return;

            // Show loading state on the row
            var $row = this.$el.find('.channel-item[data-index="' + index + '"]');
            $row.addClass("is-creating");

            Espo.Ajax.postRequest(
                "ChatwootContactInbox/" + channel.contactInboxId + "/createConversation"
            )
                .then(
                    function (response) {
                        // Update the channel data with the new conversation
                        channel.hasConversation = true;
                        channel.chatwootConversationId =
                            response.chatwootConversationId;
                        channel.chatwootAccountIdExternal =
                            response.chatwootAccountIdExternal;
                        channel.conversationEntityId = response.id;

                        $row.removeClass("is-creating");

                        if (callback) {
                            callback(response);
                        }
                    }.bind(this)
                )
                .catch(
                    function (xhr) {
                        $row.removeClass("is-creating");

                        var errorMsg = this.translate(
                            "Failed to create conversation",
                            "labels",
                            "Contact"
                        );
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }

                        Espo.Ui.error(errorMsg);
                    }.bind(this)
                );
        },

        /**
         * Handle "Open in New Tab" action.
         * If no conversation exists, creates one first.
         */
        actionOpenTab: function (data) {
            var index = parseInt(data.index, 10);
            var channel = this._getChannelByIndex(index);
            if (!channel) return;

            if (channel.hasConversation) {
                this._openInNewTab(
                    channel.chatwootAccountIdExternal || this.chatwootAccountId,
                    channel.chatwootConversationId
                );
                this.close();
            } else {
                this._createConversation(
                    index,
                    function (response) {
                        this._openInNewTab(
                            response.chatwootAccountIdExternal || this.chatwootAccountId,
                            response.chatwootConversationId
                        );
                        this.close();
                    }.bind(this)
                );
            }
        },

        /**
         * Handle "Open in Drawer" action.
         * If no conversation exists, creates one first.
         */
        actionOpenDrawer: function (data) {
            var index = parseInt(data.index, 10);
            var channel = this._getChannelByIndex(index);
            if (!channel) return;

            if (channel.hasConversation) {
                this.close();
                this._openInDrawer(
                    channel.chatwootConversationId,
                    channel.chatwootAccountIdExternal || this.chatwootAccountId,
                    channel.conversationEntityId
                );
            } else {
                this._createConversation(
                    index,
                    function (response) {
                        this.close();
                        this._openInDrawer(
                            response.chatwootConversationId,
                            response.chatwootAccountIdExternal || this.chatwootAccountId,
                            response.id
                        );
                    }.bind(this)
                );
            }
        },

        actionClose: function () {
            this.close();
        },
    });
});
