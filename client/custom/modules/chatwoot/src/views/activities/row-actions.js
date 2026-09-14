/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import ActivitiesRowActionsView from "crm:views/record/row-actions/activities";

/** Removal is available in the embedded activity tables alongside native actions. */
export default class EmbeddedActivitiesRowActionsView extends ActivitiesRowActionsView {
    getActionList() {
        const list = super.getActionList();

        if (this.options.acl.delete && !this.options.removeDisabled &&
            !this.getMetadata().get(["clientDefs", this.model.entityType, "removeDisabled"])) {
            list.push({
                action: "quickRemove",
                label: "Remove",
                data: { id: this.model.id },
                groupIndex: 1,
                iconClass: "fas fa-times",
            });
        }

        return list;
    }
}
