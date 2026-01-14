/**
 * Custom detail view that adds entity icons to relationship tabs.
 */

import DetailRecordView from "views/record/detail";

class CustomDetailRecordView extends DetailRecordView {
    template = "global:record/detail";

    /**
     * @override
     * @return {{label: string, icon?: string, iconColor?: string}[]}
     */
    getMiddleTabDataList() {
        const currentTab = this.currentTab;
        const panelDataList = this.middlePanelDefsList;

        return panelDataList
            .filter((item, i) => i === 0 || item.tabBreak)
            .map((item, i) => {
                let label = item.tabLabel;
                let hidden = false;
                let icon = null;
                let iconColor = null;

                if (i > 0) {
                    hidden =
                        panelDataList
                            .filter((panel) => panel.tabNumber === i)
                            .findIndex(
                                (panel) =>
                                    !this.recordHelper.getPanelStateParam(
                                        panel.name,
                                        "hidden"
                                    )
                            ) === -1;
                }

                if (!label) {
                    label =
                        i === 0
                            ? this.translate("Overview")
                            : (i + 1).toString();
                } else if (label.substring(0, 7) === "$label:") {
                    label = this.translate(
                        label.substring(7),
                        "labels",
                        this.scope
                    );
                } else if (label[0] === "$") {
                    label = this.translate(
                        label.substring(1),
                        "tabs",
                        this.scope
                    );
                }

                // Try to get entity icon for relationship tabs
                const entityType = this.getTabEntityType(item);
                if (entityType) {
                    icon = this.getMetadata().get([
                        "clientDefs",
                        entityType,
                        "iconClass",
                    ]);
                    iconColor = this.getMetadata().get([
                        "clientDefs",
                        entityType,
                        "color",
                    ]);
                }

                return {
                    label: label,
                    isActive: currentTab === i,
                    hidden: hidden,
                    icon: icon,
                    iconColor: iconColor,
                };
            });
    }

    /**
     * Get the entity type for a tab panel if it contains a relationship-list field.
     *
     * @param {Object} panel The panel definition from middlePanelDefsList
     * @return {string|null} The entity type or null
     */
    getTabEntityType(panel) {
        // Check if panel has a tabEntityType explicitly defined
        if (panel.tabEntityType) {
            return panel.tabEntityType;
        }

        // Find the original layout item by name to get rows
        const layoutItem = this.findLayoutItem(panel.name);
        if (!layoutItem) {
            return null;
        }

        // Look for relationship-list field in rows
        if (!layoutItem.rows || !Array.isArray(layoutItem.rows)) {
            return null;
        }

        for (const row of layoutItem.rows) {
            if (!Array.isArray(row)) continue;

            for (const cell of row) {
                if (!cell) continue;

                // Check if it's a relationship-list view
                const viewName = cell.view || "";
                if (
                    viewName.includes("relationship-list") ||
                    viewName.includes("views/fields/relationship-list")
                ) {
                    // Get the link from options
                    const link = cell.options?.link || cell.link;
                    if (link) {
                        // Get the foreign entity type from link definition
                        const linkDefs = this.model.defs?.links?.[link];
                        if (linkDefs?.entity) {
                            return linkDefs.entity;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find a layout item by panel name in the original detailLayout.
     *
     * @param {string} panelName The panel name
     * @return {Object|null} The layout item or null
     */
    findLayoutItem(panelName) {
        if (!this.detailLayout || !Array.isArray(this.detailLayout)) {
            return null;
        }

        for (let i = 0; i < this.detailLayout.length; i++) {
            const item = this.detailLayout[i];
            const itemName = item.name || "panel-" + i.toString();

            if (itemName === panelName) {
                return item;
            }
        }

        return null;
    }
}

export default CustomDetailRecordView;

