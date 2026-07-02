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
 * Records dashlet bound to the dashboard-wide funnel filter.
 *
 * Extends the core Records dashlet and appends an `equals funnelId`
 * advanced filter to the dashlet's search data whenever a funnel is
 * selected in the dashboard header and the configured entity type carries
 * the `funnel` link.
 *
 * On funnel change the dashlet re-renders, which rebuilds the search
 * manager (`afterRender` of the abstract record-list dashlet) and refetches
 * the collection with the new filter.
 *
 * Registered via the `dashlets.Records.view` metadata override in
 * `custom/Espo/Modules/Global/Resources/metadata/dashlets/Records.json`.
 */
define('global:views/dashlets/records', [
    'views/dashlets/records',
    'global:helpers/dashboard-funnel',
], function (Dep, DashboardFunnel) {

    return Dep.extend({

        getSearchData: function () {
            const data = Dep.prototype.getSearchData.call(this) || {};

            const funnel = this.getDashboardFunnel();

            if (!funnel) {
                return data;
            }

            // The dashboard funnel supersedes any funnel filter the dashlet
            // itself carries.
            data.advanced = {
                ...DashboardFunnel.stripFunnelFilters({...(data.advanced || {})}),
                dashboardFunnel: {
                    type: 'equals',
                    attribute: 'funnelId',
                    value: funnel.id,
                },
            };

            return data;
        },

        /**
         * The active dashboard funnel, or null when the dashlet's entity
         * cannot be filtered by funnel.
         *
         * @return {{id: string, name: string}|null}
         */
        getDashboardFunnel: function () {
            if (!DashboardFunnel.entitySupportsFunnel(this, this.scope)) {
                return null;
            }

            return DashboardFunnel.getFunnel(this);
        },

        afterRender: function () {
            DashboardFunnel.listen(this, () => {
                if (!DashboardFunnel.entitySupportsFunnel(this, this.scope)) {
                    return;
                }

                this.reRender();
            });

            Dep.prototype.afterRender.call(this);
        },
    });
});
