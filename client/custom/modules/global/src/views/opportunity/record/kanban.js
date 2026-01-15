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
        statusField: "opportunityStageId",
        itemViewName: "global:views/opportunity/record/kanban-item",
        
        mandatorySelectAttributeList: [
            "chatwootConversationsIds",
            "chatwootConversationsNames",
            "chatwootConversationsColumns",
        ],

        currentFunnelId: null,
        currentFunnelName: null,
        _isFetching: false,

        template: "global:opportunity/record/kanban",

        data: function () {
            return {
                ...Dep.prototype.data.call(this),
                currentFunnelId: this.currentFunnelId,
                currentFunnelName: this.currentFunnelName,
                hasFunnelSelector: true,
            };
        },

        setup: function () {
            this.currentFunnelId =
                this.options.funnelId ||
                this.getStorage().get("state", "opportunityKanbanFunnelId") ||
                null;

            this.currentFunnelName =
                this.getStorage().get("state", "opportunityKanbanFunnelName") ||
                null;

            Dep.prototype.setup.call(this);

            this.statusField = "opportunityStageId";

            if (!this.currentFunnelId) {
                this.loadDefaultFunnel();
            }

            this.addActionHandler("selectFunnel", () =>
                this.actionSelectFunnel()
            );

            this.listenTo(this.collection, "sync", () => {
                this.scheduleAggregatesRefresh();
            });
        },

        loadDefaultFunnel: function () {
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
                    if (response.list && response.list.length > 0) {
                        const funnel = response.list[0];
                        this.setFunnel(funnel.id, funnel.name);
                    }
                })
                .catch(() => {});
        },

        setFunnel: function (funnelId, funnelName) {
            if (this.currentFunnelId === funnelId) {
                return;
            }

            this.currentFunnelId = funnelId;
            this.currentFunnelName = funnelName;

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

            this.updateFunnelSelectorDisplay();
            this.applyFunnelFilter();
        },

        applyFunnelFilter: function () {
            if (!this.collection) {
                return;
            }

            if (this._isFetching) {
                return;
            }

            const where = this.collection.where || [];

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

            this._isFetching = true;

            this.collection
                .fetch()
                .then(() => {
                    this._isFetching = false;
                })
                .catch(() => {
                    this._isFetching = false;
                });
        },

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

        getCollectionUrl: function () {
            let url = Dep.prototype.getCollectionUrl
                ? Dep.prototype.getCollectionUrl.call(this)
                : this.collection.url;

            return url;
        },

        buildRows: function (callback) {
            Dep.prototype.buildRows.call(
                this,
                function () {
                    this.calculateGroupStats();

                    if (callback) {
                        callback();
                    }
                }.bind(this)
            );
        },

        calculateGroupStats: function () {
            if (!this.groupDataList) {
                return;
            }

            this.groupDataList.forEach((group) => {
                group.count = group.collection ? group.collection.total : 0;
                if (group.count === -1) {
                    group.count = group.collection
                        ? group.collection.length
                        : 0;
                }

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

            this.fetchKanbanAggregates();
        },

        fetchKanbanAggregates: function () {
            if (!this.currentFunnelId) {
                return;
            }

            Espo.Ajax.getRequest("Opportunity/action/kanbanAggregates", {
                funnelId: this.currentFunnelId,
            })
                .then((response) => {
                    if (response && response.aggregates) {
                        this.applyServerAggregates(response.aggregates);
                    }
                })
                .catch(() => {});
        },

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

            this.updateGroupStatsUI();
        },

        updateGroupStatsUI: function () {
            if (!this.groupDataList || !this.$el) {
                return;
            }

            this.groupDataList.forEach((group) => {
                const $visualColumn = this.$el.find(
                    `.kanban-board .kanban-column[data-name="${group.name}"]`
                );

                if ($visualColumn.length) {
                    $visualColumn.find(".kanban-group-count").text(group.count);
                    $visualColumn
                        .find(".kanban-group-amount")
                        .text(group.amountSumFormatted);
                }

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

        formatCurrency: function (value) {
            const currency = this.getConfig().get("defaultCurrency") || "BRL";
            const decimalMark = this.getConfig().get("decimalMark") || ",";
            const thousandSeparator =
                this.getConfig().get("thousandSeparator") || ".";

            const parts = value.toFixed(2).split(".");
            parts[0] = parts[0].replace(
                /\B(?=(\d{3})+(?!\d))/g,
                thousandSeparator
            );

            const formattedNumber = parts.join(decimalMark);

            return currency + " " + formattedNumber;
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            this.updateFunnelSelectorDisplay();
            this.setupVisualKanban();
        },

        setupVisualKanban: function () {
            this.syncItemsToVisualBoard();
            this.setupVisualDragDrop();
        },

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
                    const $items = $originalColumn.find(".item").clone(true);

                    if ($items.length > 0) {
                        $visualColumn.empty().append($items);
                    } else {
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

        setupVisualDragDrop: function () {
            const self = this;

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
                        self.moveItemToGroup(itemId, groupName);
                    }
                });
            });
        },

        moveItemToGroup: function (itemId, groupName) {
            const model = this.collection.get(itemId);
            if (!model) {
                return;
            }

            const currentGroup = model.get(this.statusField);
            if (currentGroup === groupName) {
                return;
            }

            if (this.moveModelToGroup) {
                this.moveModelToGroup(model, groupName);
                this.scheduleAggregatesRefresh();
            } else {
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
                        this.fetchKanbanAggregates();
                        this.reRender();
                    },
                });
            }
        },

        scheduleAggregatesRefresh: function () {
            if (this._aggregatesRefreshTimeout) {
                clearTimeout(this._aggregatesRefreshTimeout);
            }

            this._aggregatesRefreshTimeout = setTimeout(() => {
                this.fetchKanbanAggregates();
            }, 500);
        },

        updateFunnelSelectorDisplay: function () {
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
            if (this.statusField !== "opportunityStageId") {
                return;
            }

            attributes["opportunityStageId"] = group;

            Espo.Ajax.getRequest("OpportunityStage/" + group).then((stage) => {
                if (stage) {
                    if (stage.probability !== undefined) {
                        attributes["probability"] = stage.probability;
                    }
                    if (stage.name) {
                        attributes["opportunityStageName"] = stage.name;
                    }
                }
            });
        },
    });
});
