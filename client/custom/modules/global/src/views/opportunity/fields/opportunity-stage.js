/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Custom view for OpportunityStage field that filters stages by the selected Funnel.
 */
define("global:views/opportunity/fields/opportunity-stage", [
    "views/fields/link",
], function (Dep) {
    return Dep.extend({
        selectPrimaryFilterName: "active",

        detailTemplate: "global:opportunity/fields/opportunity-stage/detail",
        listTemplate: "global:opportunity/fields/opportunity-stage/list",
        listLinkTemplate: "global:opportunity/fields/opportunity-stage/list-link",

        mandatorySelectAttributeList: ["opportunityStageName", "opportunityStageStyle", "funnelName"],

        getAttributeList: function () {
            const list = Dep.prototype.getAttributeList.call(this);

            return [...new Set(list.concat([
                "opportunityStageStyle",
            ]))];
        },

        data: function () {
            let data = Dep.prototype.data.call(this);
            data.styleValue = this.model.get("opportunityStageStyle") || "default";
            data.displayAsLabel = true;
            return data;
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            // Listen for funnel changes to update the stage filter
            this.listenTo(this.model, "change:funnelId", () => {
                this.handleFunnelChange();
            });
        },

        /**
         * Handle funnel change - clear stage if funnel changed and re-render.
         */
        handleFunnelChange: function () {
            const funnelId = this.model.get("funnelId");
            const previousFunnelId = this.model.previous("funnelId");

            // Only clear the stage in edit mode after the user actually switched funnels.
            // During initial detailSmall loading, funnelId changes from empty to fetched value,
            // and clearing here would wipe an otherwise valid opportunityStage selection.
            if (
                this.isEditMode() &&
                previousFunnelId &&
                previousFunnelId !== funnelId
            ) {
                this.model.set({
                    opportunityStageId: null,
                    opportunityStageName: null,
                    opportunityStageStyle: null,
                    stageProbability: null,
                });
            }

            // Re-render to update the displayed value and autocomplete filter.
            if (this.isRendered()) {
                this.reRender();
            }
        },

        /**
         * Get the select filter based on the current funnel selection.
         */
        getSelectBoolFilterList: function () {
            const funnelId = this.model.get("funnelId");

            if (!funnelId) {
                return ["onlyActive"];
            }

            return ["onlyActive"];
        },

        /**
         * Get additional filters for the autocomplete.
         */
        getAutocompleteUrl: function (q) {
            let url = Dep.prototype.getAutocompleteUrl.call(this, q);

            const funnelId = this.model.get("funnelId");

            if (funnelId) {
                url += "&where[0][type]=equals&where[0][attribute]=funnelId&where[0][value]=" + funnelId;
            }

            return url;
        },

        /**
         * Get the select filters to apply when opening the select modal.
         */
        getSelectFilters: function () {
            const filters = {};

            const funnelId = this.model.get("funnelId");

            if (funnelId) {
                filters.funnel = {
                    type: "equals",
                    attribute: "funnelId",
                    value: funnelId,
                    data: {
                        type: "is",
                        idValue: funnelId,
                        nameValue: this.model.get("funnelName"),
                    },
                };
            }

            return filters;
        },

        /**
         * Whether this field is being rendered inside the Opportunity mass-update
         * modal. In that context there is no single funnel to scope stages to, so
         * the funnel-first guard is relaxed and every active stage is selectable.
         * On apply, each record is moved to the chosen stage's owning funnel
         * (see MassAction\Opportunity\MassUpdate), so no records are skipped.
         */
        isMassUpdateContext: function () {
            const parentView = this.getParentView();

            return !!(parentView && parentView.isMassUpdate === true);
        },

        /**
         * Override to check if funnel is selected before allowing stage selection.
         */
        actionSelect: function () {
            if (this.isMassUpdateContext()) {
                Dep.prototype.actionSelect.call(this);
                return;
            }

            const funnelId = this.model.get("funnelId");

            if (!funnelId) {
                Espo.Ui.warning(
                    this.translate("selectFunnelFirst", "messages", "Opportunity")
                );
                return;
            }

            Dep.prototype.actionSelect.call(this);
        },

        /**
         * Append the parent Funnel name to each autocomplete suggestion when
         * stages are offered across funnels (mass-update). Without the funnel
         * the bare stage name is ambiguous since the same stage name can exist
         * in multiple funnels.
         */
        _transformAutocompleteResult: function (response) {
            const list = Dep.prototype._transformAutocompleteResult.call(this, response);

            if (!this.isMassUpdateContext()) {
                return list;
            }

            list.forEach(item => {
                const funnelName = item.attributes && item.attributes.funnelName;

                if (funnelName) {
                    item.value = item.value + " – " + funnelName;
                }
            });

            return list;
        },
    });
});

