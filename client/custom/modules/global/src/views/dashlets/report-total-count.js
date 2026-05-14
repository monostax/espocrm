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
 * Report Total + Count + Percentage dashlet.
 *
 * Renders one big total value, a count subtitle, and an optional percentage
 * badge — all computed from the user-selected numeric columns of a Grid /
 * JointGrid Report.
 *
 * Lives in the `global` module but extends the Advanced module's Report
 * dashlet view to reuse run-time URL building, error rendering and the report
 * helper.
 */
define('global:views/dashlets/report-total-count', [
    'advanced:views/dashlets/report',
    'search-manager',
    'advanced:report-helper',
], function (Dep, SearchManager, ReportHelper) {

    return Dep.extend({

        name: 'ReportTotalCount',
        optionsView: 'global:views/dashlets/options/report-total-count',

        templateContent: '<div class="report-results-container" style="height: 100%;"></div>',

        // Layout sizing factors used by the renderer.
        totalFontSizeMultiplier: 2.2,
        countFontSizeMultiplier: 0.9,
        percentageFontSizeMultiplier: 0.8,

        setup: function () {
            this.optionsFields = this.optionsFields || {};

            // Make sure the report picker uses the dashlet-select view even if
            // the metadata is overridden by another extension.
            this.optionsFields.report = Object.assign({}, this.optionsFields.report, {
                type: 'link',
                entity: 'Report',
                required: true,
                view: 'advanced:views/report/fields/dashlet-select',
            });

            this.optionsFields.totalColumn = this.optionsFields.totalColumn ||
                {type: 'enum', options: [], required: true};
            this.optionsFields.countColumn = this.optionsFields.countColumn ||
                {type: 'enum', options: [], required: true};
            this.optionsFields.percentageColumn = this.optionsFields.percentageColumn ||
                {type: 'enum', options: []};
            this.optionsFields.bindDashboardDateRange = this.optionsFields.bindDashboardDateRange ||
                {type: 'bool'};
            this.optionsFields.dashboardDateRangeField = this.optionsFields.dashboardDateRangeField ||
                {type: 'enum', options: []};
            this.optionsFields.showFilterInfo = this.optionsFields.showFilterInfo ||
                {type: 'bool'};

            this.reportHelper = new ReportHelper(
                this.getMetadata(),
                this.getLanguage(),
                this.getDateTime(),
                this.getConfig(),
                this.getPreferences()
            );
        },

        /**
         * Walk up the view chain to find the dashboard view. Returns null when
         * the dashlet is rendered outside a dashboard (e.g. previews) or
         * before the parent has been attached.
         */
        getDashboardView: function () {
            const container = this.getParentView && this.getParentView();
            const dashboard = container && container.getParentView && container.getParentView();

            return dashboard || null;
        },

        /**
         * Subscribe to the dashboard's shared date-range changes once, after
         * render — by then the parent chain is fully set. `listenTo` ensures
         * the binding is released when the dashlet view is removed.
         */
        attachDashboardDateRangeListener: function () {
            if (this._dashboardDateRangeListenerAttached) {
                return;
            }

            const dashboardView = this.getDashboardView();

            if (!dashboardView) {
                return;
            }

            this._dashboardDateRangeListenerAttached = true;

            this.listenTo(dashboardView, 'dashboard-date-range-change', () => {
                if (!this.getOption('bindDashboardDateRange')) {
                    return;
                }

                if (!this.getOption('dashboardDateRangeField')) {
                    return;
                }

                this.actionRefresh();
            });
        },

        /**
         * Return the currently-active dashboard date range, or null when none
         * is active.
         */
        getDashboardDateRange: function () {
            const dashboardView = this.getDashboardView();

            if (!dashboardView || typeof dashboardView.getDateRange !== 'function') {
                return null;
            }

            return dashboardView.getDateRange();
        },

        /**
         * Append the dashboard date range filter to the existing `where`
         * array when the dashlet is configured to bind to it.
         */
        applyDashboardDateRange: function (where) {
            if (!this.getOption('bindDashboardDateRange')) {
                return where;
            }

            const field = this.getOption('dashboardDateRangeField');

            if (!field) {
                return where;
            }

            const range = this.getDashboardDateRange();

            if (!range || !range.start || !range.end) {
                return where;
            }

            where = where || [];

            where.push({
                type: 'between',
                attribute: field,
                value: [range.start, range.end],
            });

            return where;
        },

        afterRender: function () {
            this.attachDashboardDateRangeListener();

            this.$container = this.$el.find('.report-results-container');
            this.run();
        },

        /**
         * Sum a single column over the Grid/JointGrid report result.
         */
        sumColumn: function (column, result) {
            if (!column) {
                return null;
            }

            if (result.depth === 0 || result.depth === 1) {
                return (result.sums && result.sums[column]) || 0;
            }

            let sum = 0;

            for (const groupKey in (result.group1Sums || {})) {
                sum += (result.group1Sums[groupKey] || {})[column] || 0;
            }

            return sum;
        },

        run: async function () {
            const reportId = this.getOption('reportId');

            if (!reportId) {
                return void this.displayError('selectReport');
            }

            const entityType = this.getOption('entityType');
            const type = this.getOption('type');

            if (!entityType || !type) {
                return void this.displayError();
            }

            // This dashlet is meaningful only for aggregated reports.
            if (type !== 'Grid' && type !== 'JointGrid') {
                return void this.displayError('reportTypeNotSupported');
            }

            const totalColumn = this.getOption('totalColumn');
            const countColumn = this.getOption('countColumn');
            const percentageColumn = this.getOption('percentageColumn') || null;

            if (!totalColumn || !countColumn) {
                return void this.displayError('selectColumns');
            }

            this.$container.css('height', '100%');

            // Build runtime filters (`where`) the same way the original Report
            // dashlet does.
            const collection = await this.getCollectionFactory().create(entityType);
            const searchManager = new SearchManager(collection, 'report', null, this.getDateTime());

            if ('setTimeZone' in searchManager) {
                searchManager.setTimeZone(null);
            }

            let where = null;

            if (this.getOption('filtersData')) {
                searchManager.setAdvanced(this.getOption('filtersData'));
                where = searchManager.getWhere();
            }

            where = this.applyDashboardDateRange(where);

            const result = await Espo.Ajax.getRequest(this.getGridReportUrl(), this.getGridReportRequestData(where));

            if (!result.depth && result.depth !== 0) {
                return void this.displayError();
            }

            const useSi = this.getOption('useSiMultiplier');

            const totalValue = this.sumColumn(totalColumn, result);
            const countValue = this.sumColumn(countColumn, result);
            const percentageValue = percentageColumn ? this.sumColumn(percentageColumn, result) : null;

            this.displayComposite({
                result: result,
                useSi: useSi,
                total: {
                    column: totalColumn,
                    value: totalValue,
                    customLabel: this.getOption('totalLabel') || null,
                },
                count: {
                    column: countColumn,
                    value: countValue,
                    customLabel: this.getOption('countLabel') || null,
                },
                percentage: percentageColumn ? {
                    column: percentageColumn,
                    value: percentageValue,
                    customLabel: this.getOption('percentageLabel') || null,
                } : null,
            });
        },

        /**
         * Render the composite Total / Count / Percentage layout.
         */
        displayComposite: function (data) {
            const {result, useSi, total, count, percentage} = data;

            this.$container.empty();

            const fontSize = this.getThemeManager().getParam('fontSize') || 14;
            const fontFactor = this.getThemeManager().getFontSizeFactor
                ? this.getThemeManager().getFontSizeFactor()
                : 1;

            const base = fontSize * fontFactor;

            const totalStr = this.reportHelper.formatCellValue(total.value, total.column, result, useSi);
            const totalTitle = useSi
                ? this.reportHelper.formatCellValue(total.value, total.column, result, false)
                : null;

            const countStr = this.reportHelper.formatCellValue(count.value, count.column, result, useSi);
            const countLabel = count.customLabel ||
                this.reportHelper.formatColumn(count.column, result);

            const $wrap = $('<div>').css({
                padding: '4px 2px',
                height: '100%',
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'center',
                position: 'relative',
            });

            // Optional percentage badge — top-right.
            if (percentage) {
                const pctStr = this.reportHelper.formatCellValue(
                    percentage.value, percentage.column, result, useSi
                );
                const pctTitle = useSi
                    ? this.reportHelper.formatCellValue(percentage.value, percentage.column, result, false)
                    : null;
                const pctLabel = percentage.customLabel ||
                    this.reportHelper.formatColumn(percentage.column, result);

                let badgeClass = 'label-success';

                if (typeof percentage.value === 'number') {
                    if (percentage.value < 0) {
                        badgeClass = 'label-danger';
                    } else if (percentage.value === 0) {
                        badgeClass = 'label-default';
                    }
                }

                const $badge = $('<span>')
                    .addClass('label ' + badgeClass)
                    .css({
                        position: 'absolute',
                        top: '0',
                        right: '0',
                        fontSize: (base * this.percentageFontSizeMultiplier) + 'px',
                        padding: '3px 8px',
                        borderRadius: '10px',
                    })
                    .attr('title', pctLabel + (pctTitle ? ': ' + pctTitle : ''))
                    .text(pctStr);

                $wrap.append($badge);
            }

            // Optional total caption (only when explicitly configured — keeps
            // backward compatibility with the original "value-only" layout).
            if (total.customLabel) {
                const $totalLabel = $('<div>')
                    .addClass('text-muted')
                    .css({
                        fontSize: (base * this.countFontSizeMultiplier) + 'px',
                        marginBottom: '2px',
                    })
                    .text(total.customLabel);

                $wrap.append($totalLabel);
            }

            // Total — big primary value.
            const $total = $('<div>')
                .addClass('total-value-text numeric-text text-primary')
                .css({
                    fontSize: (base * this.totalFontSizeMultiplier) + 'px',
                    fontWeight: '600',
                    lineHeight: '1.1',
                })
                .text(totalStr);

            if (totalTitle) {
                $total.attr('title', totalTitle);
            }

            $wrap.append($total);

            // Count — muted subtitle. Adds an optional right-aligned summary
            // of the currently-applied runtime filters (e.g. date range) so
            // the user can see at a glance what's being filtered.
            //
            // Two sources contribute to the description:
            //   1. The dashlet's runtime filters (`filtersData`).
            //   2. The dashboard-wide date range, when bound.
            //
            // Each contribution provides both a *visible* compact form
            // (just the value, e.g. "01/05 – 31/05") and a *tooltip* form
            // including the field label (e.g. "Criado em: 01/05 – 31/05").
            //
            // Suppress entirely when the user has disabled `showFilterInfo`
            // in dashlet options.
            const showFilterInfo = this.getOption('showFilterInfo') !== false;

            const filterEntries = showFilterInfo
                ? this.collectFilterEntries()
                : [];

            const filterVisible = filterEntries.length
                ? filterEntries.map(e => e.visible).join(' · ')
                : null;

            const filterTooltip = filterEntries.length
                ? filterEntries.map(e => e.tooltip).join(' · ')
                : null;

            const $count = $('<div>')
                .addClass('text-muted numeric-text')
                .css({
                    fontSize: (base * this.countFontSizeMultiplier) + 'px',
                    marginTop: '4px',
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'baseline',
                    gap: '8px',
                });

            $count.append(
                $('<span>')
                    .css({
                        // Count label always wins for space; never truncate
                        // or wrap. Width is its content.
                        flex: '0 0 auto',
                        whiteSpace: 'nowrap',
                    })
                    .text(countStr + ' ' + countLabel)
            );

            if (filterVisible) {
                $count.append(
                    $('<span>')
                        .css({
                            // Filter description shrinks/ellipses first when
                            // the dashlet card is narrow.
                            flex: '0 1 auto',
                            minWidth: '0',
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            opacity: '0.85',
                        })
                        .attr('title', filterTooltip || filterVisible)
                        .text(filterVisible)
                );
            }

            $wrap.append($count);

            this.$container.append($wrap);
        },

        /**
         * Collect every active filter entry to display next to the count
         * subtitle: per-dashlet runtime filters plus the bound dashboard
         * date range (if any).
         */
        collectFilterEntries: function () {
            const entries = this.buildFilterDisplayEntries(this.getOption('filtersData'));

            const dashboardRangeEntry = this.buildDashboardDateRangeEntry();

            if (dashboardRangeEntry) {
                entries.push(dashboardRangeEntry);
            }

            return entries;
        },

        /**
         * Build entries describing the runtime filters currently applied to
         * the dashlet (e.g. "Last 7 Days", "Between 1 May – 13 May"). Used
         * to surface the active filter next to the count subtitle.
         *
         * Each entry has:
         *   - `visible`: compact, value-only string for inline display.
         *   - `tooltip`: same string prefixed with the field label.
         *
         * Recognises Espo's standard date filter types (translated via
         * `Global.options.dateSearchRanges`). Unknown / non-date filter
         * types fall through and are skipped — the dashlet is meaningful
         * mostly for date-based filtering, so cluttering the line with
         * arbitrary filter expressions would hurt readability.
         */
        buildFilterDisplayEntries: function (filtersData) {
            if (!filtersData || typeof filtersData !== 'object') {
                return [];
            }

            const dateTime = this.getDateTime();
            const entityType = this.getOption('entityType');

            const entries = [];

            Object.keys(filtersData).forEach(fieldKey => {
                const filter = filtersData[fieldKey];

                if (!filter) {
                    return;
                }

                // `isEmpty` is sent as `{type:'isNull', data:{type:'isEmpty'}}`.
                const displayType = (filter.data && filter.data.type) || filter.type;

                if (!displayType) {
                    return;
                }

                const label = this.translate(displayType, 'dateSearchRanges', 'Global');

                // Unknown filter type — translate returns the key untouched.
                if (label === displayType) {
                    return;
                }

                let formatted = label;

                if (displayType === 'between' &&
                    Array.isArray(filter.value) &&
                    filter.value.length === 2
                ) {
                    formatted = label + ' ' +
                        dateTime.toDisplayDate(filter.value[0]) + ' – ' +
                        dateTime.toDisplayDate(filter.value[1]);
                } else if (['on', 'notOn', 'after', 'before'].includes(displayType)) {
                    const value = (filter.data && filter.data.value) || filter.value;

                    if (value) {
                        formatted = label + ' ' + dateTime.toDisplayDate(value);
                    }
                } else if (
                    ['lastXDays', 'nextXDays', 'olderThanXDays', 'afterXDays'].includes(displayType) &&
                    filter.value != null
                ) {
                    // Replace the literal X in the localized label with the count.
                    formatted = label.replace(/\bX\b/, filter.value);
                }

                const fieldLabel = entityType
                    ? this.translate(fieldKey, 'fields', entityType)
                    : fieldKey;

                entries.push({
                    visible: formatted,
                    tooltip: fieldLabel + ': ' + formatted,
                });
            });

            return entries;
        },

        /**
         * Build a description entry for the active dashboard-wide date range,
         * matching the shape returned by `buildFilterDisplayEntries`:
         *   - `visible`: compact date range only (e.g. "01/05/2026 – 31/05/2026").
         *   - `tooltip`: prefixed with the field's translated label.
         *
         * Returns `null` when:
         *   - The dashlet isn't bound to the dashboard date range, or
         *   - No date field is configured, or
         *   - The dashboard isn't exposing a range right now.
         */
        buildDashboardDateRangeEntry: function () {
            if (!this.getOption('bindDashboardDateRange')) {
                return null;
            }

            const field = this.getOption('dashboardDateRangeField');

            if (!field) {
                return null;
            }

            const range = this.getDashboardDateRange();

            if (!range || !range.start || !range.end) {
                return null;
            }

            const dateTime = this.getDateTime();
            const entityType = this.getOption('entityType');

            const fieldLabel = entityType
                ? this.translate(field, 'fields', entityType)
                : field;

            const dateRangeText = dateTime.toDisplayDate(range.start) + ' – ' +
                dateTime.toDisplayDate(range.end);

            // Prefer the friendly preset label ("Últimos 7 Dias", "Este Mês"…)
            // when the user picked a preset. The mapping mirrors the buttons
            // exposed in `client/res/templates/dashboard.tpl` and the labels
            // already shipped under `Global.labels`.
            const presetLabel = this.resolveDashboardDateRangePresetLabel(range.preset);

            return {
                visible: presetLabel || dateRangeText,
                tooltip: fieldLabel + ': ' + (presetLabel
                    ? presetLabel + ' (' + dateRangeText + ')'
                    : dateRangeText),
            };
        },

        /**
         * Translate a dashboard date-range preset key (`last7Days`, etc.)
         * into a human label using the standard Espo i18n keys. Returns
         * `null` when no preset is active or the key is unrecognized.
         */
        resolveDashboardDateRangePresetLabel: function (preset) {
            if (!preset) {
                return null;
            }

            const map = {
                last7Days: 'Last 7 Days',
                last30Days: 'Last 30 Days',
                thisMonth: 'This Month',
                lastMonth: 'Last Month',
                thisYear: 'This Year',
                last12Months: 'Last 12 Months',
            };

            const key = map[preset];

            if (!key) {
                return null;
            }

            const translated = this.translate(key, 'labels', 'Global');

            return translated && translated !== key ? translated : key;
        },

        actionRefresh: function () {
            this.reRender();
        },

        setupActionList: function () {
            this.actionList.unshift({
                name: 'viewReport',
                html: this.translate('View Report', 'labels', 'Report'),
                url: '#Report/show/' + this.getOption('reportId'),
                iconHtml: '<span class="fas fa-chart-bar"></span>',
            });
        },
    });
});
