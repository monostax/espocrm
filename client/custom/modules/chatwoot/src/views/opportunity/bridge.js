/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import View from "view";

/**
 * Bridge view for the Opportunity dashboard app tab.
 *
 * Listens for Chatwoot appContext, resolves the ChatwootConversation
 * entity by chatwootConversationId, and navigates to the related
 * Opportunity list for that conversation.
 */
class OpportunityBridgeView extends View {
    template = "chatwoot:chatwoot-conversation/bridge";

    /** Dashboard app id this bridge reports its count for. */
    dashboardAppId = "fixed-opportunity";

    /** Related links whose totals are summed into the tab count. */
    countLinkList = ["opportunities"];

    /** @type {'loading'|'not-found'|'error'} */
    bridgeState = "loading";

    /** @type {string} */
    errorMessage = "";

    /** @type {number|null} */
    lastChatwootConversationId = null;

    setup() {
        this._boundMessageHandler = this._onMessage.bind(this);
        window.addEventListener("message", this._boundMessageHandler);

        this._requestContext();
    }

    onRemove() {
        // Keep global listener alive for conversation changes
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
                "OpportunityBridge: Failed to request context from parent:",
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
     * related Opportunity list.
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

            this._postCount(entityId);

            // Navigate to the related Opportunity list for this conversation
            this.getRouter().navigate(
                `#ChatwootConversation/related/${entityId}/opportunities`,
                { trigger: true },
            );
        } catch (e) {
            console.error(
                "OpportunityBridge: Failed to look up conversation:",
                e,
            );
            this.bridgeState = "error";
            this.errorMessage = "Failed to load conversation from CRM.";
            if (this.isRendered()) {
                this.reRender();
            }
        }
    }

    /**
     * Fetch the total of each related link, sum them, and post the
     * result to the Chatwoot parent window so it can render a tab badge.
     * @param {string} entityId
     */
    async _postCount(entityId) {
        try {
            const totals = await Promise.all(
                this.countLinkList.map(async (link) => {
                    const res = await Espo.Ajax.getRequest(
                        `ChatwootConversation/${entityId}/${link}`,
                        { maxSize: 1, select: "id" },
                    );

                    return res.total > 0 ? res.total : 0;
                }),
            );

            const count = totals.reduce((sum, total) => sum + total, 0);

            window.parent.postMessage(
                JSON.stringify({
                    event: "dashboardAppCount",
                    appId: this.dashboardAppId,
                    count,
                }),
                "*",
            );
        } catch (e) {
            console.error("OpportunityBridge: Failed to post count:", e);
        }
    }
}

export default OpportunityBridgeView;
