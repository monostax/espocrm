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
import ChatwootSsoManager from "chatwoot:chatwoot-sso-manager";

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

    /** @type {function|null} SSO monitoring cleanup */
    _ssoCleanup = null;

    templateContent = `
        {{#if hasConversation}}
        <div class="chatwoot-conversation-iframe-container" style="width: 100%; overflow: hidden;">
            <iframe
                src="{{chatwootUrl}}"
                style="width: 100%; height: 600px; border: none; margin: 0; padding: 0; border-radius: 4px;"
                frameborder="0"
                allowfullscreen
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

        // Get the chatwoot account ID from AppParams (user's linked account)
        this.chatwootAccountId = this.appParams.get("chatwootAccountId");

        // Get SSO URL for authentication
        this.chatSsoUrl = this.appParams.get("chatSsoUrl");

        // Get frontend URL from AppParams (from ChatwootPlatform)
        this.chatwootBaseUrl = this.appParams.get("chatwootFrontendUrl");

        // Setup listener for chatwoot route changes
        this.setupChatwootListener();

        // Listen for model sync to re-render when data is loaded
        this.listenTo(this.model, "sync", () => {
            // Clean up previous SSO monitoring before re-render
            if (this._ssoCleanup) {
                this._ssoCleanup();
                this._ssoCleanup = null;
            }
            if (this.isRendered()) {
                this.reRender();
            }
        });

        // Clean up SSO monitoring when view is removed
        this.once("remove", () => {
            if (this._ssoCleanup) {
                this._ssoCleanup();
                this._ssoCleanup = null;
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
                          "Your user is not linked to a Chat / Account",
                      )
                    : !chatwootConversationId
                      ? this.translate("No conversation ID available")
                      : null,
            };
        }

        // Build the conversation path
        const cwPath = `/app/accounts/${this.chatwootAccountId}/inbox-view/conversation/${chatwootConversationId}`;

        // Use the centralized SSO manager to determine the URL
        const { url, needsSso, pendingPath } =
            ChatwootSsoManager.getIframeUrl(
                this.chatwootBaseUrl,
                this.chatSsoUrl,
                cwPath,
            );

        // If SSO is needed, set up monitoring BEFORE render
        if (needsSso) {
            this._ssoCleanup = ChatwootSsoManager.setupSsoMonitoring({
                chatwootBaseUrl: this.chatwootBaseUrl,
                pendingPath: pendingPath,
                ssoUrl: this.chatSsoUrl,
                getIframe: () => {
                    const el = this.$el
                        ? this.$el.find("iframe")[0]
                        : null;
                    return el || null;
                },
                onConfirmed: () => {
                    console.log(
                        "IframeConversationFieldView: SSO confirmed",
                    );
                },
                onFailed: () => {
                    console.error(
                        "IframeConversationFieldView: SSO failed after retries",
                    );
                },
            });
        }

        return {
            hasConversation: true,
            chatwootUrl: url,
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
