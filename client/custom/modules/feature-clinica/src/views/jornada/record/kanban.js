/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("feature-clinica:views/jornada/record/kanban", [
    "views/record/kanban",
], function (Dep) {
    return Dep.extend({
        statusField: "status",
        itemViewName: "feature-clinica:views/jornada/record/kanban-item",

        mandatorySelectAttributeList: [
            "pacienteName",
            "profissionalName",
            "unidadeName",
            "convenioName",
            "programaName",
            "dataInicio",
            "dataExpiracao",
        ],

        setup: function () {
            Dep.prototype.setup.call(this);
            this.statusField = "status";

            this.listenTo(this.collection, "sync", () => {
                this.calculateGroupStats();
            });
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
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);
            this.updateGroupStatsUI();
        },

        updateGroupStatsUI: function () {
            if (!this.groupDataList || !this.$el) {
                return;
            }

            this.groupDataList.forEach((group) => {
                const $column = this.$el.find(
                    `.kanban-column[data-name="${group.name}"]`
                );

                if ($column.length) {
                    $column.find(".kanban-group-count").text(group.count);
                }
            });
        },
    });
});
