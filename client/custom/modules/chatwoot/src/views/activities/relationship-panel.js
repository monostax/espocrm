/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import RelationshipPanelView from "views/record/panels/relationship";

/**
 * Relationship panel used by the Chatwoot "Agendamentos" dashboard app tab.
 *
 * Overrides the panel title so its icon matches the standard detail panel
 * style (`panel-icon`, single space before the label) instead of the default
 * inline-flex `scope-icon` markup with a `&nbsp;` separator.
 */
class ActivitiesRelationshipPanelView extends RelationshipPanelView {
    setupTitle() {
        this.title =
            this.title ||
            this.translate(this.link, "links", this.model.entityType);

        const label = this.defs.label
            ? this.translate(this.defs.label, "labels", this.entityType)
            : this.title;

        let iconHtml = "";

        if (!this.getConfig().get("scopeColorsDisabled")) {
            const iconClass = this.getMetadata().get([
                "clientDefs",
                this.entityType,
                "iconClass",
            ]);

            if (iconClass) {
                const color = this.getMetadata().get([
                    "clientDefs",
                    this.entityType,
                    "color",
                ]);
                const style = color ? ` style="color: ${color}"` : "";

                iconHtml = `<span class="panel-icon ${iconClass}"${style}></span> `;
            }
        }

        this.titleHtml = iconHtml + label;

        if (this.filter && this.filter !== "all") {
            this.titleHtml += " &middot; " + this.translateFilter(this.filter);
        }
    }
}

export default ActivitiesRelationshipPanelView;
