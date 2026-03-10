/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("feature-clinica:views/jornada/record/kanban-item", [
    "views/record/kanban-item",
], function (Dep) {
    return Dep.extend({
        template: "feature-clinica:jornada/record/kanban-item",

        data: function () {
            const data = Dep.prototype.data.call(this);

            const nome = this.model.get("nome") || "Jornada";
            const pacienteName = this.model.get("pacienteName");
            const profissionalName = this.model.get("profissionalName");
            const unidadeName = this.model.get("unidadeName");
            const convenioName = this.model.get("convenioName");
            const programaName = this.model.get("programaName");
            const dataInicio = this.model.get("dataInicio");
            const dataExpiracao = this.model.get("dataExpiracao");
            const status = this.model.get("status");

            const dataInicioFormatted = this.formatDateSimple(dataInicio);
            const dataExpiracaoInfo = this.formatDate(dataExpiracao);
            const statusStyle = this.getStatusStyle(status);

            return {
                ...data,
                id: this.model.id,
                nome: nome,
                pacienteName: pacienteName,
                profissionalName: profissionalName,
                unidadeName: unidadeName,
                convenioName: convenioName,
                programaName: programaName,
                dataInicio: dataInicio,
                dataInicioFormatted: dataInicioFormatted,
                dataExpiracao: dataExpiracao,
                dataExpiracaoFormatted: dataExpiracaoInfo.formatted,
                dataExpiracaoClass: dataExpiracaoInfo.cssClass,
                status: status,
                statusStyle: statusStyle,
                statusLabel: this.getLanguage().translateOption(status, "status", "Jornada") || status,
                hasProfissional: !!profissionalName,
                hasUnidade: !!unidadeName,
                hasConvenio: !!convenioName,
                hasPrograma: !!programaName,
            };
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
                "EmAndamento": "status-em-andamento",
                "Pausada": "status-pausada",
                "Concluida": "status-concluida",
                "Abandonada": "status-abandonada",
                "Cancelada": "status-cancelada",
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

            this.$el.find(".jornada-card").on("click", (e) => {
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
