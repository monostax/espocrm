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
    /**
     * Extract SSO credentials (email + sso_auth_token) from an SSO URL
     * and append them to a target Chatwoot URL as query params.
     *
     * SSO URL format: https://chat.../app/login?email=X&sso_auth_token=Y
     * Result: targetUrl?sso_email=X&sso_auth_token=Y
     *
     * The iframe-parent-bridge.js script on the Chatwoot side will detect
     * these params and auto-login when the user lands on /app/login.
     */
    function buildChatwootUrlWithSso(chatwootBaseUrl, chatSsoUrl, cwPath) {
        var targetUrl = chatwootBaseUrl + cwPath;

        if (!chatSsoUrl) {
            return targetUrl;
        }

        try {
            var ssoUrlObj = new URL(chatSsoUrl);
            var email = ssoUrlObj.searchParams.get("email");
            var ssoToken = ssoUrlObj.searchParams.get("sso_auth_token");

            if (email && ssoToken) {
                var separator = targetUrl.includes("?") ? "&" : "?";
                targetUrl +=
                    separator +
                    "sso_email=" +
                    encodeURIComponent(email) +
                    "&sso_auth_token=" +
                    encodeURIComponent(ssoToken);
            }
        } catch (e) {
            console.error("ConversationDrawer: Failed to parse SSO URL:", e);
        }

        return targetUrl;
    }

    return Dep.extend({
        cssName: "conversation-drawer",
        className: "dialog conversation-drawer-dialog",

        template: "chatwoot:chatwoot-conversation/modals/conversation-drawer",

        scope: "ChatwootConversation",

        backdrop: true,

        fitHeight: true,

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
                this.options.chatwootAccountIdExternal ||
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

            // Build the conversation path with SSO params appended
            const cwPath = `/app/accounts/${chatwootAccountId}/inbox-view/conversation/${chatwootConversationId}`;
            this.chatwootUrl = buildChatwootUrlWithSso(
                chatwootBaseUrl,
                chatSsoUrl,
                cwPath,
            );
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
