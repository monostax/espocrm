/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

import BaseFieldView from "views/fields/base";
import AppParams from "app-params";
import { inject } from "di";

/**
 * A field view that displays a Chatwoot conversation in an iframe.
 * Uses SSO authentication and navigates directly to the conversation.
 *
 * Usage in detail layout JSON:
 * {
 *     "tabBreak": true,
 *     "tabLabel": "Chatwoot",
 *     "name": "chatwootIframeTab",
 *     "rows": [
 *         [
 *             {
 *                 "name": "chatwootIframe",
 *                 "view": "chatwoot:views/chatwoot-conversation/fields/iframe-conversation",
 *                 "noLabel": true,
 *                 "span": 4
 *             }
 *         ]
 *     ]
 * }
 */
class IframeConversationFieldView extends BaseFieldView {
    @inject(AppParams)
    appParams;

    chatwootBaseUrl = null;

    templateContent = `
        {{#if hasConversation}}
        <div class="chatwoot-conversation-iframe-container" style="width: 100%; overflow: hidden;">
            <iframe
                src="{{chatwootUrl}}"
                style="width: 100%; height: 600px; border: none; margin: 0; padding: 0; border-radius: 4px;"
                frameborder="0"
                allowfullscreen
                allow="microphone"
                sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"
            ></iframe>
        </div>
        {{else}}
        <div class="text-muted" style="padding: 20px; text-align: center;">
            {{#if errorMessage}}
            <span class="text-danger">{{errorMessage}}</span>
            {{else}}
            {{translate 'No conversation data available'}}
            {{/if}}
        </div>
        {{/if}}
    `;

    /**
     * Override fetch to return empty object.
     * This is a display-only view.
     */
    fetch() {
        return {};
    }

    /**
     * No attributes to track for this display-only view.
     */
    getAttributeList() {
        return [];
    }

    /**
     * This field is always valid (nothing to validate).
     */
    validate() {
        return false;
    }

    setup() {
        super.setup();

        // Get SSO URL for authentication
        this.chatSsoUrl = this.appParams.get("chatSsoUrl");

        // Get frontend URL from AppParams (from ChatwootPlatform)
        this.chatwootBaseUrl = this.appParams.get("chatwootFrontendUrl");

        // Setup listener for chatwoot route changes
        this.setupChatwootListener();

        // Listen for model sync to re-render when data is loaded
        this.listenTo(this.model, "sync", () => {
            if (this.isRendered()) {
                this.reRender();
            }
        });
    }

    /**
     * Get the conversation ID from the model (called fresh each render)
     */
    getChatwootConversationId() {
        return this.model.get("chatwootConversationId");
    }

    setupChatwootListener() {
        const messageHandler = (event) => {
            if (event.data.type === "CHATWOOT_ROUTE_CHANGE") {
                // We can track route changes if needed
            }
        };

        window.addEventListener("message", messageHandler);

        this.once("remove", () => {
            window.removeEventListener("message", messageHandler);
        });
    }

    /**
     * Resolve the external Chatwoot account ID.
     * Prefers the model's own foreign field, falls back to AppParams.
     */
    getChatwootAccountIdExternal() {
        return (
            this.model.get("chatwootAccountIdExternal") ||
            this.appParams.get("chatwootAccountId")
        );
    }

    data() {
        // Get conversation ID fresh from model (may have been loaded after setup)
        const chatwootConversationId = this.getChatwootConversationId();
        const chatwootAccountId = this.getChatwootAccountIdExternal();
        const hasConversation = chatwootConversationId && chatwootAccountId;

        if (!hasConversation) {
            return {
                hasConversation: false,
                errorMessage: !chatwootAccountId
                    ? this.translate(
                          "Your user is not linked to a Chat / Account",
                      )
                    : !chatwootConversationId
                      ? this.translate("No conversation ID available")
                      : null,
            };
        }

        // Build the conversation path with SSO params appended
        const cwPath = `/app/accounts/${chatwootAccountId}/inbox-view/conversation/${chatwootConversationId}`;
        let chatwootUrl = `${this.chatwootBaseUrl}${cwPath}`;

        // Append SSO credentials from the SSO URL as query params
        // The iframe-parent-bridge.js script on the Chatwoot side will
        // detect these params and auto-login when the user hits /app/login
        if (this.chatSsoUrl) {
            try {
                const ssoUrlObj = new URL(this.chatSsoUrl);
                const email = ssoUrlObj.searchParams.get("email");
                const ssoToken = ssoUrlObj.searchParams.get("sso_auth_token");

                if (email && ssoToken) {
                    const separator = chatwootUrl.includes("?") ? "&" : "?";
                    chatwootUrl +=
                        `${separator}sso_email=${encodeURIComponent(email)}&sso_auth_token=${encodeURIComponent(ssoToken)}`;
                }
            } catch (e) {
                console.error(
                    "IframeConversationFieldView: Failed to parse SSO URL:",
                    e,
                );
            }
        }

        return {
            hasConversation: true,
            chatwootUrl: chatwootUrl,
        };
    }

    afterRender() {
        const $iframe = this.$el.find("iframe");

        if ($iframe.length === 0) {
            return;
        }

        // Calculate available height
        const updateHeight = () => {
            // Get the container's available height
            const $container = this.$el.closest(".panel-body");
            if ($container.length) {
                const windowHeight = $(window).height();
                const containerOffset = $container.offset()?.top || 0;
                const padding = 40; // Some padding from bottom
                const availableHeight = Math.max(
                    400,
                    windowHeight - containerOffset - padding,
                );
                $iframe.css("height", availableHeight + "px");
            }
        };

        // Initial height update (with small delay to ensure DOM is ready)
        setTimeout(updateHeight, 100);

        // Update height on window resize
        $(window).on("resize.chatwootIframe", updateHeight);

        // Clean up on view removal
        this.once("remove", () => {
            $(window).off("resize.chatwootIframe");
        });
    }
}

export default IframeConversationFieldView;
