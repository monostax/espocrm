/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("chatwoot:views/chatwoot-conversation/record/kanban-item", [
    "views/record/kanban-item",
], function (Dep) {
    return Dep.extend({
        template: "chatwoot:chatwoot-conversation/record/kanban-item",

        data: function () {
            const contactName =
                this.model.get("contactDisplayName") ||
                this.model.get("name") ||
                "Unknown Contact";

            const avatarUrl = this.model.get("contactAvatarUrl");
            const lastMessage = this.model.get("lastMessageContent") || "";
            const lastMessageType =
                this.model.get("lastMessageType") || "incoming";
            const messagesCount = this.model.get("messagesCount") || 0;
            const lastActivityAt = this.model.get("lastActivityAt");
            const channelType = this.model.get("inboxChannelType") || "";
            const status = this.model.get("status") || "open";
            const assigneeName = this.model.get("assigneeName");
            const inboxName = this.model.get("inboxName");

            // Get channel icon based on channel type
            const channelIcon = this.getChannelIcon(channelType);

            // Format time ago
            const timeAgo = this.formatTimeAgo(lastActivityAt);

            // Truncate message for preview
            const messagePreview = this.truncateMessage(lastMessage, 80);

            // Get initials for avatar fallback
            const initials = this.getInitials(contactName);

            // Get initials color (same algorithm as chatwoot-contact avatar-url view)
            const initialsColor = this.getInitialsColor(contactName);

            // Get status style
            const statusStyle = this.getStatusStyle(status);

            // Get linked opportunities
            const opportunities = this.getOpportunitiesData();

            // Get linked agendamentos
            const agendamentos = this.getAgendamentosData();

            // Get linked tasks
            const tasks = this.getTasksData();

            return {
                ...Dep.prototype.data.call(this),
                contactName: contactName,
                avatarUrl: avatarUrl,
                hasAvatar: !!avatarUrl,
                initials: initials,
                initialsColor: initialsColor,
                messagePreview: messagePreview,
                hasMessage: !!lastMessage,
                lastMessageType: lastMessageType,
                isOutgoing: lastMessageType === "outgoing",
                isIncoming: lastMessageType === "incoming",
                messagesCount: messagesCount,
                timeAgo: timeAgo,
                channelIcon: channelIcon,
                channelType: channelType,
                status: status,
                statusStyle: statusStyle,
                statusLabel:
                    this.getLanguage().translateOption(
                        status,
                        "status",
                        "ChatwootConversation"
                    ) || status,
                assigneeName: assigneeName,
                hasAssignee: !!assigneeName,
                inboxName: inboxName,
                id: this.model.id,
                opportunities: opportunities,
                hasOpportunities: opportunities.length > 0,
                agendamentos: agendamentos,
                hasAgendamentos: agendamentos.length > 0,
                tasks: tasks,
                hasTasks: tasks.length > 0,
                // Section labels
                opportunityLabel: this.translate(
                    "Opportunity",
                    "scopeNamesPlural"
                ),
                agendamentoLabel: this.translate(
                    "CAgendamento",
                    "scopeNamesPlural"
                ),
                taskLabel: this.translate(
                    "Task",
                    "scopeNamesPlural"
                ),
                createOpportunityLabel: this.translate(
                    "Create Opportunity",
                    "labels",
                    "Opportunity"
                ),
                createAgendamentoLabel: this.translate(
                    "Create CAgendamento",
                    "labels",
                    "CAgendamento"
                ),
                createTaskLabel: this.translate(
                    "Create Task",
                    "labels",
                    "Task"
                ),
            };
        },

        /**
         * Get FontAwesome icon class for channel type
         */
        getChannelIcon: function (channelType) {
            const iconMap = {
                "Channel::Api": "fab fa-whatsapp",
                "Channel::Email": "fas fa-envelope",
                "Channel::FacebookPage": "fab fa-facebook",
                "Channel::Line": "fab fa-line",
                "Channel::Sms": "fas fa-sms",
                "Channel::Telegram": "fab fa-telegram",
                "Channel::TwilioSms": "fas fa-sms",
                "Channel::TwitterProfile": "fab fa-twitter",
                "Channel::WebWidget": "fas fa-globe",
                "Channel::Whatsapp": "fab fa-whatsapp",
                "Channel::InstagramDirect": "fab fa-instagram",
            };

            return iconMap[channelType] || "fas fa-comment";
        },

        /**
         * Format datetime to relative time
         */
        formatTimeAgo: function (datetime) {
            if (!datetime) return "";

            // Use EspoCRM's date/time utilities for proper timezone handling
            const dateTime = this.getDateTime();
            const now = moment();
            const date = moment.utc(datetime).tz(dateTime.getTimeZone());

            // Check if date is valid
            if (!date.isValid()) return "";

            const diffMs = now.diff(date);
            const diffSecs = Math.floor(diffMs / 1000);
            const diffMins = Math.floor(diffSecs / 60);
            const diffHours = Math.floor(diffMins / 60);
            const diffDays = Math.floor(diffHours / 24);

            if (diffSecs < 60) return "now";
            if (diffMins < 60) return diffMins + "m";
            if (diffHours < 24) return diffHours + "h";
            if (diffDays < 7) return diffDays + "d";

            // Format as date for older messages
            return date.format("MMM D");
        },

        /**
         * Truncate message with ellipsis
         */
        truncateMessage: function (message, maxLength) {
            if (!message) return "";
            if (message.length <= maxLength) return message;
            return message.substring(0, maxLength).trim() + "...";
        },

        /**
         * Get initials from name for avatar fallback
         */
        getInitials: function (name) {
            if (!name) return "?";
            const parts = name.trim().split(/\s+/);
            if (parts.length === 1) {
                return parts[0].charAt(0).toUpperCase();
            }
            return (
                parts[0].charAt(0) + parts[parts.length - 1].charAt(0)
            ).toUpperCase();
        },

        /**
         * Generate a consistent color based on the name
         * Same algorithm as chatwoot:views/chatwoot-contact/fields/avatar-url
         */
        getInitialsColor: function (name) {
            if (!name) return "hsl(0, 0%, 60%)";

            // Generate hash from name
            let hash = 0;
            for (let i = 0; i < name.length; i++) {
                hash = name.charCodeAt(i) + ((hash << 5) - hash);
            }

            // Convert to hue (0-360)
            const hue = Math.abs(hash % 360);

            return `hsl(${hue}, 65%, 45%)`;
        },

        /**
         * Get CSS class for status badge
         */
        getStatusStyle: function (status) {
            const styleMap = {
                open: "status-open",
                resolved: "status-resolved",
                pending: "status-pending",
                snoozed: "status-snoozed",
            };
            return styleMap[status] || "status-default";
        },

        /**
         * Get linked opportunities data for display
         */
        getOpportunitiesData: function () {
            const opportunitiesIds = this.model.get("opportunitiesIds") || [];
            const opportunitiesNames =
                this.model.get("opportunitiesNames") || {};
            const opportunitiesColumns =
                this.model.get("opportunitiesColumns") || {};

            return opportunitiesIds.map((id) => {
                const stage = opportunitiesColumns[id]
                    ? opportunitiesColumns[id].stage
                    : null;
                return {
                    id: id,
                    name: opportunitiesNames[id] || "Opportunity",
                    stage: stage,
                    stageLabel: stage
                        ? this.getLanguage().translateOption(
                              stage,
                              "stage",
                              "Opportunity"
                          )
                        : null,
                    stageStyle: this.getOpportunityStageStyle(stage),
                };
            });
        },

        /**
         * Get CSS class for opportunity stage
         */
        getOpportunityStageStyle: function (stage) {
            const styleMap = {
                Prospecting: "opp-stage-prospecting",
                Qualification: "opp-stage-qualification",
                Proposal: "opp-stage-proposal",
                Negotiation: "opp-stage-negotiation",
                "Closed Won": "opp-stage-won",
                "Closed Lost": "opp-stage-lost",
            };
            return styleMap[stage] || "opp-stage-default";
        },

        /**
         * Get linked agendamentos data for display
         */
        getAgendamentosData: function () {
            const agendamentosIds = this.model.get("cAgendamentosIds") || [];
            const agendamentosNames =
                this.model.get("cAgendamentosNames") || {};
            const agendamentosColumns =
                this.model.get("cAgendamentosColumns") || {};

            return agendamentosIds.map((id) => {
                const status = agendamentosColumns[id]
                    ? agendamentosColumns[id].status
                    : null;
                const dateStart = agendamentosColumns[id]
                    ? agendamentosColumns[id].dateStart
                    : null;
                return {
                    id: id,
                    name: agendamentosNames[id] || "Agendamento",
                    status: status,
                    dateStart: dateStart,
                    statusLabel: status
                        ? this.getLanguage().translateOption(
                              status,
                              "status",
                              "CAgendamento"
                          )
                        : null,
                    statusStyle: this.getAgendamentoStatusStyle(status),
                };
            });
        },

        /**
         * Get CSS class for agendamento status
         */
        getAgendamentoStatusStyle: function (status) {
            const styleMap = {
                Planned: "agendamento-status-planned",
                Held: "agendamento-status-held",
                "Not Held": "agendamento-status-not-held",
            };
            return styleMap[status] || "agendamento-status-planned";
        },

        /**
         * Get linked tasks data for display
         */
        getTasksData: function () {
            const tasksIds = this.model.get("tasksIds") || [];
            const tasksNames = this.model.get("tasksNames") || {};
            const tasksColumns = this.model.get("tasksColumns") || {};

            return tasksIds.map((id) => {
                const status = tasksColumns[id]
                    ? tasksColumns[id].status
                    : null;
                const dateEnd = tasksColumns[id]
                    ? tasksColumns[id].dateEnd
                    : null;
                return {
                    id: id,
                    name: tasksNames[id] || "Task",
                    status: status,
                    dateEnd: dateEnd,
                    statusLabel: status
                        ? this.getLanguage().translateOption(
                              status,
                              "status",
                              "Task"
                          )
                        : null,
                    statusStyle: this.getTaskStatusStyle(status),
                };
            });
        },

        /**
         * Get CSS class for task status
         */
        getTaskStatusStyle: function (status) {
            const styleMap = {
                "Not Started": "task-status-not-started",
                "Started": "task-status-started",
                "Completed": "task-status-completed",
                "Canceled": "task-status-canceled",
                "Deferred": "task-status-deferred",
            };
            return styleMap[status] || "task-status-not-started";
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            // Re-render card when status changes (drag & drop or manual update)
            this.listenTo(this.model, "change:status", () => {
                this.reRender();
            });

            // Re-render when model is synced (after save operations)
            this.listenTo(this.model, "sync", () => {
                this.reRender();
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            // Apply channel badge styling based on channel type
            this.applyChannelBadgeStyle();

            // Handle image load errors by showing initials fallback
            // Same pattern as chatwoot:views/chatwoot-contact/fields/avatar-url
            const $img = this.$el.find(".chatwoot-avatar-img");
            if ($img.length) {
                $img.on("error", () => {
                    $img.hide();
                    this.$el.find(".chatwoot-avatar-initials").show();
                });
            }

            // Bind create opportunity button FIRST (before card click)
            this.$el.find(".btn-create-opportunity").on("click", (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                this.actionCreateOpportunity();
            });

            // Bind create agendamento button FIRST (before card click)
            this.$el.find(".btn-create-agendamento").on("click", (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                this.actionCreateAgendamento();
            });

            // Bind create task button FIRST (before card click)
            this.$el.find(".btn-create-task").on("click", (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                this.actionCreateTask();
            });

            // Make the card clickable to open detail view
            this.$el.find(".conversation-card").on("click", (e) => {
                // Don't trigger if clicking on action buttons or create buttons
                if (
                    $(e.target).closest(".item-menu-container").length ||
                    $(e.target).closest(".btn-create-opportunity").length ||
                    $(e.target).closest(".btn-create-agendamento").length ||
                    $(e.target).closest(".btn-create-task").length ||
                    $(e.target).closest(".conversation-opportunity").length ||
                    $(e.target).closest(".conversation-agendamento").length ||
                    $(e.target).closest(".conversation-task").length
                ) {
                    return;
                }
                this.actionQuickView();
            });
        },

        /**
         * Apply the correct channel class to the badge
         */
        applyChannelBadgeStyle: function () {
            const $badge = this.$el.find(".channel-badge");
            const channelType = this.model.get("inboxChannelType") || "";

            let channelClass = "channel-default";

            if (channelType.includes("Whatsapp")) {
                channelClass = "channel-whatsapp";
            } else if (channelType.includes("Telegram")) {
                channelClass = "channel-telegram";
            } else if (channelType.includes("Instagram")) {
                channelClass = "channel-instagram";
            } else if (channelType.includes("Facebook")) {
                channelClass = "channel-facebook";
            } else if (channelType.includes("Email")) {
                channelClass = "channel-email";
            } else if (channelType.includes("Web")) {
                channelClass = "channel-web";
            }

            $badge.addClass(channelClass);
        },

        actionQuickView: function () {
            // Open the conversation drawer with iframe
            this.createView(
                "conversationDrawer",
                "chatwoot:views/chatwoot-conversation/modals/conversation-drawer",
                {
                    chatwootConversationId: this.model.get(
                        "chatwootConversationId"
                    ),
                    contactName:
                        this.model.get("contactDisplayName") ||
                        this.model.get("name"),
                    inboxName: this.model.get("inboxName"),
                    recordId: this.model.id,
                },
                (view) => {
                    view.render();

                    this.listenToOnce(view, "close", () => {
                        // Refresh the model when drawer closes
                        this.model.fetch();
                    });
                }
            );
        },

        actionCreateOpportunity: function () {
            // Get contact ID from the conversation's linked contact
            const contactId = this.model.get("contactId");
            const contactName = this.model.get("contactDisplayName");

            // Prepare attributes for the new Opportunity
            const attributes = {
                chatwootConversationsIds: [this.model.id],
                chatwootConversationsNames: {
                    [this.model.id]: this.model.get("name"),
                },
            };

            // If there's a linked contact, also link it to the Opportunity
            if (contactId) {
                attributes.contactId = contactId;
                attributes.contactName = contactName;
            }

            // Open the Opportunity create modal using custom layout
            // with funnel and opportunityStage fields instead of the obsolete stage
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
                        // Refresh the model and re-render the card
                        this.model.fetch().then(() => {
                            this.reRender();
                        });
                    });
                }
            );
        },

        actionCreateAgendamento: function () {
            // Get contact ID from the conversation's linked contact
            const contactId = this.model.get("contactId");
            const contactName = this.model.get("contactDisplayName");

            // Prepare attributes for the new CAgendamento
            const attributes = {
                chatwootConversationsIds: [this.model.id],
                chatwootConversationsNames: {
                    [this.model.id]: this.model.get("name"),
                },
            };

            // If there's a linked contact, also link it to the CAgendamento
            if (contactId) {
                attributes.contactId = contactId;
                attributes.contactName = contactName;
            }

            // Open the CAgendamento create modal
            Espo.Ui.notify(" ... ");

            this.createView(
                "quickCreateAgendamento",
                "views/modals/edit",
                {
                    scope: "CAgendamento",
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
                        // Refresh the model and re-render the card
                        this.model.fetch().then(() => {
                            this.reRender();
                        });
                    });
                }
            );
        },

        actionCreateTask: function () {
            // Get contact ID from the conversation's linked contact
            const contactId = this.model.get("contactId");
            const contactName = this.model.get("contactDisplayName");

            // Prepare attributes for the new Task
            // Tasks use parent relationship, not linkMultiple
            const attributes = {
                parentType: "ChatwootConversation",
                parentId: this.model.id,
                parentName: this.model.get("name"),
            };

            // If there's a linked contact, also link it to the Task
            if (contactId) {
                attributes.contactId = contactId;
                attributes.contactName = contactName;
            }

            // Open the Task create modal
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
                        // Refresh the model and re-render the card
                        this.model.fetch().then(() => {
                            this.reRender();
                        });
                    });
                }
            );
        },
    });
});

