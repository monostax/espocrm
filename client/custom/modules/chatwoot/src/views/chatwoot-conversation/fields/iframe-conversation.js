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

// Session storage key for tracking SSO auth state globally (shared with chatwoot/index.js)
const CHATWOOT_SSO_AUTH_KEY = "chatwoot_sso_authenticated";

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

    /**
     * Check if SSO has been completed this session (shared across all Chatwoot views)
     */
    static hasSsoAuthenticated() {
        return sessionStorage.getItem(CHATWOOT_SSO_AUTH_KEY) === "true";
    }

    /**
     * Mark SSO as completed for this session
     */
    static setSsoAuthenticated() {
        sessionStorage.setItem(CHATWOOT_SSO_AUTH_KEY, "true");
    }

    templateContent = `
        {{#if hasConversation}}
        <div class="chatwoot-conversation-iframe-container" style="width: 100%; overflow: hidden;">
            <iframe
                src="{{chatwootUrl}}"
                style="width: 100%; height: 600px; border: none; margin: 0; padding: 0; border-radius: 4px;"
                frameborder="0"
                allowfullscreen
                sandbox="allow-same-origin allow-scripts allow-forms"
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

        // Get the chatwoot account ID from AppParams (user's linked account)
        this.chatwootAccountId = this.appParams.get("chatwootAccountId");

        // Get SSO URL for authentication
        this.chatwootSsoUrl = this.appParams.get("chatwootSsoUrl");

        // Get frontend URL from AppParams (from ChatwootPlatform)
        this.chatwootBaseUrl = this.appParams.get("chatwootFrontendUrl") || "https://chatwoot.am.monostax.dev.localhost";

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

    data() {
        // Get conversation ID fresh from model (may have been loaded after setup)
        const chatwootConversationId = this.getChatwootConversationId();
        const hasConversation =
            chatwootConversationId && this.chatwootAccountId;

        if (!hasConversation) {
            return {
                hasConversation: false,
                errorMessage: !this.chatwootAccountId
                    ? this.translate(
                          "Your user is not linked to a Chatwoot account"
                      )
                    : !chatwootConversationId
                    ? this.translate("No conversation ID available")
                    : null,
            };
        }

        // Build the conversation path
        const cwPath = `/app/accounts/${this.chatwootAccountId}/inbox-view/conversation/${chatwootConversationId}`;

        let chatwootUrl;

        // For first load, use SSO URL to authenticate
        if (
            this.chatwootSsoUrl &&
            !IframeConversationFieldView.hasSsoAuthenticated()
        ) {
            // Use SSO URL for authentication
            chatwootUrl = this.chatwootSsoUrl;
            IframeConversationFieldView.setSsoAuthenticated();

            // Store pending navigation for after auth
            this.pendingNavigation = cwPath;
        } else {
            // Already authenticated - use direct path
            chatwootUrl = `${this.chatwootBaseUrl}${cwPath}`;
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
                    windowHeight - containerOffset - padding
                );
                $iframe.css("height", availableHeight + "px");
            }
        };

        // Initial height update (with small delay to ensure DOM is ready)
        setTimeout(updateHeight, 100);

        // Update height on window resize
        $(window).on("resize.chatwootIframe", updateHeight);

        // Handle pending navigation after SSO auth
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

        // Clean up on view removal
        this.once("remove", () => {
            $(window).off("resize.chatwootIframe");
        });
    }
}

export default IframeConversationFieldView;

