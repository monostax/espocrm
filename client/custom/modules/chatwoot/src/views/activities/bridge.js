/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import View from "view";
import ViewRecordHelper from "view-record-helper";

/**
 * Bridge view for the "Agendamentos" (Activities) dashboard app tab.
 *
 * Listens for Chatwoot appContext, resolves the ChatwootConversation
 * entity by chatwootConversationId, loads the model, and renders the
 * Appointment / Meeting / Task relationship panels inline.
 */
class ActivitiesBridgeView extends View {
    template = "chatwoot:chatwoot-conversation/bridge";

    /** Dashboard app id this bridge reports its count for. */
    dashboardAppId = "fixed-activities";

    /** Related links whose totals are summed into the tab count. */
    countLinkList = ["appointments", "meetings", "tasks"];

    /** @type {'loading'|'not-found'|'error'|'ready'} */
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
                "ActivitiesBridge: Failed to request context from parent:",
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
            this._setState("not-found", "No conversation ID received from Chatwoot.");
            return;
        }

        // Avoid re-fetching if same conversation
        if (conversationId === this.lastChatwootConversationId) {
            return;
        }

        this.lastChatwootConversationId = conversationId;
        this._lookupAndRender(conversationId, accountId);
    }

    /**
     * Update the bridge state and re-render the shell.
     * @param {'loading'|'not-found'|'error'|'ready'} state
     * @param {string} [message]
     */
    _setState(state, message) {
        this.bridgeState = state;
        this.errorMessage = message || "";

        if (this.isRendered()) {
            this.reRender();
        }
    }

    /**
     * Look up the ChatwootConversation, load the model, and render the
     * activity relationship panels.
     * @param {number} chatwootConversationId
     * @param {number|undefined} chatwootAccountId
     */
    async _lookupAndRender(chatwootConversationId, chatwootAccountId) {
        this._setState("loading");

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
                this._setState(
                    "not-found",
                    `Conversation #${chatwootConversationId} not found in CRM.`,
                );
                return;
            }

            const entityId = response.list[0].id;

            this._postCount(entityId);

            await this._renderPanels(entityId);
        } catch (e) {
            console.error(
                "ActivitiesBridge: Failed to look up conversation:",
                e,
            );
            this._setState("error", "Failed to load conversation from CRM.");
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
            console.error("ActivitiesBridge: Failed to post count:", e);
        }
    }

    /**
     * Load the ChatwootConversation model and render the activity panels.
     * @param {string} entityId
     */
    async _renderPanels(entityId) {
        const model = await this.getModelFactory().create("ChatwootConversation");

        model.id = entityId;

        await model.fetch();

        this._setState("ready");

        await this.createView("panels", "chatwoot:views/activities/panels", {
            model,
            scope: "ChatwootConversation",
            selector: ".bridge-detail-container",
            type: "detail",
            readOnly: false,
            recordHelper: new ViewRecordHelper(),
            recordViewObject: this,
        });

        await this.getView("panels").render();
    }
}

export default ActivitiesBridgeView;
