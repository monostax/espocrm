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
 *   - contactPhoneNumber: Contact phone number (optional, for identifier checks)
 *   - contactEmailAddress: Contact email (optional, for identifier checks)
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
            this.chatwootAccountId = this.getHelper().getAppParam("chatwootAccountId");
            this.contactPhoneNumber = this.options.contactPhoneNumber || null;
            this.contactEmailAddress = this.options.contactEmailAddress || null;

            // accountId -> Set<channelType> of identities the contact already has
            this.identitiesByAccount = {};

            // Set<channelType> of identities without a ChatwootAccount link.
            // Manually-entered identities (Contact edit form) are not
            // account-scoped; the backend falls back to a tenant-scoped
            // lookup for them, so the picker must treat them as valid for
            // any inbox the user can see.
            this.identitiesTenantWide = {};

            this.channels = [];
            this.availableInboxes = [];
            this.isLoading = true;
            this.hasError = false;
            this.errorMessage = null;

            this._loadData();
        },

        /**
         * Fetch ChatwootContactInboxes, ChatwootConversations,
         * ChatwootInbox records, and the contact's ContactChannelIdentity
         * collection in parallel, then merge client-side.
         *
         * No account filter is applied — EspoCRM ACL (teams) ensures
         * the user only sees entities they have access to. This allows
         * users with memberships in multiple ChatwootAccounts to see
         * inboxes from all their accounts.
         *
         * ContactChannelIdentity is the source-of-truth for whether the
         * contact has any non-phone channel identifier (Instagram/Telegram/
         * Facebook source_id, etc.) the backend can use to initiate a new
         * conversation. Scoped per ChatwootAccount because source_ids are
         * page-/account-scoped on Chatwoot's side.
         */
        _loadData: function () {
            var contactInboxPromise = Espo.Ajax.getRequest("ChatwootContactInbox", {
                where: [
                    {
                        type: "equals",
                        attribute: "contactId",
                        value: this.contactId,
                    },
                ],
                select: "id,inboxName,inboxChannelType,chatwootInboxId,inboxId,chatwootAccountId,chatwootAccountName",
                maxSize: 200,
            });

            var conversationPromise = Espo.Ajax.getRequest("ChatwootConversation", {
                where: [
                    {
                        type: "equals",
                        attribute: "contactId",
                        value: this.contactId,
                    },
                ],
                orderBy: "lastActivityAt",
                order: "desc",
                select: "id,chatwootConversationId,chatwootAccountIdExternal,contactInboxId",
                maxSize: 200,
            });

            var chatwootInboxPromise = Espo.Ajax.getRequest("ChatwootInbox", {
                select: "id,channelType,name,chatwootInboxId,chatwootAccountId,chatwootAccountName",
                maxSize: 200,
            });

            var identityPromise = Espo.Ajax.getRequest("ContactChannelIdentity", {
                where: [
                    {
                        type: "equals",
                        attribute: "contactId",
                        value: this.contactId,
                    },
                ],
                select: "id,channelType,sourceId,chatwootAccountId,chatwootInboxId",
                maxSize: 200,
            }).catch(function () {
                // Best-effort: if ACL forbids reading the entity for this
                // user we still want the modal to function (phone-only
                // channels can be initiated regardless of identities).
                return { list: [] };
            });

            Promise.all([contactInboxPromise, conversationPromise, chatwootInboxPromise, identityPromise])
                .then(
                    function (results) {
                        var contactInboxes = (results[0] && results[0].list) || [];
                        var conversations = (results[1] && results[1].list) || [];
                        var chatwootInboxes = (results[2] && results[2].list) || [];
                        var identities = (results[3] && results[3].list) || [];

                        this._buildIdentityIndex(identities);
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
         * Build a map of chatwootAccountId -> Set of channelType strings
         * (lowercased, mapped enum form) the contact has at least one
         * identity for. Used by `_checkRequiredIdentifier` to enable a
         * "Nova Conversa" row only when the backend can plausibly reach
         * the contact on that channel within the inbox's account.
         *
         * Identities without a chatwootAccountId (e.g. entered manually
         * on the Contact edit form) are collected into a tenant-wide set
         * instead — mirroring the backend's tenant-scoped fallback in
         * `ContactChatwoot::resolveExternalContactForChannel`.
         */
        _buildIdentityIndex: function (identities) {
            var byAccount = {};
            var tenantWide = {};

            identities.forEach(function (identity) {
                var ct = (identity.channelType || "").toLowerCase();
                var accountId = identity.chatwootAccountId;
                if (!ct) {
                    return;
                }
                // Only ROUTABLE identities enable a "Nova Conversa" row.
                // For instagram/facebook/twitter the routable source_id is
                // the numeric page-scoped user id — a handle-keyed row
                // (manual entry, scoped id not yet observed) cannot
                // address an outbound message. Mirrors
                // ContactReconciler::isRoutableSourceId on the PHP side.
                if (!this._isRoutableIdentity(ct, identity.sourceId)) {
                    return;
                }
                if (!accountId) {
                    tenantWide[ct] = true;
                    return;
                }
                if (!byAccount[accountId]) {
                    byAccount[accountId] = {};
                }
                byAccount[accountId][ct] = true;
            }, this);

            this.identitiesByAccount = byAccount;
            this.identitiesTenantWide = tenantWide;
        },

        /**
         * Whether an identity's sourceId can address an outbound message
         * on its channel. Handle-channels require the numeric page-scoped
         * user id; WhatsApp LIDs are non-routable for initiation.
         */
        _isRoutableIdentity: function (channelType, sourceId) {
            var sid = (sourceId || "").toString();

            if (!sid) {
                return false;
            }

            if (["instagram", "facebook", "twitter"].indexOf(channelType) !== -1) {
                return /^\d+$/.test(sid);
            }

            if (sid.slice(-4) === "@lid") {
                return false;
            }

            return true;
        },

        /**
         * Returns true when the contact has at least one
         * ContactChannelIdentity row of `channelType` within
         * `chatwootAccountId`, or a tenant-wide (account-less)
         * identity of that channelType.
         */
        _hasIdentityFor: function (chatwootAccountId, channelType) {
            if (!channelType) {
                return false;
            }
            if (this.identitiesTenantWide[channelType]) {
                return true;
            }
            if (!chatwootAccountId) {
                return false;
            }
            var bucket = this.identitiesByAccount[chatwootAccountId];
            return !!(bucket && bucket[channelType]);
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
                        chatwootAccountName: contactInbox.chatwootAccountName || null,
                    };
                }.bind(this)
            );

            // === Build available inboxes for "New Conversation" section ===

            // Build a Set of (chatwootInboxId + chatwootAccountId) from existing
            // ContactInboxes for deduplication. Using composite key because the
            // same external inbox ID could theoretically exist across accounts.
            var existingInboxKeys = {};
            contactInboxes.forEach(function (ci) {
                if (ci.chatwootInboxId && ci.chatwootAccountId) {
                    existingInboxKeys[ci.chatwootInboxId + ":" + ci.chatwootAccountId] = true;
                }
            });

            // Filter inboxes: exclude already-linked ones.
            // All remaining inboxes are shown. Inboxes whose required identifier
            // is missing on the Contact are rendered in a disabled state with a hint.
            this.availableInboxes = chatwootInboxes
                .filter(function (inbox) {
                    // Skip inboxes already represented by a ContactInbox
                    var key = inbox.chatwootInboxId + ":" + inbox.chatwootAccountId;
                    if (existingInboxKeys[key]) {
                        return false;
                    }

                    // Must have a known channelType
                    if (!inbox.channelType) {
                        return false;
                    }

                    return true;
                })
                .map(
                    function (inbox) {
                        var iconInfo = this._getChannelIcon(inbox.channelType);
                        var identifierCheck = this._checkRequiredIdentifier(inbox);

                        return {
                            isNewInbox: true,
                            inboxEntityId: inbox.id,
                            chatwootInboxId: inbox.chatwootInboxId,
                            inboxName: inbox.name || "Unknown Inbox",
                            channelTypeLabel: iconInfo.label,
                            svgIconUrl: iconInfo.svgIconUrl,
                            iconClass: iconInfo.iconClass,
                            hasRequiredIdentifier: identifierCheck.hasIdentifier,
                            missingIdentifierHint: identifierCheck.hint,
                            chatwootAccountName: inbox.chatwootAccountName || null,
                        };
                    }.bind(this)
                );
        },

        /**
         * Check whether the current contact has the required identifier
         * to initiate a *new* conversation on `inbox`.
         *
         * The semantics are channel-family-dependent:
         *
         * - whatsapp / waha / sms: requires `Contact.phoneNumber`. The
         *   backend will normalize it (E.164) and Chatwoot creates the
         *   contact_inbox row with the phone as source_id.
         *
         * - email: requires `Contact.emailAddress`.
         *
         * - instagram / telegram / facebook / messenger / line / viber:
         *   requires a pre-existing `ContactChannelIdentity` of matching
         *   channelType within the inbox's ChatwootAccount. These channels
         *   cannot be cold-DMed — the contact must already have a
         *   source_id we materialized from a prior webhook/sync. The
         *   backend re-uses that source_id to create the contact_inbox
         *   on Chatwoot and then opens the conversation.
         *
         * - everything else (web_widget, api, twitter, …): unsupported
         *   for new outbound conversations.
         *
         * @param {Object} inbox - a row from `ChatwootInbox` list
         * @returns {{ hasIdentifier: boolean, hint: string|null }}
         */
        _checkRequiredIdentifier: function (inbox) {
            var ct = (inbox.channelType || "").toLowerCase();
            var mapped = this._normalizeChannelType(ct);
            var accountId = inbox.chatwootAccountId || null;

            // Phone-based channels
            if (mapped === "whatsapp" || mapped === "sms" || ct.includes("waha")) {
                if (this.contactPhoneNumber) {
                    return { hasIdentifier: true, hint: null };
                }
                // LID-era: a contact whose WhatsApp chat is keyed by a LID
                // (privacy identifier) may have no phone number, but the
                // backend can still reuse the linked Chatwoot contact via
                // its whatsapp ContactChannelIdentity.
                if (this._hasIdentityFor(accountId, "whatsapp")) {
                    return { hasIdentifier: true, hint: null };
                }
                return {
                    hasIdentifier: false,
                    hint: this.translate("Contact has no phone number", "labels", "Contact"),
                };
            }

            // Email channels
            if (mapped === "email") {
                if (this.contactEmailAddress) {
                    return { hasIdentifier: true, hint: null };
                }
                return {
                    hasIdentifier: false,
                    hint: this.translate("Contact has no email address", "labels", "Contact"),
                };
            }

            // Social channels — require a pre-existing identity scoped to the inbox's account.
            var socialChannels = ["instagram", "telegram", "facebook", "line", "viber"];
            if (mapped && socialChannels.indexOf(mapped) !== -1) {
                if (this._hasIdentityFor(accountId, mapped)) {
                    return { hasIdentifier: true, hint: null };
                }

                var capitalized = mapped.charAt(0).toUpperCase() + mapped.slice(1);
                var hintKey = "Contact has no " + capitalized + " identifier";
                return {
                    hasIdentifier: false,
                    hint: this.translate(hintKey, "labels", "Contact"),
                };
            }

            // Unknown channel types — disable with generic hint
            return {
                hasIdentifier: false,
                hint: this.translate("Channel not supported for new conversations", "labels", "Contact"),
            };
        },

        /**
         * Normalize a raw `ChatwootInbox.channelType` value (which can be
         * either a Chatwoot raw form like "Channel::Instagram" or a
         * vendor-specific tag like "whatsappQrcode") to the enum used by
         * ContactChannelIdentity.channelType. Mirrors
         * `ContactReconciler::mapChannelType` on the PHP side.
         */
        _normalizeChannelType: function (channelType) {
            var ct = (channelType || "").toLowerCase();
            if (!ct) {
                return null;
            }
            if (ct.indexOf("whatsapp") !== -1 || ct.indexOf("waha") !== -1) {
                return "whatsapp";
            }
            if (ct.indexOf("instagram") !== -1) {
                return "instagram";
            }
            if (ct.indexOf("telegram") !== -1) {
                return "telegram";
            }
            if (ct.indexOf("messenger") !== -1 || ct.indexOf("facebook") !== -1) {
                return "facebook";
            }
            if (ct.indexOf("line") !== -1) {
                return "line";
            }
            if (ct.indexOf("viber") !== -1) {
                return "viber";
            }
            if (ct.indexOf("sms") !== -1) {
                return "sms";
            }
            if (ct.indexOf("email") !== -1) {
                return "email";
            }
            if (ct.indexOf("twitter") !== -1) {
                return "twitter";
            }
            if (ct.indexOf("webwidget") !== -1 || ct.indexOf("web_widget") !== -1) {
                return "web_widget";
            }
            if (ct.indexOf("api") !== -1) {
                return "api";
            }
            return null;
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
                whatsappCoexistence: "whatsapp",
                instagram: "instagram",
                email: "mail",
                google: "google",
                microsoft: "outlook",
                outlook: "outlook",
                website: "website",
                "Channel::WebWidget": "website",
                web_widget: "website",
                "Channel::Email": "mail",
            };

            // Contains-based fallback — mirrors svg-icon-enum.js pickByContains
            var containsMap = {
                whatsapp: "whatsapp",
                waha: "whatsapp",
                telegram: "telegram",
                instagram: "instagram",
                facebook: "messenger",
                messenger: "messenger",
                webwidget: "website",
                web_widget: "website",
                website: "website",
                gmail: "google",
                google: "google",
                outlook: "outlook",
                microsoft: "outlook",
                email: "mail",
                mail: "mail",
            };

            // Icon name → SVG file — mirrors SVG_ICON_FILE_MAP in svg-icon-enum.js
            var svgFileMap = {
                whatsapp: "whatsapp.svg",
                telegram: "telegram.svg",
                instagram: "instagram.svg",
                messenger: "messenger.svg",
                mail: "mail.svg",
                website: "website.svg",
                google: "google.svg",
                outlook: "outlook.svg",
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
