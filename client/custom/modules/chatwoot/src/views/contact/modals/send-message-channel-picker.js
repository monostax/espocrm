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

        /** @type {Array} Available inboxes for new conversations */
        availableInboxes: [],

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
                hasAvailableInboxes: this.availableInboxes.length > 0,
                availableInboxes: this.availableInboxes,
                contactPhoneNumber: this.contactPhoneNumber,
                chatwootAccountName: this.chatwootAccountName,
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
            this.chatwootAccountName = this.options.chatwootAccountName || null;
            this.chatwootAccountId = this.getHelper().getAppParam("chatwootAccountId");
            this.contactPhoneNumber = this.options.contactPhoneNumber || null;

            this.channels = [];
            this.availableInboxes = [];
            this.isLoading = true;
            this.hasError = false;
            this.errorMessage = null;

            this._loadData();
        },

        /**
         * Fetch ChatwootContactInboxes, ChatwootConversations, and
         * ChatwootInbox records (for channelType from the linked
         * ChatwootInboxIntegration) in parallel, then merge client-side.
         */
        _loadData: function () {
            var contactInboxPromise = Espo.Ajax.getRequest("ChatwootContactInbox", {
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
                select: "id,inboxName,inboxChannelType,chatwootInboxId,inboxId",
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

            var chatwootInboxPromise = Espo.Ajax.getRequest("ChatwootInbox", {
                where: [
                    {
                        type: "equals",
                        attribute: "chatwootAccountId",
                        value: this.chatwootAccountEntityId,
                    },
                ],
                select: "id,channelType,name,chatwootInboxId",
                maxSize: 200,
            });

            Promise.all([contactInboxPromise, conversationPromise, chatwootInboxPromise])
                .then(
                    function (results) {
                        var contactInboxes = (results[0] && results[0].list) || [];
                        var conversations = (results[1] && results[1].list) || [];
                        var chatwootInboxes = (results[2] && results[2].list) || [];

                        this._processData(contactInboxes, conversations, chatwootInboxes);

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
         * Process raw data into the channels array used by the template.
         *
         * @param {Array} contactInboxes - ChatwootContactInbox records
         * @param {Array} conversations - ChatwootConversation records (desc by lastActivityAt)
         * @param {Array} chatwootInboxes - ChatwootInbox records (with channelType from ChatwootInboxIntegration)
         */
        _processData: function (contactInboxes, conversations, chatwootInboxes) {
            // Group conversations by contactInboxId — first one is the most recent
            var conversationMap = {};
            conversations.forEach(function (conv) {
                var key = conv.contactInboxId;
                if (key && !conversationMap[key]) {
                    conversationMap[key] = conv;
                }
            });

            // Map ChatwootInbox by id for channelType lookup
            var inboxMap = {};
            chatwootInboxes.forEach(function (cInbox) {
                inboxMap[cInbox.id] = cInbox;
            });

            this.channels = contactInboxes.map(
                function (contactInbox) {
                    var conversation = conversationMap[contactInbox.id] || null;

                    // Resolve channelType from linked ChatwootInbox
                    // (which is a foreign field from ChatwootInboxIntegration)
                    var chatwootInbox = contactInbox.inboxId ? inboxMap[contactInbox.inboxId] : null;
                    var integrationChannelType = chatwootInbox ? chatwootInbox.channelType : null;

                    var iconInfo = this._getChannelIcon(integrationChannelType);

                    return {
                        contactInboxId: contactInbox.id,
                        inboxName: contactInbox.inboxName || "Unknown Channel",
                        inboxChannelType: contactInbox.inboxChannelType,
                        channelTypeLabel: iconInfo.label,
                        svgIconUrl: iconInfo.svgIconUrl,
                        iconClass: iconInfo.iconClass,
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
                        chatwootAccountName: this.chatwootAccountName,
                    };
                }.bind(this)
            );

            // === Build available inboxes for "New Conversation" section ===

            // Build a Set of chatwootInboxId (external int) from existing ContactInboxes
            // for deduplication (Decision #15: use chatwootInboxId, not inboxId)
            var existingChatwootInboxIds = {};
            contactInboxes.forEach(function (ci) {
                if (ci.chatwootInboxId) {
                    existingChatwootInboxIds[ci.chatwootInboxId] = true;
                }
            });

            // Filter inboxes: exclude already-linked ones, V1 only WhatsApp (Decision #16)
            this.availableInboxes = chatwootInboxes
                .filter(function (inbox) {
                    // Skip inboxes already represented by a ContactInbox
                    if (inbox.chatwootInboxId && existingChatwootInboxIds[inbox.chatwootInboxId]) {
                        return false;
                    }

                    // V1: Only WhatsApp-type inboxes (Decision #16)
                    var ct = (inbox.channelType || "").toLowerCase();
                    if (!ct || (!ct.includes("whatsapp") && !ct.includes("waha"))) {
                        return false;
                    }

                    return true;
                })
                .map(
                    function (inbox) {
                        var iconInfo = this._getChannelIcon(inbox.channelType);

                        return {
                            isNewInbox: true,
                            inboxEntityId: inbox.id,
                            chatwootInboxId: inbox.chatwootInboxId,
                            inboxName: inbox.name || "Unknown Inbox",
                            channelTypeLabel: iconInfo.label,
                            svgIconUrl: iconInfo.svgIconUrl,
                            iconClass: iconInfo.iconClass,
                            hasPhoneNumber: !!this.contactPhoneNumber,
                            chatwootAccountName: this.chatwootAccountName,
                        };
                    }.bind(this)
                );
        },

        /**
         * Get the icon for a channel using the ChatwootInboxIntegration channelType.
         *
         * Uses the same resolution logic as the svg-icon-enum field view
         * on the ChatwootInbox sidepanel:
         *   1. svgIconByValue exact match (e.g. "whatsappQrcode" → "whatsapp")
         *   2. pickByContains fallback (e.g. value contains "whatsapp" → "whatsapp")
         *   3. Font Awesome fallback for unknown types
         *
         * @param {string|null} channelType - ChatwootInboxIntegration.channelType (e.g. "whatsappQrcode", "whatsappCloudApi")
         * @returns {{ svgIconUrl: string|null, iconClass: string|null, label: string }}
         */
        _getChannelIcon: function (channelType) {
            // Exact value map — mirrors entityDefs ChatwootInbox.channelType.svgIconByValue
            var svgIconByValue = {
                whatsappQrcode: "whatsapp",
                whatsappCloudApi: "whatsapp",
            };

            // Contains-based fallback — mirrors svg-icon-enum.js pickByContains
            var containsMap = {
                whatsapp: "whatsapp",
                waha: "whatsapp",
                telegram: "telegram",
                instagram: "instagram",
                facebook: "messenger",
                messenger: "messenger",
            };

            // Icon name → SVG file — mirrors SVG_ICON_FILE_MAP in svg-icon-enum.js
            var svgFileMap = {
                whatsapp: "whatsapp.svg",
                telegram: "telegram.svg",
                instagram: "instagram.svg",
                messenger: "messenger.svg",
            };

            // Font Awesome fallback for channels without SVG icons
            var faIconMap = {
                email: "fas fa-envelope",
                web: "fas fa-globe",
                sms: "fas fa-sms",
                api: "fas fa-plug",
                default: "fas fa-comment",
            };

            var value = channelType || "";
            var iconName = null;

            // Step 1: Exact match (svgIconByValue)
            iconName = svgIconByValue[value] || null;

            // Step 2: Contains-based fallback
            if (!iconName && value) {
                var normalized = value.toLowerCase();
                for (var needle in containsMap) {
                    if (normalized.includes(needle)) {
                        iconName = containsMap[needle];
                        break;
                    }
                }
            }

            // Step 3: Resolve SVG file
            var svgFile = iconName ? svgFileMap[iconName] : null;

            if (svgFile) {
                return {
                    svgIconUrl: this.getBasePath() + "client/custom/modules/global/res/icons/" + svgFile,
                    iconClass: null,
                    label: iconName,
                };
            }

            // Step 4: Font Awesome fallback
            var label = value ? value.toLowerCase() : "default";

            return {
                svgIconUrl: null,
                iconClass: faIconMap[label] || faIconMap["default"],
                label: label,
            };
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
         * Initiate a new conversation on an inbox for this Contact.
         *
         * POSTs to Contact/:id/initiateConversation with the inbox entity ID.
         * Shows a loading spinner on the inbox row during creation.
         */
        _initiateConversation: function (inboxIndex, callback) {
            var inbox = this.availableInboxes[inboxIndex];
            if (!inbox) return;

            // Show loading state on the row
            var $row = this.$el.find('.channel-item[data-inbox-index="' + inboxIndex + '"]');
            $row.addClass("is-creating");

            Espo.Ajax.postRequest(
                "Contact/" + this.contactId + "/initiateConversation",
                { inboxId: inbox.inboxEntityId }
            )
                .then(
                    function (response) {
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
                            "Failed to initiate conversation",
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
         * Handle "Open in New Tab" for a new inbox row.
         * Initiates conversation first, then opens tab.
         */
        actionOpenTabNewInbox: function (data) {
            var inboxIndex = parseInt(data.inboxIndex, 10);

            this._initiateConversation(
                inboxIndex,
                function (response) {
                    this._openInNewTab(
                        response.chatwootAccountIdExternal || this.chatwootAccountId,
                        response.chatwootConversationId
                    );
                    this.close();
                }.bind(this)
            );
        },

        /**
         * Handle "Open in Drawer" for a new inbox row.
         * Initiates conversation first, then opens drawer.
         */
        actionOpenDrawerNewInbox: function (data) {
            var inboxIndex = parseInt(data.inboxIndex, 10);

            this._initiateConversation(
                inboxIndex,
                function (response) {
                    this.close();
                    this._openInDrawer(
                        response.chatwootConversationId,
                        response.chatwootAccountIdExternal || this.chatwootAccountId,
                        response.id
                    );
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
