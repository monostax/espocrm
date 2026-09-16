/************************************************************************
 * This file is part of Monostax.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 ************************************************************************/

import View from "view";
import ViewRecordHelper from "view-record-helper";

class OpportunityContactsBridgeView extends View {
    template = "chatwoot:chatwoot-conversation/bridge";
    bridgeState = "loading";
    errorMessage = "";
    requestId = 0;

    setup() {
        this.messageHandler = event => this.onContext(event);
        window.addEventListener("message", this.messageHandler);
        window.parent.postMessage("chatwoot-dashboard-app:fetch-info", "*");
    }

    onRemove() {
        this.requestId += 1;
        window.removeEventListener("message", this.messageHandler);
    }

    data() {
        return { bridgeState: this.bridgeState, errorMessage: this.errorMessage };
    }

    onContext(event) {
        if (event.source !== window.parent || typeof event.data !== "string") return;

        let message;
        try {
            message = JSON.parse(event.data);
        } catch {
            return;
        }

        if (message?.event !== "appContext" || !message.data?.opportunity?.id) return;

        this.parentOrigin = event.origin;
        const { opportunity, contactsRevision } = message.data;
        if (opportunity.id === this.opportunityId) {
            if (contactsRevision !== this.contactsRevision) {
                this.contactsRevision = contactsRevision;
                const collection = this.getView("panels")?.getPanelView("contacts")?.collection;
                // Keep the native panel's contents in sync with the live tab count.
                collection?.fetch().catch(() => {});
            }
            return;
        }

        this.contactsRevision = contactsRevision;
        this.loadOpportunity(opportunity.id);
    }

    async loadOpportunity(id) {
        this.opportunityId = id;
        const requestId = ++this.requestId;
        this.clearView("panels");
        this.bridgeState = "loading";
        if (this.isRendered()) this.reRender();

        try {
            const model = await this.getModelFactory().create("Opportunity");
            model.id = id;
            await model.fetch();
            if (requestId !== this.requestId) return;

            this.bridgeState = "ready";
            await this.whenRendered();
            await this.reRender();
            if (requestId !== this.requestId) return;

            const panels = await this.createView("panels", "chatwoot:views/opportunity/contacts-panels", {
                model,
                scope: "Opportunity",
                selector: ".bridge-detail-container",
                type: "detail",
                readOnly: false,
                recordHelper: new ViewRecordHelper(),
                recordViewObject: this,
            });
            if (requestId !== this.requestId) return;
            const collection = panels.getPanelView("contacts")?.collection;
            if (collection) {
                const postCount = () => {
                    if (requestId !== this.requestId || collection.total < 0) return;
                    window.parent.postMessage(JSON.stringify({
                        event: "dashboardAppCount",
                        appId: "fixed-contacts",
                        opportunityId: id,
                        count: collection.total,
                    }), this.parentOrigin);
                };
                this.listenTo(collection, "sync", postCount);
                if (collection.lastSyncPromise?.getReadyState() === 4) postCount();
            }
            await panels.render();
        } catch {
            if (requestId !== this.requestId) return;
            this.opportunityId = null;
            this.bridgeState = "error";
            this.errorMessage = this.translate("Error");
            if (this.isRendered()) this.reRender();
        }
    }
}

export default OpportunityContactsBridgeView;
