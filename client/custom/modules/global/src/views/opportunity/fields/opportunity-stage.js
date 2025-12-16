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

            // Clear the stage when funnel changes (stage might not belong to new funnel)
            if (this.model.hasChanged("funnelId")) {
                this.model.set({
                    opportunityStageId: null,
                    opportunityStageName: null,
                });
            }

            // Re-render to update the autocomplete filter
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
         * Override to check if funnel is selected before allowing stage selection.
         */
        actionSelect: function () {
            const funnelId = this.model.get("funnelId");

            if (!funnelId) {
                Espo.Ui.warning(
                    this.translate("selectFunnelFirst", "messages", "Opportunity")
                );
                return;
            }

            Dep.prototype.actionSelect.call(this);
        },
    });
});

