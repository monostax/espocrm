/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import DetailBottomRecordView from "views/record/detail-bottom";

/**
 * Renders only the "Agendamentos" relationship panels (Appointment,
 * Meeting, Task) for a ChatwootConversation, without the record
 * header/fields or the stream panel.
 *
 * Used by the Chatwoot "Agendamentos" dashboard app tab.
 */
class ActivitiesPanelsView extends DetailBottomRecordView {
    // No stream panel for this embedded, focused view.
    streamPanel = false;

    // Panels to show, in display order.
    activityLinkList = ["appointments", "meetings", "tasks"];

    setup() {
        this.type = this.mode;

        if ("type" in this.options) {
            this.type = this.options.type;
        }

        this.panelList = [];

        this.setupInitial();

        const linkDefs = (this.model.defs || {}).links || {};

        this.activityLinkList.forEach((name) => {
            if (!linkDefs[name]) {
                return;
            }

            this.addRelationshipPanel(name, {
                view: "chatwoot:views/activities/relationship-panel",
            });
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
}

export default ActivitiesPanelsView;
