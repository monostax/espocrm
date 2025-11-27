/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2025 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import ListView from "views/list";
import MultiCollection from "multi-collection";

class ActivitiesListView extends ListView {
    template = "list";
    scope = "Activities";
    name = "Activities";
    entityType = "Activities";

    setup() {
        // Always include all activity types for this standalone view
        this.scopeList = ["Meeting", "Call", "Task"];

        // Set view type from options
        this.type = this.options.type || "list";

        // Set layout name so parent can load layout from layouts/Activities/list.json
        this.layoutName = this.type;
        this.layoutScope = "Activities";

        // Enable search panel with advanced filters
        this.searchPanel = true;

        // Use custom search view for Activities
        this.searchView = "global:views/activities/search";

        // Define common search fields for activities (these appear in "Add Filter" dropdown)
        this.searchFields = {
            _scope: {
                type: "enum",
                options: ["Meeting", "Call", "Task"],
            },
            name: {
                type: "text",
            },
            status: {
                type: "enum",
                options: [
                    "Planned",
                    "Held",
                    "Not Held",
                    "Not Started",
                    "Started",
                    "Completed",
                    "Canceled",
                    "Deferred",
                ],
            },
            dateStart: {
                type: "datetime",
            },
            dateEnd: {
                type: "datetime",
            },
            assignedUser: {
                type: "link",
            },
            parent: {
                type: "linkParent",
                entityList: ["Account", "Lead", "Contact", "Opportunity"],
            },
            teams: {
                type: "linkMultiple",
            },
            description: {
                type: "text",
            },
            createdAt: {
                type: "datetime",
            },
            modifiedAt: {
                type: "datetime",
            },
        };

        // Define which fields are shown by default in search panel
        this.defaultSearchData = {
            // Can add default filters here if needed
        };

        // Primary filters are now defined in metadata

        // Create seeds for each entity type
        this.seeds = {};
        this.wait(true);

        let loadedCount = 0;
        this.scopeList.forEach((scope) => {
            this.getModelFactory().create(scope, (seed) => {
                this.seeds[scope] = seed;
                loadedCount++;

                if (loadedCount === this.scopeList.length) {
                    this.wait(false);
                }
            });
        });

        if (this.scopeList.length === 0) {
            this.wait(false);
        }

        // Create multi-collection for activities
        this.collection = new MultiCollection();
        this.collection.seeds = this.seeds;
        this.collection.entityType = "Activities"; // Set entityType for layout loading
        this.collection.url = "Activities/action/all"; // Use our custom Global module endpoint
        this.collection.orderBy = "dateStart";
        this.collection.order = "desc";
        this.collection.maxSize = this.getConfig().get("recordsPerPage") || 20;

        // Pass entity type list to the endpoint
        this.collection.data = {
            entityTypeList: this.scopeList,
        };

        super.setup();

        // Setup view mode buttons
        this.setupViewModeButtons();

        // Setup create buttons for each entity type
        this.setupCreateButtons();
    }

    /**
     * Override to pass layoutScope for virtual entity
     */
    prepareRecordViewOptions(options) {
        super.prepareRecordViewOptions(options);

        // For virtual entities (MultiCollection), explicitly set layout scope
        options.layoutScope = "Activities";
        options.layoutName = this.type || "list";
    }

    setupViewModeButtons() {
        this.menu = this.menu || {};
        this.menu.buttons = this.menu.buttons || [];

        if (this.type === "list") {
            this.menu.buttons.push({
                action: "switchToKanban",
                label: "Kanban",
                iconClass: "fas fa-th",
                style: "default",
            });
        } else if (this.type === "kanban") {
            this.menu.buttons.push({
                action: "switchToList",
                label: "List",
                iconClass: "fas fa-list",
                style: "default",
            });
        }

        // Add dropdown for filtering by entity type
        if (this.type === "list" || this.type === "kanban") {
            this.setupEntityTypeFilter();
        }
    }

    setupEntityTypeFilter() {
        // Add entity type filter dropdown
        this.menu.dropdown = this.menu.dropdown || [];

        this.menu.dropdown.push({
            label: "All Types",
            action: "filterEntityType",
            data: {
                entityType: null,
            },
        });

        this.scopeList.forEach((entityType) => {
            this.menu.dropdown.push({
                label: this.translate(entityType, "scopeNamesPlural"),
                action: "filterEntityType",
                data: {
                    entityType: entityType,
                },
            });
        });
    }

    actionFilterEntityType(data) {
        const entityType = data.entityType;

        if (entityType) {
            this.collection.data.entityTypeList = [entityType];
        } else {
            this.collection.data.entityTypeList = this.scopeList;
        }

        this.collection.fetch().then(() => {
            this.reRender();
        });
    }

    setupCreateButtons() {
        this.buttonList = this.buttonList || [];

        this.scopeList.forEach((entityType) => {
            if (!this.getAcl().checkScope(entityType, "create")) {
                return;
            }

            if (this.getMetadata().get(["scopes", entityType, "disabled"])) {
                return;
            }

            this.buttonList.push({
                action: "quickCreate",
                label: "Create " + this.translate(entityType, "scopeNames"),
                style: "default",
                iconClass: this.getMetadata().get([
                    "clientDefs",
                    entityType,
                    "iconClass",
                ]),
                data: {
                    scope: entityType,
                },
            });
        });
    }

    actionSwitchToKanban() {
        this.getRouter().navigate("#Activities/kanban", { trigger: true });
    }

    actionSwitchToList() {
        this.getRouter().navigate("#Activities", { trigger: true });
    }

    actionQuickCreate(data) {
        const scope = data.scope;

        if (!scope) {
            return;
        }

        this.actionQuickCreateSpecific(scope);
    }

    actionQuickCreateSpecific(scope) {
        Espo.Ui.notify(" ... ");

        const attributes = {};

        this.createView(
            "quickCreate",
            "views/modals/edit",
            {
                scope: scope,
                attributes: attributes,
            },
            (view) => {
                view.render();
                view.notify(false);

                this.listenToOnce(view, "after:save", () => {
                    this.collection.fetch();
                });
            }
        );
    }

    getCreateAttributes() {
        return {};
    }

    getHeader() {
        return this.buildHeaderHtml([
            $("<span>").text(this.translate("Activities")),
        ]);
    }

    updatePageTitle() {
        this.setPageTitle(this.translate("Activities"));
    }

    getSearchDefaultData() {
        return (
            this.getMetadata().get("clientDefs.Activities.defaultSearchData") ||
            {}
        );
    }

    getRecordViewName() {
        if (this.type === "kanban") {
            return (
                this.getMetadata().get(
                    "clientDefs.Activities.recordViews.kanban"
                ) || "global:views/activities/record/kanban"
            );
        }

        return (
            this.getMetadata().get("clientDefs.Activities.recordViews.list") ||
            "global:views/activities/record/list"
        );
    }
}

export default ActivitiesListView;

