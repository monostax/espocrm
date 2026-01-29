/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("chatwoot:views/chatwoot-conversation/modals/conversation-drawer", [
    "views/modal",
], function (Dep) {
    return Dep.extend({
        cssName: "conversation-drawer",
        className: "dialog conversation-drawer-dialog",

        template: "chatwoot:chatwoot-conversation/modals/conversation-drawer",

        backdrop: true,

        fitHeight: true,

        // Session storage key for tracking SSO auth state
        CHATWOOT_SSO_AUTH_KEY: "chatwoot_sso_authenticated",

        data: function () {
            return {
                hasConversation: this.hasConversation,
                chatwootUrl: this.chatwootUrl,
                errorMessage: this.errorMessage,
            };
        },

        setup: function () {
            this.headerText =
                this.options.contactName ||
                this.translate("Conversation", "scopeNames");
            this.recordId = this.options.recordId;

            // Add header buttons
            this.buttonList = [
                {
                    name: "viewDetails",
                    label: "View Details",
                    style: "default",
                },
                {
                    name: "close",
                    label: "Close",
                },
            ];

            const chatwootConversationId = this.options.chatwootConversationId;
            const chatwootAccountId =
                this.getHelper().getAppParam("chatwootAccountId");
            const chatSsoUrl = this.getHelper().getAppParam("chatSsoUrl");
            const chatwootBaseUrl = this.getHelper().getAppParam(
                "chatwootFrontendUrl",
            );

            this.hasConversation = chatwootConversationId && chatwootAccountId;

            if (!this.hasConversation) {
                this.errorMessage = !chatwootAccountId
                    ? this.translate(
                          "Your user is not linked to a Chat / Account",
                          "messages",
                          "Chat / Conversation",
                      )
                    : this.translate(
                          "No conversation ID available",
                          "messages",
                          "Chat / Conversation",
                      );
                return;
            }

            // Build the conversation path
            const cwPath = `/app/accounts/${chatwootAccountId}/inbox-view/conversation/${chatwootConversationId}`;

            // Check SSO auth state
            const hasSsoAuthenticated =
                sessionStorage.getItem(this.CHATWOOT_SSO_AUTH_KEY) === "true";

            if (chatSsoUrl && !hasSsoAuthenticated) {
                // Use SSO URL for first-time authentication
                this.chatwootUrl = chatSsoUrl;
                sessionStorage.setItem(this.CHATWOOT_SSO_AUTH_KEY, "true");
                this.pendingNavigation = cwPath;
                this.chatwootBaseUrl = chatwootBaseUrl;
            } else {
                // Already authenticated - use direct path
                this.chatwootUrl = `${chatwootBaseUrl}${cwPath}`;
            }
        },

        actionClose: function () {
            this.close();
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            // Add drawer-specific class to the modal backdrop and dialog
            this.$el.closest(".modal").addClass("drawer-modal");
            $(".modal-backdrop").last().addClass("drawer-backdrop");

            // Handle iframe resizing
            const $iframe = this.$el.find("iframe");
            if ($iframe.length) {
                const updateHeight = () => {
                    const $modal = this.$el.closest(".modal");
                    const footerHeight =
                        $modal.find(".modal-footer").outerHeight() || 0;
                    const windowHeight = $(window).height();
                    const availableHeight = windowHeight - footerHeight;
                    $iframe.css(
                        "height",
                        Math.max(300, availableHeight) + "px",
                    );
                };

                // Delay to ensure modal is fully rendered
                setTimeout(updateHeight, 50);
                $(window).on("resize.conversationDrawer", updateHeight);

                // Handle pending navigation after SSO
                if (this.pendingNavigation) {
                    const pendingPath = this.pendingNavigation;
                    this.pendingNavigation = null;

                    const handleReady = (event) => {
                        if (event.data.type === "CHATWOOT_READY") {
                            const targetUrl = `${this.chatwootBaseUrl}${pendingPath}`;
                            $iframe.attr("src", targetUrl);
                            window.removeEventListener("message", handleReady);
                        }
                    };

                    window.addEventListener("message", handleReady);

                    this.once("remove", () => {
                        window.removeEventListener("message", handleReady);
                    });
                }
            }

            // Clean up on close
            this.once("remove", () => {
                $(window).off("resize.conversationDrawer");
                $(".modal-backdrop").removeClass("drawer-backdrop");
            });
        },

        close: function () {
            this.dialog.close();
        },

        actionViewDetails: function () {
            // Close the drawer and navigate to the detail view
            this.close();

            if (this.recordId) {
                this.getRouter().navigate(
                    "#ChatwootConversation/view/" + this.recordId,
                    { trigger: true },
                );
            }
        },
    });
});
