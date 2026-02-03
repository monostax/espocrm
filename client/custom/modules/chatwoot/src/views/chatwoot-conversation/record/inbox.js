/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("chatwoot:views/chatwoot-conversation/record/inbox", [
    "views/record/list",
    "search-manager",
], function (Dep, SearchManager) {
    return Dep.extend({
        template: "chatwoot:chatwoot-conversation/record/inbox",

        // Session storage key for tracking SSO auth state
        CHATWOOT_SSO_AUTH_KEY: "chatwoot_sso_authenticated",

        // Disable standard list features we don't need
        checkboxes: false,
        rowActionsDisabled: true,
        showMore: false,
        massActionsDisabled: true,
        headerDisabled: true,
        pagination: false,

        // Disable features that require layouts
        _internalLayout: null,
        listLayout: [],
        settingsEnabled: false,
        columnResize: false,
        header: false,
        selectable: false,
        buttonsDisabled: true,

        // Currently selected conversation ID
        selectedConversationId: null,

        // Active tab in right panel
        activeTab: "chat",

        events: {
            "click .inbox-conversation-item": function (e) {
                e.stopPropagation();
                e.preventDefault();
                const $item = $(e.currentTarget);
                const id = $item.data("id");
                this.selectConversation(id);
            },
            "click .inbox-tab": function (e) {
                e.preventDefault();
                const tab = $(e.currentTarget).data("tab");
                this.switchTab(tab);
            },
            "click .btn-create-opportunity": function (e) {
                e.preventDefault();
                this.actionCreateOpportunity();
            },
            "click .btn-create-appointment": function (e) {
                e.preventDefault();
                this.actionCreateAppointment();
            },
            "click .btn-create-task": function (e) {
                e.preventDefault();
                this.actionCreateTask();
            },
            "click .btn-create-case": function (e) {
                e.preventDefault();
                this.actionCreateCase();
            },
            'click [data-action="changeStatus"]': function (e) {
                e.preventDefault();
                const status = $(e.currentTarget).data("status");
                this.actionChangeStatus(status);
            },
            'click [data-action="changeAgent"]': function (e) {
                e.preventDefault();
                const agentId = $(e.currentTarget).data("agent-id");
                const agentName = $(e.currentTarget).data("agent-name");
                this.actionChangeAgent(agentId, agentName);
            },
            "click .inbox-agent-dropdown .dropdown-toggle": function (e) {
                // Load agents when dropdown is opened
                this.loadAgentsForDropdown();
            },
            'click [data-action="viewConversation"]': function (e) {
                e.preventDefault();
                this.actionViewConversation();
            },
            'click [data-action="removeConversation"]': function (e) {
                e.preventDefault();
                this.actionRemoveConversation();
            },
        },

        /**
         * Override to specify which attributes to fetch from server.
         * The parent class builds this from layout, but we need custom fields.
         */
        getSelectAttributeList: function (callback) {
            const attributeList = [
                "id",
                "name",
                "status",
                "contactDisplayName",
                "contactAvatarUrl",
                "contactId",
                "lastActivityAt",
                "lastMessageContent",
                "lastMessageType",
                "inboxName",
                "inboxChannelType",
                "chatwootConversationId",
                "chatwootAccountId",
                "chatwootAccountIdExternal",
                "messagesCount",
                "assigneeId",
                "assigneeName",
            ];

            if (callback) {
                callback(attributeList);
            }

            return Promise.resolve(attributeList);
        },

        data: function () {
            // Don't call parent's data() - we use a custom template that doesn't need it
            const conversations = this.getConversationListData();

            return {
                conversations: conversations,
                hasConversations: conversations.length > 0,
                conversationCount: conversations.length,
                selectedConversationId: this.selectedConversationId,
                chatwootUrl: this.chatwootUrl || "",
                hasSelectedConversation: !!this.selectedConversationId,
                noSelectionMessage: this.translate(
                    "Select a conversation to view",
                    "messages",
                    "ChatwootConversation",
                ),
                noConversationsMessage: this.translate(
                    "No conversations",
                    "messages",
                    "ChatwootConversation",
                ),
                conversationsLabel: this.translate(
                    "Conversations",
                    "labels",
                    "ChatwootConversation",
                ),
            };
        },

        // WebSocket debounce interval (ms)
        webSocketDebounceInterval: 500,

        setup: function () {
            Dep.prototype.setup.call(this);

            // Get Chatwoot params
            this.chatSsoUrl = this.getHelper().getAppParam("chatSsoUrl");
            this.chatwootBaseUrl = this.getHelper().getAppParam(
                "chatwootFrontendUrl",
            );

            // Get WebSocket manager
            this.webSocketManager = this.getHelper().webSocketManager;

            // Listen for collection changes to re-render
            this.listenTo(this.collection, "sync", function () {
                if (this.isRendered()) {
                    this.reRender();
                }
            });

            // Setup WebSocket for real-time updates
            this.setupWebSocket();

            // Setup message listener for Chatwoot iframe communication
            this.setupChatwootMessageListener();
        },

        /**
         * Setup listener for messages from Chatwoot iframe
         */
        setupChatwootMessageListener: function () {
            this._chatwootMessageHandler = (event) => {
                // Verify origin
                if (
                    this.chatwootBaseUrl &&
                    !event.origin.includes(
                        new URL(this.chatwootBaseUrl).hostname.replace(
                            "chat.",
                            "",
                        ),
                    )
                ) {
                    return;
                }

                if (!event.data || !event.data.type) return;

                // Handle request for intended URL
                if (event.data.type === "CHATWOOT_REQUEST_INTENDED_URL") {
                    console.log(
                        "Inbox: Received CHATWOOT_REQUEST_INTENDED_URL from Chatwoot",
                    );

                    // Check for backup intended URL
                    const backupUrl = localStorage.getItem(
                        "chatwoot_intended_url_backup",
                    );
                    if (backupUrl) {
                        console.log(
                            "Inbox: Sending intended URL to Chatwoot:",
                            backupUrl,
                        );

                        const $iframe = this.$el.find(".inbox-iframe")[0];
                        if ($iframe && $iframe.contentWindow) {
                            try {
                                $iframe.contentWindow.postMessage(
                                    {
                                        type: "PARENT_INTENDED_URL",
                                        path: backupUrl,
                                        timestamp: Date.now(),
                                    },
                                    this.chatwootBaseUrl,
                                );
                            } catch (e) {
                                console.error(
                                    "Inbox: Failed to send intended URL:",
                                    e,
                                );
                            }
                        }

                        // Clear the backup
                        localStorage.removeItem("chatwoot_intended_url_backup");
                    }
                }
            };

            window.addEventListener("message", this._chatwootMessageHandler);

            this.once("remove", () => {
                if (this._chatwootMessageHandler) {
                    window.removeEventListener(
                        "message",
                        this._chatwootMessageHandler,
                    );
                }
            });
        },

        /**
         * Setup WebSocket subscription for real-time updates
         */
        setupWebSocket: function () {
            if (!this.webSocketManager || !this.webSocketManager.isEnabled()) {
                console.log("Inbox: WebSocket not available or not enabled");
                return;
            }

            this._webSocketDebounceTimeout = null;

            // Subscribe to chatwootConversationUpdate topic
            this.webSocketManager.subscribe(
                "chatwootConversationUpdate",
                (topic, data) => {
                    console.log(
                        "Inbox: Received chatwootConversationUpdate",
                        data,
                    );
                    this.handleWebSocketUpdate(data);
                },
            );

            // Subscribe to generic recordUpdate for ChatwootConversation
            this.webSocketManager.subscribe(
                "recordUpdate.ChatwootConversation",
                (topic, data) => {
                    console.log(
                        "Inbox: Received recordUpdate.ChatwootConversation",
                        data,
                    );
                    this.handleWebSocketUpdate(data);
                },
            );

            this.isWebSocketSubscribed = true;
            console.log("Inbox: WebSocket subscriptions active");
        },

        /**
         * Handle WebSocket update with debouncing
         */
        handleWebSocketUpdate: function (data) {
            // Debounce to prevent multiple rapid refreshes
            if (this._webSocketDebounceTimeout) {
                clearTimeout(this._webSocketDebounceTimeout);
            }

            this._webSocketDebounceTimeout = setTimeout(() => {
                this.refreshList();
            }, this.webSocketDebounceInterval);
        },

        /**
         * Refresh the conversation list
         */
        refreshList: function () {
            console.log("Inbox: Refreshing list via WebSocket update");

            const previousSelectedId = this.selectedConversationId;

            this.collection
                .fetch({
                    reset: true,
                })
                .then(() => {
                    // Check if the previously selected conversation is still in the list
                    if (previousSelectedId) {
                        const stillExists =
                            this.collection.get(previousSelectedId);

                        if (!stillExists) {
                            // Selected conversation is no longer in the list (status changed)
                            // Select the first available conversation
                            this.selectedConversationId = null;

                            if (this.collection.length > 0) {
                                const firstModel = this.collection.at(0);
                                this.selectConversation(firstModel.id);
                            } else {
                                // No conversations left, show placeholder
                                this.clearConversationView();
                            }
                        }
                    }
                });
        },

        /**
         * Change the status of the selected conversation
         */
        actionChangeStatus: function (status) {
            const model = this.getSelectedModel();
            if (!model) return;

            const currentStatus = model.get("status");
            if (currentStatus === status) return;

            Espo.Ui.notify(this.translate("Saving..."));

            model
                .save({ status: status }, { patch: true })
                .then(() => {
                    Espo.Ui.success(this.translate("Saved"));

                    // Update the status label in toolbar
                    this.updateStatusLabel(status);

                    // The WebSocket will trigger a list refresh
                    // which will handle if the conversation moves out of the current filter
                })
                .catch(() => {
                    Espo.Ui.error(this.translate("Error"));
                });
        },

        /**
         * Update the status label in the toolbar
         */
        updateStatusLabel: function (status) {
            const label = this.translateOption(
                status,
                "status",
                "ChatwootConversation",
            );
            const $btn = this.$el.find(
                ".inbox-status-dropdown .inbox-status-label",
            );
            $btn.html(
                '<span class="inbox-status-indicator status-' +
                    status +
                    '"></span>' +
                    label,
            );
        },

        /**
         * Show/hide the chat toolbar
         */
        updateChatToolbar: function (show, status, assigneeName) {
            const $toolbar = this.$el.find(".inbox-chat-toolbar");

            if (show) {
                $toolbar.addClass("visible");
                this.updateStatusLabel(status);
                this.updateAgentLabel(assigneeName);
            } else {
                $toolbar.removeClass("visible");
            }
        },

        /**
         * Update the agent label in the toolbar
         */
        updateAgentLabel: function (agentName) {
            const $label = this.$el.find(
                ".inbox-agent-dropdown .inbox-agent-label",
            );
            if (agentName) {
                $label.text(agentName);
            } else {
                $label.text(
                    this.translate(
                        "Unassigned",
                        "labels",
                        "ChatwootConversation",
                    ),
                );
            }
        },

        /**
         * Load agents for the dropdown when it's opened
         */
        loadAgentsForDropdown: function () {
            const model = this.getSelectedModel();
            if (!model) return;

            const $menu = this.$el.find(".inbox-agent-menu");
            const currentAssigneeId = model.get("assigneeId");

            // Show loading state
            $menu.html(
                '<li class="inbox-agent-loading"><a role="button"><i class="fas fa-spinner fa-spin"></i> ' +
                    this.translate("Loading...", "messages") +
                    "</a></li>",
            );

            // Fetch agents from server
            const url =
                "ChatwootConversation/action/agentsForAssignment?id=" +
                model.id;

            Espo.Ajax.getRequest(url)
                .then((response) => {
                    this.populateAgentDropdown(
                        response.list || [],
                        currentAssigneeId,
                    );
                })
                .catch(() => {
                    $menu.html(
                        '<li><a role="button" class="text-danger">' +
                            this.translate("Error") +
                            "</a></li>",
                    );
                });
        },

        /**
         * Populate the agent dropdown with available agents
         */
        populateAgentDropdown: function (agents, currentAssigneeId) {
            const $menu = this.$el.find(".inbox-agent-menu");
            let html = "";

            // Add "Unassigned" option
            const unassignedClass = !currentAssigneeId ? "active" : "";
            html +=
                '<li><a role="button" class="action ' +
                unassignedClass +
                '" data-action="changeAgent" data-agent-id="" data-agent-name="">';
            html += '<span class="agent-availability offline"></span>';
            html +=
                '<span class="agent-name">' +
                this.translate("Unassigned", "labels", "ChatwootConversation") +
                "</span>";
            html += "</a></li>";

            if (agents.length > 0) {
                html += '<li class="divider"></li>';
            }

            // Add agents
            agents.forEach((agent) => {
                const isActive = agent.id === currentAssigneeId;
                const activeClass = isActive ? "active" : "";
                const displayName = agent.availableName || agent.name;
                const status = agent.availabilityStatus || "offline";

                html +=
                    '<li><a role="button" class="action ' +
                    activeClass +
                    '" data-action="changeAgent" data-agent-id="' +
                    agent.id +
                    '" data-agent-name="' +
                    this.escapeHtml(displayName) +
                    '">';
                html +=
                    '<span class="agent-availability ' + status + '"></span>';
                html +=
                    '<span class="agent-name">' +
                    this.escapeHtml(displayName) +
                    "</span>";
                if (agent.role === "administrator") {
                    html += '<span class="agent-role">Admin</span>';
                }
                html += "</a></li>";
            });

            $menu.html(html);
        },

        /**
         * Escape HTML special characters
         */
        escapeHtml: function (text) {
            if (!text) return "";
            const div = document.createElement("div");
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Change the assigned agent for the selected conversation
         */
        actionChangeAgent: function (agentId, agentName) {
            const model = this.getSelectedModel();
            if (!model) return;

            const currentAssigneeId = model.get("assigneeId");
            const newAssigneeId = agentId ? parseInt(agentId, 10) : null;

            // Skip if same agent
            if (currentAssigneeId === newAssigneeId) return;

            Espo.Ui.notify(this.translate("Saving..."));

            model
                .save(
                    {
                        assigneeId: newAssigneeId,
                        assigneeName: agentName || null,
                    },
                    { patch: true },
                )
                .then(() => {
                    Espo.Ui.success(this.translate("Saved"));
                    this.updateAgentLabel(agentName);
                })
                .catch((error) => {
                    Espo.Ui.error(this.translate("Error"));
                });
        },

        /**
         * Clear the conversation view when no conversation is selected
         */
        clearConversationView: function () {
            this.selectedConversationId = null;

            // Clear entity list views
            this.clearView("opportunitiesList");
            this.clearView("opportunitiesList");
            this.clearView("appointmentsList");
            this.clearView("tasksList");
            this.clearView("opportunitiesSearch");
            this.clearView("appointmentsSearch");
            this.clearView("tasksSearch");
            this.currentListModelId = {};

            // Reset tab counts
            this.setTabCount("opportunities", 0);
            this.setTabCount("opportunities", 0);
            this.setTabCount("appointments", 0);
            this.setTabCount("cases", 0);
            this.setTabCount("tasks", 0);

            // Hide chat toolbar
            this.updateChatToolbar(false);

            // Hide iframe and show placeholder
            this.$el.find(".inbox-iframe").hide().attr("src", "");
            this.$el.find(".inbox-iframe-placeholder").show();

            // Remove selection from list
            this.$el.find(".inbox-conversation-item").removeClass("selected");

            // Switch to chat tab
            this.switchTab("chat");
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            // Auto-select first conversation if available and none selected
            if (this.collection.length > 0 && !this.selectedConversationId) {
                const firstModel = this.collection.at(0);
                if (firstModel) {
                    // Use setTimeout to ensure DOM is ready
                    setTimeout(() => {
                        this.selectConversation(firstModel.id);
                    }, 100);
                }
            }

            // Update iframe height
            this.updateHeight();
            $(window).on("resize.inboxView" + this.cid, () =>
                this.updateHeight(),
            );
        },

        /**
         * Update the view height to fill available space
         */
        updateHeight: function () {
            const $container = this.$el.find(".inbox-container");
            if (!$container.length) return;

            const headerHeight = $("#navbar").outerHeight() || 0;
            const searchHeight = $(".search-container").outerHeight() || 0;
            const padding = 30;
            const availableHeight =
                $(window).height() - headerHeight - searchHeight - padding;

            $container.css("height", Math.max(400, availableHeight) + "px");
        },

        /**
         * Get conversation list data for rendering
         */
        getConversationListData: function () {
            if (!this.collection || !this.collection.models) return [];

            const list = [];

            this.collection.forEach((model) => {
                const contactName =
                    model.get("contactDisplayName") ||
                    model.get("name") ||
                    "Unknown";
                const initials = this.getInitials(contactName);
                const inboxName = model.get("inboxName") || "";
                const channelType = this.normalizeChannelType(
                    model.get("inboxChannelType"),
                    inboxName,
                );
                const lastMessageContent =
                    model.get("lastMessageContent") || "";
                const lastMessageType = model.get("lastMessageType") || "";
                const status = model.get("status") || "open";

                list.push({
                    id: model.id,
                    contactName: contactName,
                    initials: initials,
                    initialsColor: this.getColorForName(contactName),
                    hasAvatar: !!model.get("contactAvatarUrl"),
                    avatarUrl: model.get("contactAvatarUrl"),
                    channelType: channelType,
                    channelIcon: this.getChannelIcon(channelType),
                    hasMessage: !!lastMessageContent,
                    messagePreview: this.truncateMessage(
                        lastMessageContent,
                        60,
                    ),
                    lastMessageType: lastMessageType,
                    isIncoming: lastMessageType === "incoming",
                    timeAgo: this.getTimeAgo(model.get("lastActivityAt")),
                    status: status,
                    statusLabel: this.translateOption(
                        status,
                        "status",
                        "ChatwootConversation",
                    ),
                    inboxName: model.get("inboxName"),
                    chatwootConversationId: model.get("chatwootConversationId"),
                    isSelected: model.id === this.selectedConversationId,
                });
            });

            return list;
        },

        /**
         * Select a conversation and load it in the iframe
         */
        selectConversation: function (id) {
            if (this.selectedConversationId === id) return;

            this.selectedConversationId = id;

            // Clear entity list views (they'll be re-created when tab is opened)
            this.clearView("opportunitiesList");
            this.clearView("appointmentsList");
            this.clearView("casesList");
            this.clearView("tasksList");
            this.currentListModelId = {};

            // Update visual selection
            this.$el.find(".inbox-conversation-item").removeClass("selected");
            this.$el
                .find('.inbox-conversation-item[data-id="' + id + '"]')
                .addClass("selected");

            // Get the model and load the conversation
            const model = this.collection.get(id);
            if (model) {
                this.loadConversationInIframe(model);

                // Update chat toolbar with current status and assignee
                this.updateChatToolbar(
                    true,
                    model.get("status"),
                    model.get("assigneeName"),
                );

                // Update tab counts
                this.updateTabCounts(model);

                // If not on chat tab, refresh the current tab's list
                if (this.activeTab === "opportunities") {
                    this.renderEntityListView("opportunities", "Opportunity");
                } else if (this.activeTab === "appointments") {
                    this.renderEntityListView("appointments", "Appointment");
                } else if (this.activeTab === "tasks") {
                    this.renderEntityListView("tasks", "Task");
                } else if (this.activeTab === "cases") {
                    this.renderEntityListView("cases", "Case");
                }
            }
        },

        /**
         * Update the record count badges on tabs
         */
        updateTabCounts: function (model) {
            // Fetch counts for opportunities
            this.fetchRelatedCount(
                "opportunities",
                "Opportunity",
                model.id,
                (count) => {
                    this.setTabCount("opportunities", count);
                },
            );

            // Fetch counts for appointments
            this.fetchRelatedCount(
                "appointments",
                "Appointment",
                model.id,
                (count) => {
                    this.setTabCount("appointments", count);
                },
            );

            // Fetch counts for tasks
            this.fetchRelatedCount("tasks", "Task", model.id, (count) => {
                this.setTabCount("tasks", count);
            });

            // Fetch counts for cases
            this.fetchRelatedCount("cases", "Case", model.id, (count) => {
                this.setTabCount("cases", count);
            });
        },

        /**
         * Fetch count of related records
         */
        fetchRelatedCount: function (link, scope, modelId, callback) {
            const url =
                "ChatwootConversation/" + modelId + "/" + link + "?maxSize=0";

            Espo.Ajax.getRequest(url)
                .then((response) => {
                    callback(response.total || 0);
                })
                .catch(() => {
                    callback(0);
                });
        },

        /**
         * Set the count badge for a tab
         */
        setTabCount: function (tabKey, count) {
            const $badge = this.$el.find(
                '.inbox-tab-count[data-scope="' + tabKey + '"]',
            );

            if (count > 0) {
                $badge.text(count).addClass("has-count");
            } else {
                $badge.text("").removeClass("has-count");
            }
        },

        /**
         * Load a conversation in the iframe
         */
        loadConversationInIframe: function (model) {
            const chatwootConversationId = model.get("chatwootConversationId");
            const chatwootAccountId = model.get("chatwootAccountIdExternal");

            // Debug logging
            console.log("loadConversationInIframe:", {
                chatwootConversationId: chatwootConversationId,
                chatwootAccountId: chatwootAccountId,
                chatSsoUrl: this.chatSsoUrl,
                modelId: model.id,
            });

            if (!chatwootConversationId || !chatwootAccountId) {
                console.error("Missing required data:", {
                    chatwootConversationId: chatwootConversationId,
                    chatwootAccountId: chatwootAccountId,
                });
                this.showIframeError();
                return;
            }

            const cwPath =
                "/app/accounts/" +
                chatwootAccountId +
                "/inbox-view/conversation/" +
                chatwootConversationId;
            const hasSsoAuthenticated =
                sessionStorage.getItem(this.CHATWOOT_SSO_AUTH_KEY) === "true";

            let chatwootUrl;

            if (this.chatSsoUrl && !hasSsoAuthenticated) {
                chatwootUrl = this.chatSsoUrl;
                sessionStorage.setItem(this.CHATWOOT_SSO_AUTH_KEY, "true");
                this.pendingNavigation = cwPath;

                // Save intended URL to Chatwoot's localStorage via postMessage
                // This ensures the URL is preserved even if Chatwoot redirects to dashboard after SSO
                this.saveIntendedUrlInChatwoot(cwPath);
            } else {
                chatwootUrl = this.chatwootBaseUrl + cwPath;
            }

            this.chatwootUrl = chatwootUrl;

            const $iframe = this.$el.find(".inbox-iframe");
            const $placeholder = this.$el.find(".inbox-iframe-placeholder");

            $placeholder.hide();
            $iframe.attr("src", chatwootUrl).show();

            // Handle pending SSO navigation
            if (this.pendingNavigation) {
                this.setupPendingNavigation();
            }
        },

        /**
         * Save intended URL in Chatwoot's localStorage via postMessage
         * This ensures the URL is preserved even if Chatwoot redirects to dashboard after SSO
         */
        saveIntendedUrlInChatwoot: function (path) {
            // We need to save the intended URL directly in localStorage since
            // the iframe isn't loaded yet. We'll use a temporary iframe or
            // rely on the bridge to handle this when Chatwoot loads.
            // For now, we also save it in the parent's localStorage as a backup
            // that the bridge can check via the CHATWOOT_READY initial path.

            // Save in parent localStorage as backup
            try {
                localStorage.setItem("chatwoot_intended_url_backup", path);
                console.log("Inbox: Saved intended URL backup:", path);
            } catch (e) {
                console.error("Inbox: Failed to save intended URL backup:", e);
            }
        },

        /**
         * Setup handler for pending navigation after SSO
         */
        setupPendingNavigation: function () {
            const pendingPath = this.pendingNavigation;
            this.pendingNavigation = null;

            const handleReady = (event) => {
                if (event.data && event.data.type === "CHATWOOT_READY") {
                    // First, send the intended URL to Chatwoot to save in its localStorage
                    const $iframe = this.$el.find(".inbox-iframe")[0];
                    if ($iframe && $iframe.contentWindow) {
                        try {
                            $iframe.contentWindow.postMessage(
                                {
                                    type: "SAVE_INTENDED_URL",
                                    path: pendingPath,
                                    timestamp: Date.now(),
                                },
                                this.chatwootBaseUrl,
                            );
                            console.log(
                                "Inbox: Sent SAVE_INTENDED_URL to Chatwoot:",
                                pendingPath,
                            );
                        } catch (e) {
                            console.error(
                                "Inbox: Failed to send SAVE_INTENDED_URL:",
                                e,
                            );
                        }
                    }

                    // Then redirect to the target URL
                    const targetUrl = this.chatwootBaseUrl + pendingPath;
                    this.$el.find(".inbox-iframe").attr("src", targetUrl);
                    window.removeEventListener("message", handleReady);

                    // Clear the backup
                    try {
                        localStorage.removeItem("chatwoot_intended_url_backup");
                    } catch (e) {
                        // Ignore
                    }
                }
            };

            window.addEventListener("message", handleReady);

            this.once("remove", function () {
                window.removeEventListener("message", handleReady);
            });
        },

        /**
         * Show error in iframe area
         */
        showIframeError: function (errorMsg) {
            const $placeholder = this.$el.find(".inbox-iframe-placeholder");
            const message =
                errorMsg ||
                this.translate(
                    "Unable to load conversation",
                    "messages",
                    "ChatwootConversation",
                );

            $placeholder
                .html(
                    '<i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #f59e0b; margin-bottom: 16px;"></i>' +
                        '<p style="color: #6b7280;">' +
                        message +
                        "</p>",
                )
                .show();
            this.$el.find(".inbox-iframe").hide();
        },

        // Helper methods
        getInitials: function (name) {
            if (!name) return "?";
            const parts = name.trim().split(/\s+/);
            if (parts.length === 1) {
                return parts[0].substring(0, 2).toUpperCase();
            }
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        },

        getColorForName: function (name) {
            const colors = [
                "#f87171",
                "#fb923c",
                "#fbbf24",
                "#a3e635",
                "#4ade80",
                "#34d399",
                "#22d3d8",
                "#38bdf8",
                "#60a5fa",
                "#818cf8",
                "#a78bfa",
                "#c084fc",
                "#e879f9",
                "#f472b6",
                "#fb7185",
            ];
            let hash = 0;
            for (let i = 0; i < (name || "").length; i++) {
                hash = name.charCodeAt(i) + ((hash << 5) - hash);
            }
            return colors[Math.abs(hash) % colors.length];
        },

        normalizeChannelType: function (channelType, inboxName) {
            // Try to detect from channelType first
            if (channelType) {
                const type = channelType.toLowerCase();
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

            // Fallback: try to detect from inbox name
            if (inboxName) {
                const name = inboxName.toLowerCase();
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

        getChannelIcon: function (channelType) {
            const icons = {
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

        truncateMessage: function (message, maxLength) {
            if (!message) return "";
            message = message.replace(/\n/g, " ").trim();
            if (message.length <= maxLength) return message;
            return message.substring(0, maxLength) + "...";
        },

        getTimeAgo: function (dateString) {
            if (!dateString) return "";

            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);

            if (diffMins < 1) return "now";
            if (diffMins < 60) return diffMins + "m";
            if (diffHours < 24) return diffHours + "h";
            if (diffDays < 7) return diffDays + "d";

            return date.toLocaleDateString();
        },

        translateOption: function (value, field, scope) {
            return this.getLanguage().translateOption(value, field, scope);
        },

        /**
         * Switch between tabs in the right panel
         */
        switchTab: function (tab) {
            this.activeTab = tab;

            // Update tab buttons
            this.$el.find(".inbox-tab").removeClass("active");
            this.$el
                .find('.inbox-tab[data-tab="' + tab + '"]')
                .addClass("active");

            // Update tab content
            this.$el.find(".inbox-tab-content").removeClass("active");
            this.$el
                .find('.inbox-tab-content[data-tab="' + tab + '"]')
                .addClass("active");

            // Render the list view for entity tabs
            if (tab === "opportunities") {
                this.renderEntityListView("opportunities", "Opportunity");
            } else if (tab === "appointments") {
                this.renderEntityListView("appointments", "Appointment");
            } else if (tab === "tasks") {
                this.renderEntityListView("tasks", "Task");
            } else if (tab === "cases") {
                this.renderEntityListView("cases", "Case");
            }
        },

        /**
         * Render a full list view for a related entity with search
         */
        renderEntityListView: function (viewKey, scope) {
            const model = this.getSelectedModel();
            if (!model) return;

            const searchContainerSelector =
                '.inbox-tab-content[data-tab="' +
                viewKey +
                '"] .inbox-entity-search-container';
            const listContainerSelector =
                '.inbox-tab-content[data-tab="' +
                viewKey +
                '"] .inbox-entity-list-container';

            // Clear existing views if conversation changed
            if (
                this.currentListModelId &&
                this.currentListModelId[viewKey] !== model.id
            ) {
                this.clearView(viewKey + "List");
                this.clearView(viewKey + "Search");
            }

            this.currentListModelId = this.currentListModelId || {};
            this.currentListModelId[viewKey] = model.id;

            // Check if view already exists
            if (this.getView(viewKey + "List")) {
                return;
            }

            // Get the link name based on scope
            const linkName =
                scope === "Opportunity"
                    ? "opportunities"
                      : scope === "Appointment"
                        ? "appointments"
                        : scope === "Case"
                          ? "cases"
                          : "tasks";

            // Use the relationship URL to fetch related records
            // URL format: ChatwootConversation/{id}/{link}
            const url = "ChatwootConversation/" + model.id + "/" + linkName;

            // Create collection with relationship URL
            this.getCollectionFactory().create(scope, (collection) => {
                collection.maxSize =
                    this.getConfig().get("recordsPerPageSmall") || 10;

                // Set URL to fetch related records
                collection.url = collection.urlRoot = url;

                // Create search manager
                const searchManager = new SearchManager(collection, {
                    defaultData:
                        this.getMetadata().get(
                            "clientDefs." + scope + ".defaultFilterData",
                        ) || {},
                });
                searchManager.scope = scope;

                // Apply initial where clause
                collection.where = searchManager.getWhere();

                // Create the search view
                this.createView(
                    viewKey + "Search",
                    "views/record/search",
                    {
                        collection: collection,
                        selector: searchContainerSelector,
                        searchManager: searchManager,
                        scope: scope,
                        textFilterDisabled: false,
                        primaryFiltersDisabled: false,
                        viewModeList: ["list"],
                        disableSavePreset: true,
                    },
                    (searchView) => {
                        searchView.render();

                        this.listenTo(searchView, "reset", () => {
                            collection.reset();
                            collection.fetch();
                        });
                    },
                );

                // Create the list view
                this.createView(
                    viewKey + "List",
                    "views/record/list",
                    {
                        collection: collection,
                        selector: listContainerSelector,
                        scope: scope,
                        skipBuildRows: true,
                        buttonsDisabled: false,
                        checkboxes: false,
                        rowActionsView: "views/record/row-actions/relationship",
                        showMore: true,
                        pagination: true,
                        displayTotalCount: true,
                        massActionsDisabled: true,
                        rowActionsOptions: {
                            unlinkDisabled: false,
                            editDisabled: false,
                            removeDisabled: true,
                        },
                    },
                    (view) => {
                        // Add unlink action handler to the list view
                        view.actionUnlinkRelated = (data) => {
                            const id = data.id;

                            this.confirm(
                                {
                                    message: this.translate(
                                        "unlinkRecordConfirmation",
                                        "messages",
                                    ),
                                    confirmText: this.translate("Unlink"),
                                },
                                () => {
                                    Espo.Ui.notify(" ... ");

                                    Espo.Ajax.deleteRequest(collection.url, {
                                        id: id,
                                    }).then(() => {
                                        Espo.Ui.success(
                                            this.translate("Unlinked"),
                                        );
                                        collection.fetch();

                                        // Update tab count
                                        const countKey =
                                            viewKey === "opportunities"
                                                ? "opportunities"
                                                : viewKey === "appointments"
                                                  ? "appointments"
                                                  : viewKey === "cases"
                                                    ? "cases"
                                                    : "tasks";
                                        this.fetchRelatedCount(
                                            countKey,
                                            scope,
                                            model.id,
                                            (count) => {
                                                this.setTabCount(
                                                    viewKey,
                                                    count,
                                                );
                                            },
                                        );
                                    });
                                },
                            );
                        };

                        view.render();
                        collection.fetch();
                    },
                );
            });
        },

        /**
         * Get the currently selected conversation model
         */
        getSelectedModel: function () {
            if (!this.selectedConversationId) return null;
            return this.collection.get(this.selectedConversationId);
        },

        /**
         * Refresh a specific entity list view
         */
        refreshEntityListView: function (viewKey) {
            const view = this.getView(viewKey + "List");
            if (view && view.collection) {
                view.collection.fetch();
            }
        },

        /**
         * Create a new Opportunity linked to the selected conversation
         */
        actionCreateOpportunity: function () {
            const model = this.getSelectedModel();
            if (!model) return;

            const contactId = model.get("contactId");
            const contactName = model.get("contactDisplayName");

            const attributes = {
                chatwootConversationsIds: [model.id],
                chatwootConversationsNames: {
                    [model.id]: model.get("name"),
                },
            };

            if (contactId) {
                attributes.contactId = contactId;
                attributes.contactName = contactName;
            }

            Espo.Ui.notify(" ... ");

            this.createView(
                "quickCreate",
                "views/modals/edit",
                {
                    scope: "Opportunity",
                    attributes: attributes,
                    fullFormDisabled: true,
                    layoutName: "detailSmallChatwoot",
                    sideDisabled: true,
                    bottomDisabled: true,
                },
                (view) => {
                    Espo.Ui.notify(false);
                    view.render();

                    this.listenToOnce(view, "after:save", () => {
                        // Refresh the list view and count
                        this.refreshEntityListView("opportunities");
                        this.fetchRelatedCount(
                            "opportunities",
                            "Opportunity",
                            model.id,
                            (count) => {
                                this.setTabCount("opportunities", count);
                            },
                        );
                    });
                },
            );
        },

        /**
         * Create a new Appointment linked to the selected conversation
         */
        actionCreateAppointment: function () {
            const model = this.getSelectedModel();
            if (!model) return;

            const contactId = model.get("contactId");
            const contactName = model.get("contactDisplayName");

            const attributes = {
                chatwootConversationsIds: [model.id],
                chatwootConversationsNames: {
                    [model.id]: model.get("name"),
                },
            };

            // Pre-populate customer field (link to Contact)
            if (contactId) {
                attributes.customerId = contactId;
                attributes.customerName = contactName;
            }

            // Pre-populate name with customer name
            if (contactName) {
                attributes.name = contactName;
            }

            Espo.Ui.notify(" ... ");

            this.createView(
                "quickCreateAppointment",
                "views/modals/edit",
                {
                    scope: "Appointment",
                    attributes: attributes,
                    fullFormDisabled: true,
                    layoutName: "detailSmall",
                    sideDisabled: true,
                    bottomDisabled: true,
                },
                (view) => {
                    Espo.Ui.notify(false);
                    view.render();

                    this.listenToOnce(view, "after:save", () => {
                        // Refresh the list view and count
                        this.refreshEntityListView("appointments");
                        this.fetchRelatedCount(
                            "appointments",
                            "Appointment",
                            model.id,
                            (count) => {
                                this.setTabCount("appointments", count);
                            },
                        );
                    });
                },
            );
        },

        /**
         * Create a new Task linked to the selected conversation
         */
        actionCreateTask: function () {
            const model = this.getSelectedModel();
            if (!model) return;

            const attributes = {
                parentType: "ChatwootConversation",
                parentId: model.id,
                parentName: model.get("name"),
            };

            Espo.Ui.notify(" ... ");

            this.createView(
                "quickCreateTask",
                "views/modals/edit",
                {
                    scope: "Task",
                    attributes: attributes,
                    fullFormDisabled: true,
                    layoutName: "detailSmall",
                    sideDisabled: true,
                    bottomDisabled: true,
                },
                (view) => {
                    Espo.Ui.notify(false);
                    view.render();

                    this.listenToOnce(view, "after:save", () => {
                        // Refresh the list view and count
                        this.refreshEntityListView("tasks");
                        this.fetchRelatedCount(
                            "tasks",
                            "Task",
                            model.id,
                            (count) => {
                                this.setTabCount("tasks", count);
                            },
                        );
                    });
                },
            );
        },

        /**
         * Create a new Case linked to the selected conversation
         */
        actionCreateCase: function () {
            const model = this.getSelectedModel();
            if (!model) return;

            const contactId = model.get("contactId");
            const contactName = model.get("contactDisplayName");

            const attributes = {
                chatwootConversationsIds: [model.id],
                chatwootConversationsNames: {
                    [model.id]: model.get("name"),
                },
            };

            if (contactId) {
                attributes.contactId = contactId;
                attributes.contactName = contactName;
            }

            Espo.Ui.notify(" ... ");

            this.createView(
                "quickCreateCase",
                "views/modals/edit",
                {
                    scope: "Case",
                    attributes: attributes,
                    fullFormDisabled: true,
                    layoutName: "detailSmall",
                    sideDisabled: true,
                    bottomDisabled: true,
                },
                (view) => {
                    Espo.Ui.notify(false);
                    view.render();

                    this.listenToOnce(view, "after:save", () => {
                        // Refresh the list view and count
                        this.refreshEntityListView("cases");
                        this.fetchRelatedCount(
                            "cases",
                            "Case",
                            model.id,
                            (count) => {
                                this.setTabCount("cases", count);
                            },
                        );
                    });
                },
            );
        },

        /**
         * Navigate to the detail view of the selected conversation
         */
        actionViewConversation: function () {
            const model = this.getSelectedModel();
            if (!model) return;

            this.getRouter().navigate(
                "#ChatwootConversation/view/" + model.id,
                { trigger: true },
            );
        },

        /**
         * Remove the selected conversation with confirmation
         */
        actionRemoveConversation: function () {
            const model = this.getSelectedModel();
            if (!model) return;

            // Capture status before deletion for optimistic UI update
            const status = model.get("status");

            this.confirm(
                {
                    message: this.translate(
                        "removeRecordConfirmation",
                        "messages",
                    ),
                    confirmText: this.translate("Remove"),
                },
                () => {
                    Espo.Ui.notify(this.translate("Removing..."));

                    // Trigger optimistic UI update for navbar badges
                    $(document).trigger("chatwoot:conversation:removed", {
                        status: status,
                    });

                    model
                        .destroy({
                            wait: true,
                        })
                        .then(() => {
                            Espo.Ui.success(this.translate("Removed"));

                            // Remove from collection
                            this.collection.remove(model);

                            // Clear selected conversation
                            this.selectedConversationId = null;

                            // Select next available conversation or show placeholder
                            if (this.collection.length > 0) {
                                const nextModel = this.collection.at(0);
                                this.selectConversation(nextModel.id);
                            } else {
                                this.clearConversationView();
                            }

                            // Re-render the list
                            this.reRender();
                        })
                        .catch(() => {
                            Espo.Ui.error(this.translate("Error"));
                            // Revert optimistic update on error by refreshing badges
                            $(document).trigger(
                                "chatwoot:conversation:badges:refresh",
                            );
                        });
                },
            );
        },

        /**
         * Cleanup on view removal
         */
        onRemove: function () {
            // Unsubscribe from WebSocket topics
            if (this.isWebSocketSubscribed && this.webSocketManager) {
                this.webSocketManager.unsubscribe("chatwootConversationUpdate");
                this.webSocketManager.unsubscribe(
                    "recordUpdate.ChatwootConversation",
                );
                console.log("Inbox: WebSocket subscriptions removed");
            }

            // Clear any pending timeouts
            if (this._webSocketDebounceTimeout) {
                clearTimeout(this._webSocketDebounceTimeout);
            }

            $(window).off("resize.inboxView" + this.cid);
            Dep.prototype.onRemove.call(this);
        },
    });
});

