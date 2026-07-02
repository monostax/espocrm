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
 * Report dashlet bound to the dashboard-wide funnel filter.
 *
 * Extends the Advanced module's Report dashlet and injects an
 * `equals funnelId` runtime filter whenever:
 *   - the dashboard header exposes a selected funnel, and
 *   - the report's entity type carries the `funnel` link.
 *
 * The injection happens by intercepting `getOption('filtersData')` — the
 * single place from which the parent `run()` builds the runtime `where` for
 * every display type (Grid, JointGrid and List). This keeps the vendor
 * `run()` untouched.
 *
 * Registered via the `dashlets.Report.view` metadata override in
 * `custom/Espo/Modules/Global/Resources/metadata/dashlets/Report.json`.
 */
define('global:views/dashlets/report', [
    'advanced:views/dashlets/report',
    'global:helpers/dashboard-funnel',
], function (Dep, DashboardFunnel) {

    return Dep.extend({

        getOption: function (name) {
            const value = Dep.prototype.getOption.call(this, name);

            if (name !== 'filtersData') {
                return value;
            }

            const funnel = this.getDashboardFunnel();

            if (!funnel) {
                return value;
            }

            // Clone so the injected filter never leaks into the stored
            // dashlet options (edit-options modal, preferences save). The
            // dashboard funnel supersedes any funnel filter the dashlet
            // itself carries.
            const data = DashboardFunnel.stripFunnelFilters(
                Espo.Utils.cloneDeep(value || {})
            );

            data.dashboardFunnel = {
                type: 'equals',
                attribute: 'funnelId',
                value: funnel.id,
            };

            return data;
        },

        /**
         * The active dashboard funnel, or null when the report entity
         * cannot be filtered by funnel.
         *
         * @return {{id: string, name: string}|null}
         */
        getDashboardFunnel: function () {
            const entityType = Dep.prototype.getOption.call(this, 'entityType');

            if (!DashboardFunnel.entitySupportsFunnel(this, entityType)) {
                return null;
            }

            return DashboardFunnel.getFunnel(this);
        },

        /**
         * Re-run the report whenever the dashboard funnel changes.
         * Attached after render — by then the parent chain is fully set.
         */
        attachDashboardFunnelListener: function () {
            DashboardFunnel.listen(this, () => {
                const entityType = Dep.prototype.getOption.call(this, 'entityType');

                if (!DashboardFunnel.entitySupportsFunnel(this, entityType)) {
                    return;
                }

                this.actionRefresh();
            });
        },

        afterRender: function () {
            this.attachDashboardFunnelListener();

            Dep.prototype.afterRender.call(this);
        },
    });
});
