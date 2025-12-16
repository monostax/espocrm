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

            // Listen for collection sync to log when groups are updated
            // The base class already has a sync listener that updates groupRawDataList
            // and calls buildRowsAndRender(), so we just add debugging here
            this.listenTo(this.collection, "sync", (collection, response) => {
                console.log("[Kanban] SYNC event fired", {
                    hasResponse: !!response,
                    hasGroups: !!response?.groups,
                    groupCount: response?.groups?.length,
                    groupNames: response?.groups
                        ?.map((g) => g.name || g.label)
                        .join(", "),
                    currentFunnelId: this.currentFunnelId,
                });

                // Schedule aggregates refresh after sync
                this.scheduleAggregatesRefresh();
            });

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
                previousFunnelId: this.currentFunnelId,
                previousFunnelName: this.currentFunnelName,
                newFunnelId: funnelId,
                newFunnelName: funnelName,
                isSameFunnel: this.currentFunnelId === funnelId,
            });

            // Warn if switching to the same funnel
            if (this.currentFunnelId === funnelId) {
                console.warn(
                    "[Kanban] setFunnel() SAME FUNNEL - no change expected"
                );
            }

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

            // Update funnel selector display immediately (without full re-render)
            this.updateFunnelSelectorDisplay();

            console.log("[Kanban] setFunnel() calling applyFunnelFilter...");
            // Update the collection filter and reload
            // The sync handler will rebuild rows and re-render with new stages
            this.applyFunnelFilter();

            // NOTE: Don't call reRender() here - it renders with OLD groupRawDataList
            // before the fetch completes. The collection's 'sync' event listener
            // (set up in the base kanban view) will call buildRowsAndRender()
            // which rebuilds groupDataList with the new stages and re-renders.
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

            // Store previous group count to detect changes
            const previousGroupCount = this.groupRawDataList?.length || 0;
            const previousGroupNames =
                this.groupRawDataList?.map((g) => g.name).join(",") || "";

            this.collection
                .fetch()
                .then(() => {
                    console.log("[Kanban] applyFunnelFilter() FETCH COMPLETE", {
                        newGroupCount: this.groupRawDataList?.length,
                        newGroupNames: this.groupRawDataList
                            ?.map((g) => g.name)
                            .join(","),
                        previousGroupCount,
                        previousGroupNames,
                        groupsChanged:
                            previousGroupNames !==
                            (this.groupRawDataList
                                ?.map((g) => g.name)
                                .join(",") || ""),
                    });
                    this._isFetching = false;

                    // The sync event should have updated groupRawDataList and triggered buildRowsAndRender.
                    // Log the current state to debug if columns didn't update.
                    console.log(
                        "[Kanban] applyFunnelFilter() groupRawDataList after fetch:",
                        this.groupRawDataList
                    );
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

                    // Calculate count and amount sum for each group
                    this.calculateGroupStats();

                    if (callback) {
                        callback();
                    }
                }.bind(this)
            );
        },

        /**
         * Calculate count and amount sum for each group/lane.
         * Initially uses local data, then fetches server aggregates for accurate totals.
         */
        calculateGroupStats: function () {
            if (!this.groupDataList) {
                return;
            }

            // First, set initial values from local data (for immediate display)
            this.groupDataList.forEach((group) => {
                // Count from local collection
                group.count = group.collection ? group.collection.total : 0;
                if (group.count === -1) {
                    group.count = group.collection
                        ? group.collection.length
                        : 0;
                }

                // Calculate sum from loaded models (partial sum)
                let amountSum = 0;
                if (group.collection && group.collection.models) {
                    group.collection.models.forEach((model) => {
                        const amount = model.get("amount") || 0;
                        amountSum += parseFloat(amount) || 0;
                    });
                }
                group.amountSum = amountSum;
                group.amountSumFormatted = this.formatCurrency(amountSum);
            });

            // Fetch accurate aggregates from server
            this.fetchKanbanAggregates();

            console.log(
                "[Kanban] calculateGroupStats() initial calculation COMPLETE",
                this.groupDataList
            );
        },

        /**
         * Fetch aggregated count and sum from server for accurate totals.
         */
        fetchKanbanAggregates: function () {
            if (!this.currentFunnelId) {
                console.log(
                    "[Kanban] fetchKanbanAggregates() - No funnel ID, skipping"
                );
                return;
            }

            Espo.Ajax.getRequest("Opportunity/action/kanbanAggregates", {
                funnelId: this.currentFunnelId,
            })
                .then((response) => {
                    console.log(
                        "[Kanban] fetchKanbanAggregates() response:",
                        response
                    );

                    if (response && response.aggregates) {
                        this.applyServerAggregates(response.aggregates);
                    }
                })
                .catch((error) => {
                    console.error(
                        "[Kanban] fetchKanbanAggregates() ERROR:",
                        error
                    );
                });
        },

        /**
         * Apply server aggregates to group data and update the UI.
         */
        applyServerAggregates: function (aggregates) {
            if (!this.groupDataList) {
                return;
            }

            this.groupDataList.forEach((group) => {
                const stageId = group.name;
                const serverData = aggregates[stageId];

                if (serverData) {
                    group.count = serverData.count;
                    group.amountSum = serverData.amountSum;
                    group.amountSumFormatted = this.formatCurrency(
                        serverData.amountSum
                    );
                }
            });

            // Update the UI with the new values
            this.updateGroupStatsUI();

            console.log(
                "[Kanban] applyServerAggregates() COMPLETE",
                this.groupDataList
            );
        },

        /**
         * Update the group stats in the UI without full re-render.
         */
        updateGroupStatsUI: function () {
            if (!this.groupDataList || !this.$el) {
                return;
            }

            this.groupDataList.forEach((group) => {
                // Update visual kanban board
                const $visualColumn = this.$el.find(
                    `.kanban-board .kanban-column[data-name="${group.name}"]`
                );

                if ($visualColumn.length) {
                    $visualColumn.find(".kanban-group-count").text(group.count);
                    $visualColumn
                        .find(".kanban-group-amount")
                        .text(group.amountSumFormatted);
                }

                // Also update original hidden structure for compatibility
                const $header = this.$el.find(
                    `.kanban-head .group-header[data-name="${group.name}"]`
                );

                if ($header.length) {
                    $header.find(".kanban-group-count").text(group.count);
                    $header
                        .find(".kanban-group-amount")
                        .text(group.amountSumFormatted);
                }
            });
        },

        /**
         * Format currency value for display.
         */
        formatCurrency: function (value) {
            const currency = this.getConfig().get("defaultCurrency") || "BRL";
            const decimalMark = this.getConfig().get("decimalMark") || ",";
            const thousandSeparator =
                this.getConfig().get("thousandSeparator") || ".";

            // Format number with thousands separator
            const parts = value.toFixed(2).split(".");
            parts[0] = parts[0].replace(
                /\B(?=(\d{3})+(?!\d))/g,
                thousandSeparator
            );

            const formattedNumber = parts.join(decimalMark);

            return currency + " " + formattedNumber;
        },

        afterRender: function () {
            console.log("[Kanban] afterRender() called", {
                groupDataListLength: this.groupDataList?.length,
                isRendered: this.isRendered,
            });

            Dep.prototype.afterRender.call(this);

            // Update funnel selector display
            this.updateFunnelSelectorDisplay();

            // Setup visual kanban sync
            this.setupVisualKanban();

            console.log("[Kanban] afterRender() COMPLETE");
        },

        /**
         * Setup the visual kanban board synchronization.
         * Syncs items between the modern visual board and the hidden original structure.
         */
        setupVisualKanban: function () {
            // Sync items from original structure to visual board
            this.syncItemsToVisualBoard();

            // Setup drag and drop for visual board
            this.setupVisualDragDrop();
        },

        /**
         * Sync items from the original hidden kanban structure to the visual board.
         */
        syncItemsToVisualBoard: function () {
            if (!this.groupDataList) {
                return;
            }

            this.groupDataList.forEach((group) => {
                const $originalColumn = this.$el.find(
                    `.kanban-columns-container .group-column-list[data-name="${group.name}"]`
                );
                const $visualColumn = this.$el.find(
                    `.kanban-board .group-column-list-visual[data-name="${group.name}"]`
                );

                if ($originalColumn.length && $visualColumn.length) {
                    // Clone items from original to visual
                    const $items = $originalColumn.find(".item").clone(true);

                    if ($items.length > 0) {
                        $visualColumn.empty().append($items);
                    } else {
                        // Show empty placeholder
                        $visualColumn.html(
                            `<div class="kanban-empty-placeholder">${
                                this.translate(
                                    "Drop here",
                                    "labels",
                                    "Opportunity"
                                ) || "Drop here"
                            }</div>`
                        );
                    }
                }
            });
        },

        /**
         * Setup drag and drop for the visual kanban board.
         */
        setupVisualDragDrop: function () {
            const self = this;

            // Make visual items draggable
            this.$el.find(".kanban-board .item").each(function () {
                const $item = $(this);
                $item.attr("draggable", "true");

                $item.on("dragstart", function (e) {
                    e.originalEvent.dataTransfer.setData(
                        "text/plain",
                        $(this).data("id")
                    );
                    $(this).addClass("dragging");
                    self.$el
                        .find(".kanban-column-content")
                        .addClass("drag-active");
                });

                $item.on("dragend", function () {
                    $(this).removeClass("dragging");
                    self.$el
                        .find(".kanban-column-content")
                        .removeClass("drag-active");
                    self.$el
                        .find(".kanban-empty-placeholder")
                        .removeClass("drag-over");
                });
            });

            // Setup drop zones
            this.$el.find(".kanban-column-content").each(function () {
                const $dropZone = $(this);
                const groupName = $dropZone
                    .find(".group-column-list-visual")
                    .data("name");

                $dropZone.on("dragover", function (e) {
                    e.preventDefault();
                    $(this)
                        .find(".kanban-empty-placeholder")
                        .addClass("drag-over");
                });

                $dropZone.on("dragleave", function (e) {
                    if (
                        !$(e.relatedTarget)
                            .closest(".kanban-column-content")
                            .is(this)
                    ) {
                        $(this)
                            .find(".kanban-empty-placeholder")
                            .removeClass("drag-over");
                    }
                });

                $dropZone.on("drop", function (e) {
                    e.preventDefault();
                    const itemId =
                        e.originalEvent.dataTransfer.getData("text/plain");
                    $(this)
                        .find(".kanban-empty-placeholder")
                        .removeClass("drag-over");

                    if (itemId && groupName) {
                        // Trigger the original EspoCRM move functionality
                        self.moveItemToGroup(itemId, groupName);
                    }
                });
            });
        },

        /**
         * Move an item to a new group using EspoCRM's built-in functionality.
         */
        moveItemToGroup: function (itemId, groupName) {
            // Find the model
            const model = this.collection.get(itemId);
            if (!model) {
                return;
            }

            // Get the current group
            const currentGroup = model.get(this.statusField);
            if (currentGroup === groupName) {
                return;
            }

            // Use EspoCRM's built-in move functionality if available
            if (this.moveModelToGroup) {
                this.moveModelToGroup(model, groupName);
                // Refresh aggregates after EspoCRM handles the move
                this.scheduleAggregatesRefresh();
            } else {
                // Fallback: directly update the model
                const attributes = {};
                this.handleAttributesOnGroupChange(
                    model,
                    attributes,
                    groupName
                );
                attributes[this.statusField] = groupName;

                model.save(attributes, {
                    patch: true,
                    success: () => {
                        // Refresh aggregates after successful save
                        this.fetchKanbanAggregates();
                        this.reRender();
                    },
                });
            }
        },

        /**
         * Schedule a refresh of aggregates after a short delay.
         * This allows time for the server to process the move.
         */
        scheduleAggregatesRefresh: function () {
            // Clear any existing timeout to prevent multiple refreshes
            if (this._aggregatesRefreshTimeout) {
                clearTimeout(this._aggregatesRefreshTimeout);
            }

            // Delay the refresh slightly to allow the save to complete
            this._aggregatesRefreshTimeout = setTimeout(() => {
                this.fetchKanbanAggregates();
            }, 500);
        },

        /**
         * Update the funnel selector UI.
         */
        updateFunnelSelectorDisplay: function () {
            // Handle case where view isn't rendered yet
            if (!this.$el || !this.$el.length) {
                return;
            }

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

