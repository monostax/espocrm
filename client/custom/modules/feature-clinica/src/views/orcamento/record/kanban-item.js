/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("feature-clinica:views/orcamento/record/kanban-item", [
    "views/record/kanban-item",
], function (Dep) {
    return Dep.extend({
        template: "feature-clinica:orcamento/record/kanban-item",

        data: function () {
            const data = Dep.prototype.data.call(this);

            const numero = this.model.get("numero") || "Orçamento";
            const pacienteName = this.model.get("pacienteName");
            const profissionalName = this.model.get("profissionalName");
            const unidadeName = this.model.get("unidadeName");
            const convenioName = this.model.get("convenioName");
            const valorLiquido = this.model.get("valorLiquido");
            const valorLiquidoCurrency = this.model.get("valorLiquidoCurrency");
            const dataValidade = this.model.get("dataValidade");
            const dataEmissao = this.model.get("dataEmissao");
            const status = this.model.get("status");

            const valorFormatted = this.formatCurrency(valorLiquido, valorLiquidoCurrency);
            const dataValidadeInfo = this.formatDate(dataValidade);
            const dataEmissaoFormatted = this.formatDateSimple(dataEmissao);
            const statusStyle = this.getStatusStyle(status);

            return {
                ...data,
                id: this.model.id,
                numero: numero,
                pacienteName: pacienteName,
                profissionalName: profissionalName,
                unidadeName: unidadeName,
                convenioName: convenioName,
                valorLiquido: valorLiquido,
                valorFormatted: valorFormatted,
                dataValidade: dataValidade,
                dataValidadeFormatted: dataValidadeInfo.formatted,
                dataValidadeClass: dataValidadeInfo.cssClass,
                dataEmissaoFormatted: dataEmissaoFormatted,
                status: status,
                statusStyle: statusStyle,
                statusLabel: this.getLanguage().translateOption(status, "status", "Orcamento") || status,
                hasConvenio: !!convenioName,
                hasProfissional: !!profissionalName,
                hasUnidade: !!unidadeName,
            };
        },

        formatCurrency: function (value, currency) {
            if (!value && value !== 0) return null;

            currency = currency || this.getConfig().get("defaultCurrency") || "BRL";
            const decimalMark = this.getConfig().get("decimalMark") || ",";
            const thousandSeparator = this.getConfig().get("thousandSeparator") || ".";

            const parts = parseFloat(value).toFixed(2).split(".");
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator);

            const formattedNumber = parts.join(decimalMark);

            return currency + " " + formattedNumber;
        },

        formatDate: function (dateStr) {
            if (!dateStr) {
                return { formatted: null, cssClass: "" };
            }

            const dateTime = this.getDateTime();
            const today = moment().startOf("day");
            const date = moment(dateStr);

            if (!date.isValid()) {
                return { formatted: null, cssClass: "" };
            }

            const diffDays = date.diff(today, "days");

            let cssClass = "";
            if (diffDays < 0) {
                cssClass = "is-overdue";
            } else if (diffDays <= 7) {
                cssClass = "is-soon";
            }

            const formatted = dateTime.toDisplayDate(dateStr);

            return { formatted: formatted, cssClass: cssClass };
        },

        formatDateSimple: function (dateStr) {
            if (!dateStr) return null;
            const dateTime = this.getDateTime();
            return dateTime.toDisplayDate(dateStr);
        },

        getStatusStyle: function (status) {
            const styleMap = {
                "Rascunho": "status-rascunho",
                "Enviado": "status-enviado",
                "Aprovado": "status-aprovado",
                "Expirado": "status-expirado",
                "Recusado": "status-recusado",
            };
            return styleMap[status] || "status-default";
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.listenTo(this.model, "change:status", () => {
                this.reRender();
            });

            this.listenTo(this.model, "sync", () => {
                this.reRender();
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            this.$el.find(".orcamento-card").on("click", (e) => {
                if ($(e.target).closest(".item-menu-container").length) {
                    return;
                }
                this.actionQuickView();
            });
        },

        actionQuickView: function () {
            const viewName = this.getMetadata().get(
                ["clientDefs", this.model.entityType, "modalViews", "detail"]
            ) || "views/modals/detail";

            Espo.Ui.notify(" ... ");

            this.createView("modal", viewName, {
                scope: this.model.entityType,
                model: this.model,
                id: this.model.id,
            }, (view) => {
                Espo.Ui.notify(false);
                view.render();

                this.listenToOnce(view, "after:save", () => {
                    this.model.fetch();
                });
            });
        },
    });
});
