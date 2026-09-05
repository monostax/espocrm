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
 * Bridge view for the "Oportunidades" (Opportunity) dashboard app tab.
 *
 * Listens for Chatwoot appContext, resolves the ChatwootConversation
 * entity by chatwootConversationId, and renders its "Opportunities"
 * relationship panel inline (same panel as the ChatwootConversation
 * detail view: create / select / unlink row actions, `listSmallChatwoot`
 * layout).
 */
class OpportunityBridgeView extends View {
    template = "chatwoot:chatwoot-conversation/bridge";

    /** Dashboard app id this bridge reports its count for. */
    dashboardAppId = "fixed-opportunity";

    /** Panel (= ChatwootConversation link) rendered inline and used for the tab count. */
    panelName = "opportunities";

    /** @type {'loading'|'not-found'|'error'|'ready'} */
    bridgeState = "loading";

    /** @type {string} */
    errorMessage = "";

    /** Last rendered context, e.g. "conversation:123". */
    lastContextKey = null;

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
            this._setState("not-found", "No conversation ID received from Chatwoot.");
            return;
        }

        this._handleContext(`conversation:${conversationId}`, () =>
            this._lookupConversationAndRender(conversationId, accountId),
        );
    }

    /**
     * Run `render` unless the same context is already displayed.
     * @param {string} key
     * @param {function(): Promise<void>} render
     */
    async _handleContext(key, render) {
        if (key === this.lastContextKey) {
            return;
        }

        this.lastContextKey = key;

        this._setState("loading");

        try {
            await render();
        } catch (e) {
            console.error("OpportunityBridge: Failed to load record:", e);
            this._setState("error", "Failed to load conversation from CRM.");
        }
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
     * Look up the ChatwootConversation by its Chatwoot ids and render its
     * Opportunities panel.
     * @param {number} chatwootConversationId
     * @param {number|undefined} chatwootAccountId
     */
    async _lookupConversationAndRender(chatwootConversationId, chatwootAccountId) {
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

        await this._renderPanels("ChatwootConversation", response.list[0].id);
    }

    /**
     * Post the related-records total to the Chatwoot parent window so it
     * can render a tab badge.
     * @param {number} count
     */
    _postCount(count) {
        try {
            window.parent.postMessage(
                JSON.stringify({
                    event: "dashboardAppCount",
                    appId: this.dashboardAppId,
                    count: count > 0 ? count : 0,
                }),
                "*",
            );
        } catch (e) {
            console.error("OpportunityBridge: Failed to post count:", e);
        }
    }

    /**
     * Load the record and render its Opportunities panel.
     * @param {string} entityType
     * @param {string} entityId
     */
    async _renderPanels(entityType, entityId) {
        const model = await this.getModelFactory().create(entityType);

        model.id = entityId;

        await model.fetch();

        this._setState("ready");

        const panelsView = await this.createView(
            "panels",
            "chatwoot:views/opportunity/panels",
            {
                model,
                scope: entityType,
                selector: ".bridge-detail-container",
                type: "detail",
                readOnly: false,
                recordHelper: new ViewRecordHelper(),
                recordViewObject: this,
            },
        );

        await panelsView.render();

        this._syncCount(panelsView);
    }

    /**
     * Keep the tab badge in sync with the panel's collection: the panel
     * re-fetches after create / select / unlink, and every fetch carries
     * the related total.
     * @param {import('views/record/panels-container').default} panelsView
     */
    _syncCount(panelsView) {
        if (this._countCollection) {
            this.stopListening(this._countCollection);
            this._countCollection = null;
        }

        const panelView = panelsView.getPanelView(this.panelName);
        const collection = panelView?.collection;

        if (!collection) {
            return;
        }

        this._countCollection = collection;

        const post = () => this._postCount(collection.total);

        // Already fetched (unlikely race with the panel's first fetch).
        if (collection.lastSyncPromise?.getReadyState() === 4) {
            post();
        }

        this.listenTo(collection, "sync", post);
    }
}

export default OpportunityBridgeView;
