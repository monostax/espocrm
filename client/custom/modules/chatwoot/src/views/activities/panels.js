/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import DetailBottomRecordView from "views/record/detail-bottom";

/**
 * Renders a fixed subset of a record's `sidePanels.detail` panels, without
 * the record header/fields or the stream panel — same layout as the record
 * detail side panels.
 *
 * By default it shows the "Atividades (Planejadas)" / "Atividades (Realizadas)"
 * panels. Subclasses (or the `panelNameList` option) pick other panels,
 * e.g. `chatwoot:views/opportunity/panels` shows only `opportunities`.
 *
 * The panel views are taken from the scope's `sidePanels.detail` clientDefs
 * (falling back to `app.clientRecord.panels`), so e.g. Opportunity uses
 * `crm:views/opportunity/record/panels/activities` exactly as its detail
 * view does. ChatwootConversation needs dedicated views (its activities are
 * related through many-to-many links), which are set in `panelViewMap`.
 *
 * Used by the Chatwoot "Atividades" dashboard app tab.
 */
class ActivitiesPanelsView extends DetailBottomRecordView {
    // No stream panel for this embedded, focused view.
    streamPanel = false;

    // Panels to show, in display order. Overridable via `options.panelNameList`.
    panelNameList = ["activities", "history"];

    // Per-scope panel view overrides.
    panelViewMap = {
        ChatwootConversation: {
            activities: "chatwoot:views/activities/activities-panel",
            history: "chatwoot:views/activities/history-panel",
        },
    };

    setup() {
        this.type = this.mode;

        if ("type" in this.options) {
            this.type = this.options.type;
        }

        this.panelList = [];

        this.setupInitial();

        const panelNameList = this.options.panelNameList || this.panelNameList;

        panelNameList.forEach((name) => {
            const p = this.getActivityPanelDefs(name);

            if (p.aclScope && !this.getAcl().checkScope(p.aclScope)) {
                return;
            }

            this.panelList.push(p);
        });

        this.panelList = this.panelList.map((p) => {
            const item = Espo.Utils.clone(p);

            if (this.recordHelper.getPanelStateParam(p.name, "hidden") !== null) {
                item.hidden = this.recordHelper.getPanelStateParam(
                    p.name,
                    "hidden",
                );
            } else {
                this.recordHelper.setPanelStateParam(
                    p.name,
                    "hidden",
                    item.hidden || false,
                );
            }

            return item;
        });

        this.panelList.forEach((item) => {
            item.actionsViewKey = item.name + "Actions";
        });

        this.alterPanels();
        this.setupPanelsFinal();
        this.setupPanelViews();
    }

    /**
     * Build the panel defs for `name` the same way `views/record/detail-side`
     * does: `app.clientRecord.panels.<name>` defaults, overlaid with the
     * scope's `sidePanels.detail` item of that name, then the per-scope
     * view override.
     *
     * @param {string} name
     * @return {Object.<string, *>}
     */
    getActivityPanelDefs(name) {
        const defaults =
            this.getMetadata().get(["app", "clientRecord", "panels", name]) ||
            {};

        const sideItem =
            (
                this.getMetadata().get([
                    "clientDefs",
                    this.scope,
                    "sidePanels",
                    "detail",
                ]) || []
            ).find((item) => item.name === name) || {};

        const p = Espo.Utils.cloneDeep({ ...defaults, ...sideItem, name });

        const view = (this.panelViewMap[this.scope] || {})[name];

        if (view) {
            p.view = view;
        }

        if (["activities", "history"].includes(name)) {
            p.recordListView = "chatwoot:views/activities/table";
        }

        return p;
    }
}

export default ActivitiesPanelsView;
