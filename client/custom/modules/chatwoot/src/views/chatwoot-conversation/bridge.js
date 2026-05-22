/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import View from "view";

/**
 * Bridge view for Chatwoot Dashboard App integration.
 *
 * When EspoCRM is embedded inside a Chatwoot dashboard app iframe,
 * this view:
 * 1. Requests the conversation context from Chatwoot via postMessage
 * 2. Receives the appContext payload (conversation, contact, currentAgent)
 * 3. Looks up the ChatwootConversation entity by chatwootConversationId
 * 4. Navigates to the standard ChatwootConversation detail view
 *
 * On subsequent conversation changes (Chatwoot sends new appContext),
 * the global message listener re-navigates to the new entity.
 */
class ChatwootConversationBridgeView extends View {
    template = "chatwoot:chatwoot-conversation/bridge";

    /** @type {'loading'|'not-found'|'error'} */
    bridgeState = "loading";

    /** @type {string} */
    errorMessage = "";

    /** @type {number|null} */
    lastChatwootConversationId = null;

    setup() {
        this._boundMessageHandler = this._onMessage.bind(this);
        window.addEventListener("message", this._boundMessageHandler);

        // Request context from Chatwoot parent
        this._requestContext();
    }

    onRemove() {
        // Keep the global listener alive — it's cleaned up when the
        // entire iframe is destroyed by Chatwoot. We need it to handle
        // conversation changes even after navigating away from the bridge.
    }

    data() {
        return {
            bridgeState: this.bridgeState,
            errorMessage: this.errorMessage,
        };
    }

    /**
     * Request conversation context from Chatwoot parent window.
     */
    _requestContext() {
        try {
            window.parent.postMessage("chatwoot-dashboard-app:fetch-info", "*");
        } catch (e) {
            console.error(
                "ChatwootConversationBridge: Failed to request context from parent:",
                e,
            );
        }
    }

    /**
     * Handle incoming messages from the parent Chatwoot window.
     * @param {MessageEvent} event
     */
    _onMessage(event) {
        if (typeof event.data !== "string") {
            return;
        }

        let parsed;
        try {
            parsed = JSON.parse(event.data);
        } catch (e) {
            return;
        }

        if (parsed.event !== "appContext" || !parsed.data) {
            return;
        }

        const conversationId = parsed.data.conversation?.id;
        const accountId = parsed.data.conversation?.account_id;

        if (!conversationId) {
            this.bridgeState = "not-found";
            this.errorMessage = "No conversation ID received from Chatwoot.";
            if (this.isRendered()) {
                this.reRender();
            }
            return;
        }

        // Avoid re-fetching if same conversation
        if (conversationId === this.lastChatwootConversationId) {
            return;
        }

        this.lastChatwootConversationId = conversationId;
        this._lookupAndNavigate(conversationId, accountId);
    }

    /**
     * Look up the ChatwootConversation entity and navigate to its
     * standard detail view using the router.
     * @param {number} chatwootConversationId
     * @param {number|undefined} chatwootAccountId
     */
    async _lookupAndNavigate(chatwootConversationId, chatwootAccountId) {
        this.bridgeState = "loading";
        if (this.isRendered()) {
            this.reRender();
        }

        try {
            const where = [
                {
                    type: "equals",
                    attribute: "chatwootConversationId",
                    value: chatwootConversationId,
                },
            ];

            if (chatwootAccountId) {
                where.push({
                    type: "equals",
                    attribute: "chatwootAccountIdExternal",
                    value: chatwootAccountId,
                });
            }

            const response = await Espo.Ajax.getRequest("ChatwootConversation", {
                where,
                maxSize: 1,
                select: "id",
            });

            if (!response.list || response.list.length === 0) {
                this.bridgeState = "not-found";
                this.errorMessage = `Conversation #${chatwootConversationId} not found in CRM.`;
                if (this.isRendered()) {
                    this.reRender();
                }
                return;
            }

            const entityId = response.list[0].id;

            // Navigate to the standard detail view via the router.
            // This renders the full page with header, record, and side panel.
            this.getRouter().navigate(
                `#ChatwootConversation/view/${entityId}`,
                { trigger: true },
            );
        } catch (e) {
            console.error(
                "ChatwootConversationBridge: Failed to look up conversation:",
                e,
            );
            this.bridgeState = "error";
            this.errorMessage = "Failed to load conversation from CRM.";
            if (this.isRendered()) {
                this.reRender();
            }
        }
    }
}

export default ChatwootConversationBridgeView;
