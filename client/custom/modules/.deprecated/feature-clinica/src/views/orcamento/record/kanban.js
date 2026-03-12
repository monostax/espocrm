/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("feature-clinica:views/orcamento/record/kanban", [
    "views/record/kanban",
], function (Dep) {
    return Dep.extend({
        statusField: "status",
        itemViewName: "feature-clinica:views/orcamento/record/kanban-item",

        mandatorySelectAttributeList: [
            "pacienteName",
            "profissionalName",
            "unidadeName",
            "convenioName",
            "valorLiquido",
            "valorLiquidoCurrency",
            "dataValidade",
            "dataEmissao",
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

                let valueSum = 0;
                if (group.collection && group.collection.models) {
                    group.collection.models.forEach((model) => {
                        const value = model.get("valorLiquido") || 0;
                        valueSum += parseFloat(value) || 0;
                    });
                }
                group.valueSum = valueSum;
                group.valueSumFormatted = this.formatCurrency(valueSum);
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
                    $column
                        .find(".kanban-group-value")
                        .text(group.valueSumFormatted);
                }
            });
        },
    });
});
