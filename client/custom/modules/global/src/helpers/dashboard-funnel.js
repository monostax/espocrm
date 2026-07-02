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
 * Shared plumbing for dashlets that consume the dashboard-wide funnel
 * filter exposed by `views/dashboard` (`getFunnel()` +
 * `'dashboard-funnel-change'` event).
 *
 * Mirrors the dashboard date-range consumption pattern implemented in
 * `global:views/dashlets/report-total-count`.
 */
define('global:helpers/dashboard-funnel', [], function () {

    return {

        /**
         * Walk up the view chain to find the dashboard view. Returns null
         * when the dashlet is rendered outside a dashboard (e.g. previews)
         * or before the parent chain has been attached.
         *
         * @param {module:view} view A dashlet body view.
         * @return {module:view|null}
         */
        getDashboardView: function (view) {
            const container = view.getParentView && view.getParentView();
            const dashboard = container && container.getParentView && container.getParentView();

            return dashboard || null;
        },

        /**
         * Return the currently-active dashboard funnel (`{id, name}`), or
         * null when no funnel is selected or the dashlet is not on a
         * dashboard exposing the filter.
         *
         * @param {module:view} view A dashlet body view.
         * @return {{id: string, name: string}|null}
         */
        getFunnel: function (view) {
            const dashboardView = this.getDashboardView(view);

            if (!dashboardView || typeof dashboardView.getFunnel !== 'function') {
                return null;
            }

            return dashboardView.getFunnel();
        },

        /**
         * Whether the given entity type carries the `funnel` link (i.e. can
         * be filtered by `funnelId`). Only such dashlets react to the
         * dashboard funnel filter.
         *
         * @param {module:view} view A dashlet body view.
         * @param {string|null} entityType
         * @return {boolean}
         */
        entitySupportsFunnel: function (view, entityType) {
            if (!entityType) {
                return false;
            }

            return view.getMetadata()
                .get(['entityDefs', entityType, 'fields', 'funnel', 'type']) === 'link';
        },

        /**
         * Remove funnel-targeting entries from an advanced-filters object
         * (`filtersData` / search `advanced` data). Used so that an active
         * dashboard funnel *supersedes* any funnel filter the dashlet itself
         * carries instead of being ANDed with it.
         *
         * Mutates and returns the given object.
         *
         * @param {Object.<string, Object>|null} advanced
         * @return {Object.<string, Object>|null}
         */
        stripFunnelFilters: function (advanced) {
            if (!advanced || typeof advanced !== 'object') {
                return advanced;
            }

            Object.keys(advanced).forEach(key => {
                const item = advanced[key] || {};
                const baseField = key.split('-')[0];

                if (
                    baseField === 'funnel' ||
                    item.attribute === 'funnelId' ||
                    item.attribute === 'funnel'
                ) {
                    delete advanced[key];
                }
            });

            return advanced;
        },

        /**
         * Subscribe the dashlet to dashboard funnel changes (idempotent).
         * `listenTo` releases the binding when the dashlet view is removed.
         *
         * @param {module:view} view A dashlet body view.
         * @param {function()} callback Invoked on every funnel change.
         */
        listen: function (view, callback) {
            if (view._dashboardFunnelListenerAttached) {
                return;
            }

            const dashboardView = this.getDashboardView(view);

            if (!dashboardView || typeof dashboardView.getFunnel !== 'function') {
                return;
            }

            view._dashboardFunnelListenerAttached = true;

            view.listenTo(dashboardView, 'dashboard-funnel-change', () => callback());
        },
    };
});
