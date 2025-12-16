/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("global:views/opportunity/record/kanban", [
    "crm:views/opportunity/record/kanban",
], function (Dep) {
    return Dep.extend({
        // Use opportunityStageId as the status field (the actual ID column)
        statusField: "opportunityStageId",

        // Current funnel selection
        currentFunnelId: null,
        currentFunnelName: null,

        // Debug flag to prevent infinite loops
        _isFetching: false,
        _buildRowsCount: 0,

        template: "global:opportunity/record/kanban",

        data: function () {
            console.log("[Kanban] data() called", {
                currentFunnelId: this.currentFunnelId,
                currentFunnelName: this.currentFunnelName,
            });
            return {
                ...Dep.prototype.data.call(this),
                currentFunnelId: this.currentFunnelId,
                currentFunnelName: this.currentFunnelName,
                hasFunnelSelector: true,
            };
        },

        setup: function () {
            console.log("[Kanban] setup() START");

            // Get funnel from URL params or storage
            this.currentFunnelId =
                this.options.funnelId ||
                this.getStorage().get("state", "opportunityKanbanFunnelId") ||
                null;

            this.currentFunnelName =
                this.getStorage().get("state", "opportunityKanbanFunnelName") ||
                null;

            console.log("[Kanban] setup() funnel info", {
                currentFunnelId: this.currentFunnelId,
                currentFunnelName: this.currentFunnelName,
            });

            Dep.prototype.setup.call(this);

            // Override status field to use opportunityStageId
            this.statusField = "opportunityStageId";

            console.log(
                "[Kanban] setup() statusField set to:",
                this.statusField
            );

            // If no funnel selected, try to get default
            if (!this.currentFunnelId) {
                console.log("[Kanban] No funnel selected, loading default...");
                this.loadDefaultFunnel();
            }

            // Set up action handlers
            this.addActionHandler("selectFunnel", () =>
                this.actionSelectFunnel()
            );

            console.log("[Kanban] setup() END");
        },

        /**
         * Load the default funnel for the user.
         */
        loadDefaultFunnel: function () {
            console.log("[Kanban] loadDefaultFunnel() START");

            Espo.Ajax.getRequest("Funnel", {
                select: "id,name,isDefault",
                where: [
                    {
                        type: "isTrue",
                        attribute: "isActive",
                    },
                ],
                orderBy: "isDefault",
                order: "desc",
                maxSize: 1,
            })
                .then((response) => {
                    console.log(
                        "[Kanban] loadDefaultFunnel() response:",
                        response
                    );

                    if (response.list && response.list.length > 0) {
                        const funnel = response.list[0];
                        console.log(
                            "[Kanban] loadDefaultFunnel() setting funnel:",
                            funnel
                        );
                        this.setFunnel(funnel.id, funnel.name);
                    } else {
                        console.warn(
                            "[Kanban] loadDefaultFunnel() NO FUNNELS FOUND!"
                        );
                    }
                })
                .catch((error) => {
                    console.error("[Kanban] loadDefaultFunnel() ERROR:", error);
                });
        },

        /**
         * Set the current funnel and reload the Kanban.
         */
        setFunnel: function (funnelId, funnelName) {
            console.log("[Kanban] setFunnel() called", {
                funnelId,
                funnelName,
            });

            this.currentFunnelId = funnelId;
            this.currentFunnelName = funnelName;

            // Save to storage for persistence
            this.getStorage().set(
                "state",
                "opportunityKanbanFunnelId",
                funnelId
            );
            this.getStorage().set(
                "state",
                "opportunityKanbanFunnelName",
                funnelName
            );

            console.log("[Kanban] setFunnel() calling applyFunnelFilter...");
            // Update the collection filter and reload
            this.applyFunnelFilter();

            console.log("[Kanban] setFunnel() calling reRender...");
            // Rerender
            this.reRender();
        },

        /**
         * Apply funnel filter to the collection.
         */
        applyFunnelFilter: function () {
            console.log("[Kanban] applyFunnelFilter() called", {
                hasCollection: !!this.collection,
                currentFunnelId: this.currentFunnelId,
                isFetching: this._isFetching,
            });

            if (!this.collection) {
                console.warn(
                    "[Kanban] applyFunnelFilter() NO COLLECTION - returning early"
                );
                return;
            }

            // Prevent double fetch
            if (this._isFetching) {
                console.warn(
                    "[Kanban] applyFunnelFilter() ALREADY FETCHING - skipping"
                );
                return;
            }

            // Add funnelId to the collection's where clause
            const where = this.collection.where || [];

            // Remove existing funnel filter
            const filteredWhere = where.filter(
                (item) => item.attribute !== "funnelId"
            );

            if (this.currentFunnelId) {
                filteredWhere.push({
                    type: "equals",
                    attribute: "funnelId",
                    value: this.currentFunnelId,
                });
            }

            this.collection.where = filteredWhere;

            console.log(
                "[Kanban] applyFunnelFilter() where clause:",
                this.collection.where
            );
            console.log(
                "[Kanban] applyFunnelFilter() collection.url:",
                this.collection.url
            );

            // Reload the collection
            console.log("[Kanban] applyFunnelFilter() FETCHING...");
            this._isFetching = true;

            this.collection
                .fetch()
                .then(() => {
                    console.log("[Kanban] applyFunnelFilter() FETCH COMPLETE");
                    this._isFetching = false;
                })
                .catch((err) => {
                    console.error(
                        "[Kanban] applyFunnelFilter() FETCH ERROR:",
                        err
                    );
                    this._isFetching = false;
                });
        },

        /**
         * Open funnel selection modal.
         */
        actionSelectFunnel: function () {
            const viewName =
                this.getMetadata().get([
                    "clientDefs",
                    "Funnel",
                    "modalViews",
                    "select",
                ]) || "views/modals/select-records";

            this.createView("dialog", viewName, {
                scope: "Funnel",
                multiple: false,
                createButton: false,
                primaryFilterName: "active",
                boolFilterList: ["onlyActive"],
                forceSelectAllAttributes: true,
            }).then((view) => {
                view.render();

                this.listenToOnce(view, "select", (model) => {
                    this.setFunnel(model.id, model.get("name"));
                    view.close();
                });
            });
        },

        /**
         * Override to apply funnel filter before fetching.
         */
        getCollectionUrl: function () {
            let url = Dep.prototype.getCollectionUrl
                ? Dep.prototype.getCollectionUrl.call(this)
                : this.collection.url;

            return url;
        },

        /**
         * Override buildRows to ensure funnel filter is applied.
         */
        buildRows: function (callback) {
            this._buildRowsCount++;
            console.log(
                "[Kanban] buildRows() called - count:",
                this._buildRowsCount,
                {
                    hasGroupRawDataList: !!this.groupRawDataList,
                    groupRawDataListLength: this.groupRawDataList?.length,
                    currentFunnelId: this.currentFunnelId,
                }
            );

            // Log the raw data if available
            if (this.groupRawDataList) {
                console.log(
                    "[Kanban] buildRows() groupRawDataList:",
                    this.groupRawDataList
                );
            }

            // IMPORTANT: Don't call applyFunnelFilter here - it causes infinite loop!
            // The funnel filter should be applied before the initial fetch, not in buildRows
            // this.applyFunnelFilter();  // <-- This was causing the infinite loop!

            Dep.prototype.buildRows.call(
                this,
                function () {
                    console.log("[Kanban] buildRows() callback - COMPLETE");
                    if (callback) {
                        callback();
                    }
                }.bind(this)
            );
        },

        afterRender: function () {
            console.log("[Kanban] afterRender() called", {
                groupDataListLength: this.groupDataList?.length,
                isRendered: this.isRendered,
            });

            Dep.prototype.afterRender.call(this);

            // Update funnel selector display
            this.updateFunnelSelectorDisplay();

            console.log("[Kanban] afterRender() COMPLETE");
        },

        /**
         * Update the funnel selector UI.
         */
        updateFunnelSelectorDisplay: function () {
            const $selector = this.$el.find(".funnel-selector-name");

            if ($selector.length) {
                $selector.text(
                    this.currentFunnelName ||
                        this.translate("Select Funnel", "labels", "Opportunity")
                );
            }
        },

        handleAttributesOnGroupChange: function (model, attributes, group) {
            // When moving cards between columns, update the opportunityStageId
            // The 'group' parameter is the OpportunityStage ID from the kanban column
            if (this.statusField !== "opportunityStageId") {
                return;
            }

            // Set the opportunityStageId (this is what gets saved)
            attributes["opportunityStageId"] = group;

            // Fetch the stage to get its probability and name
            Espo.Ajax.getRequest("OpportunityStage/" + group).then((stage) => {
                if (stage) {
                    if (stage.probability !== undefined) {
                        attributes["probability"] = stage.probability;
                    }
                    // Also update the display name
                    if (stage.name) {
                        attributes["opportunityStageName"] = stage.name;
                    }
                }
            });
        },
    });
});

