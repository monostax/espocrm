/************************************************************************
 * This file is part of Monostax.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 ************************************************************************/

import ActivitiesPanelsView from "chatwoot:views/activities/panels";

/** Use the native Opportunity contacts relationship, including select/create/unlink. */
class OpportunityContactsPanelsView extends ActivitiesPanelsView {
    panelNameList = ["contacts"];

    getActivityPanelDefs(name) {
        return {
            ...this.getMetadata().get(["clientDefs", "Opportunity", "relationshipPanels", name]),
            name,
            link: name,
            view: "chatwoot:views/activities/relationship-panel",
            aclScope: "Contact",
        };
    }
}

export default OpportunityContactsPanelsView;
