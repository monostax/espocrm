/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

/**
 * A quantidade aquisição field with unit suffix.
 *
 * Displays the acquisition unit (caixa, pacote, kit, unidade) from the
 * related Insumo as a suffix, similar to how currency fields show the currency symbol.
 *
 * Example: 10 caixas
 */
define("feature-clinica:views/fields/quantidade-aquisicao", [
    "views/fields/float",
], function (Dep) {
    return Dep.extend({
        type: "quantidadeAquisicao",

        editTemplate: "fields/currency/edit",

        detailTemplate: "fields/currency/detail",

        listTemplate: "fields/currency/list",

        /**
         * Unit translations mapping
         */
        unitTranslations: {
            unidade: "Unidade",
            caixa: "Caixa",
            pacote: "Pacote",
            kit: "Kit",
        },

        /**
         * Get the unit value from the related Insumo
         * @return {string}
         */
        getUnitValue: function () {
            // Try to get from the model's foreign field
            var unitValue = this.model.get("insumoUnidadeAquisicao");

            if (!unitValue) {
                // Fallback: try to get from the related insumo entity
                var insumo = this.model.get("insumo");
                if (insumo) {
                    unitValue = insumo.get("unidadeAquisicao");
                }
            }

            return unitValue || "unidade";
        },

        /**
         * Translate the unit value to display label
         * @param {string} unitValue
         * @return {string}
         */
        translateUnit: function (unitValue) {
            // First try the options translation from Insumo
            var translated = this.translate(unitValue, "options", "Insumo");
            if (translated !== unitValue) {
                return translated;
            }

            // Fallback to local translations
            return this.unitTranslations[unitValue] || unitValue;
        },

        /**
         * @inheritDoc
         */
        data: function () {
            var unitValue = this.getUnitValue();
            var unitLabel = this.translateUnit(unitValue);

            return {
                ...Dep.prototype.data.call(this),
                currencyFieldName: null,
                currencyValue: unitLabel,
                currencyList: [],
                currencySymbol: "",
                multipleCurrencies: false,
                defaultCurrency: unitLabel,
                unitValue: unitValue,
                unitLabel: unitLabel,
            };
        },

        /**
         * @inheritDoc
         */
        setup: function () {
            Dep.prototype.setup.call(this);

            // Listen for changes to the insumo relation
            this.listenTo(this.model, "change:insumo", function () {
                if (this.isEditMode()) {
                    this.reRender();
                }
            });

            // Also listen for changes to the foreign field
            this.listenTo(
                this.model,
                "change:insumoUnidadeAquisicao",
                function () {
                    if (this.isEditMode()) {
                        this.reRender();
                    }
                },
            );
        },

        /**
         * @inheritDoc
         */
        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            // Update the suffix display in edit mode
            if (this.isEditMode()) {
                this._updateUnitSuffix();
            }
        },

        /**
         * Update the unit suffix in the input field
         */
        _updateUnitSuffix: function () {
            var $addon = this.$el.find(".input-group-addon");
            if ($addon.length) {
                var unitLabel = this.translateUnit(this.getUnitValue());
                $addon.text(unitLabel);
            }
        },
    });
});

