/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

/** @module views/dashboard */

import View from 'view';
import GridStack from 'gridstack';
import _ from 'underscore';
import moment from 'moment';
import Datepicker from 'ui/datepicker';

class DashboardView extends View {

    template = 'dashboard'

    dashboardLayout = null
    currentTab = null

    /**
     * @private
     * @type {number}
     */
    cellHeight

    WIDTH_MULTIPLIER = 3
    HEIGHT_MULTIPLIER = 4

    /**
     * Internal date format used to persist the dashboard date range.
     * @private
     */
    DATE_RANGE_INTERNAL_FORMAT = 'YYYY-MM-DD'

    /**
     * @private
     * @type {{start: string, end: string}|null}
     */
    dateRange = null

    /**
     * @private
     * @type {import('ui/datepicker').default|null}
     */
    dateRangeStartDatepicker = null

    /**
     * @private
     * @type {import('ui/datepicker').default|null}
     */
    dateRangeEndDatepicker = null

    /**
     * Currently selected dashboard-wide funnel filter.
     * `null` means "all funnels" (no filtering).
     *
     * @private
     * @type {{id: string, name: string}|null}
     */
    funnel = null

    /**
     * @private
     * @type {Object.<string, import('views/dashlet').default>|null}
     */
    preservedDashletViews = null

    /**
     * @private
     * @type {Object.<string, HTMLElement>|null}
     */
    preservedDashletElements = null

    events = {
        /** @this DashboardView */
        'click button[data-action="selectTab"]': function (e) {
            const tab = parseInt($(e.currentTarget).data('tab'));

            this.selectTab(tab);
        },
        /** @this DashboardView */
        'click .dashboard-buttons [data-action="addDashlet"]': function () {
            this.createView('addDashlet', 'views/modals/add-dashlet', {}, view => {
                view.render();

                this.listenToOnce(view, 'add', name => this.addDashlet(name));
            });
        },
        /** @this DashboardView */
        'click .dashboard-buttons [data-action="editTabs"]': function () {
            this.editTabs();
        },
        /** @this DashboardView */
        'click .dashboard-date-range[data-action="toggleDateRange"]': function (e) {
            if ($(e.target).closest('.dashboard-date-range-popover').length) {
                return;
            }

            this.toggleDateRangePopover();
        },
        /** @this DashboardView */
        'click .dashboard-date-range-popover': function (e) {
            e.stopPropagation();
        },
        /** @this DashboardView */
        'click [data-action="selectDatePreset"]': function (e) {
            e.stopPropagation();

            const preset = $(e.currentTarget).data('preset');

            this.applyDatePreset(preset);
        },
        /** @this DashboardView */
        'click [data-action="applyDateRange"]': function (e) {
            e.stopPropagation();

            this.applyDateRangeFromInputs();
        },
        /** @this DashboardView */
        'click [data-action="cancelDateRange"]': function (e) {
            e.stopPropagation();

            this.closeDateRangePopover();
        },
        /** @this DashboardView */
        'click .dashboard-funnel-filter[data-action="selectFunnel"]': function (e) {
            if ($(e.target).closest('[data-action="clearFunnel"]').length) {
                return;
            }

            this.actionSelectFunnel();
        },
        /** @this DashboardView */
        'click .dashboard-funnel-filter [data-action="clearFunnel"]': function (e) {
            e.stopPropagation();

            this.setFunnel(null);
        },
    }

    data() {
        // displayTitle defaults to true: the controller passes it explicitly,
        // but `views/home` (the root URL entry point) embeds the dashboard
        // without options, in which case we still want the title/description.
        const displayTitle = this.options.displayTitle !== false;

        const currentTabData = this.dashboardLayout && this.dashboardLayout[this.currentTab] || {};

        // Per-tab title falls back to the global "Dashboard" translation.
        const titleText = displayTitle
            ? (currentTabData.title || this.translate('Dashboard', 'scopeNames'))
            : null;

        // Per-tab description falls back to the global default translation
        // (which itself returns `null` if no translation is configured).
        const descriptionText = displayTitle
            ? (currentTabData.description || this.getDashboardDescription())
            : null;

        // The date range picker is shown unless this tab explicitly opts out.
        const showDateRange = currentTabData.showDateRange !== false;

        const showFunnelFilter = this.isFunnelFilterAvailable() &&
            currentTabData.showFunnelFilter !== false;

        return {
            displayTitle: displayTitle,
            titleText: titleText,
            descriptionText: descriptionText,
            showDateRange: showDateRange,
            dateRangeLabel: this.formatDateRangeLabel(this.dateRange),
            showFunnelFilter: showFunnelFilter,
            funnelLabel: this.formatFunnelLabel(this.funnel),
            hasFunnel: !!this.funnel,
            currentTab: this.currentTab,
            tabCount: this.dashboardLayout.length,
            dashboardLayout: this.dashboardLayout,
            layoutReadOnly: this.layoutReadOnly,
            hasAdd: !this.layoutReadOnly && !this.getPreferences().get('dashboardLocked'),
        };
    }

    /**
     * @protected
     * @return {string|null}
     */
    getDashboardDescription() {
        const language = this.getLanguage();

        const description = language.translate('dashboardDescription', 'messages');

        return description === 'dashboardDescription' ? null : description;
    }

    generateId() {
        return (Math.floor(Math.random() * 10000001)).toString();
    }

    setupCurrentTabLayout() {
        if (!this.dashboardLayout) {
            const defaultLayout = [
                {
                    "name": "Dashboard",
                    "layout": [],
                }
            ];

            if (this.getConfig().get('forcedDashboardLayout')) {
                this.dashboardLayout = this.getConfig().get('forcedDashboardLayout') || [];
            }
            else if (this.getUser().get('portalId')) {
                this.dashboardLayout = this.getConfig().get('dashboardLayout') || [];
            }
            else {
                this.dashboardLayout = this.getPreferences().get('dashboardLayout') || defaultLayout;
            }

            if (
                this.dashboardLayout.length === 0 ||
                Object.prototype.toString.call(this.dashboardLayout) !== '[object Array]'
            ) {
                this.dashboardLayout = defaultLayout;
            }

            this.ensureTabSlugs(this.dashboardLayout);
        }

        const dashboardLayout = this.dashboardLayout || [];

        if (dashboardLayout.length <= this.currentTab) {
            this.currentTab = 0;
        }

        let tabLayout = dashboardLayout[this.currentTab].layout || [];

        tabLayout = GridStack.Utils.sort(tabLayout);

        this.currentTabLayout = tabLayout;
    }

    /**
     * @param {number} tab
     */
    storeCurrentTab(tab) {
        this.getStorage().set('state', 'dashboardTab', tab);
    }

    /**
     * @param {number} tab
     */
    selectTab(tab) {
        this.$el.find('.page-header button[data-action="selectTab"]').removeClass('active');
        this.$el.find(`.page-header button[data-action="selectTab"][data-tab="${tab}"]`).addClass('active');

        this.currentTab = tab;
        this.storeCurrentTab(tab);
        this.updateUrlForTab(tab);

        this.setupCurrentTabLayout();

        this.dashletIdList.forEach(id => this.clearView(`dashlet-${id}`));

        this.dashletIdList = [];

        this.reRender();
    }

    /**
     * Reflect the active tab in the URL without triggering navigation.
     * Uses the tab's `slug` so links work across users (a slug derived from
     * the tab name is stable per template/deployment); falls back to the
     * numeric index for legacy tabs that have no slug yet.
     *
     * @param {number} tab
     * @private
     */
    updateUrlForTab(tab) {
        const router = this.getRouter();

        if (!router) {
            return;
        }

        const entry = (this.dashboardLayout || [])[tab];
        const key = entry && entry.slug ? entry.slug : String(tab);

        router.navigate(`Dashboard/index/tab=${encodeURIComponent(key)}`, {trigger: false});
    }

    /**
     * Resolve a URL `tab` parameter to an index in `this.dashboardLayout`.
     * Tries, in order: slug match, legacy `id` match, numeric index, and
     * finally a name-based slug match (so a link generated against one
     * user's layout still resolves on another user's layout when names line
     * up). Returns `null` when nothing matches.
     *
     * @param {string|number|null|undefined} value
     * @return {number|null}
     * @private
     */
    resolveTabFromUrl(value) {
        if (value == null || value === '') {
            return null;
        }

        const layout = this.dashboardLayout || [];
        const key = String(value).toLowerCase();

        const bySlug = layout.findIndex(d => d && d.slug && String(d.slug).toLowerCase() === key);

        if (bySlug !== -1) {
            return bySlug;
        }

        const byId = layout.findIndex(d => d && d.id != null && String(d.id) === String(value));

        if (byId !== -1) {
            return byId;
        }

        if (/^\d+$/.test(String(value))) {
            const idx = parseInt(String(value));

            if (idx >= 0 && idx < layout.length) {
                return idx;
            }
        }

        const byDerivedSlug = layout.findIndex(d => d && this.slugifyTabName(d.name) === key);

        if (byDerivedSlug !== -1) {
            return byDerivedSlug;
        }

        return null;
    }

    /**
     * Derive a URL-safe slug from a tab `name`. Stable, ASCII-only, lowercase.
     * Used both to assign new tab slugs and to match URL params against tabs
     * that don't yet have a stored slug.
     *
     * @param {string} name
     * @return {string}
     * @private
     */
    slugifyTabName(name) {
        return String(name || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 50) || 'tab';
    }

    /**
     * Lazily assign a `slug` to any tab missing one, de-duplicating against
     * existing slugs. The mutation is in-memory; it is persisted on the next
     * `saveLayout()` call.
     *
     * @param {Array<Object>} layout
     * @private
     */
    ensureTabSlugs(layout) {
        if (!Array.isArray(layout)) {
            return;
        }

        const used = new Set(
            layout
                .filter(d => d && d.slug)
                .map(d => String(d.slug).toLowerCase())
        );

        layout.forEach(entry => {
            if (!entry || entry.slug) {
                return;
            }

            const base = this.slugifyTabName(entry.name);
            let candidate = base;
            let n = 2;

            while (used.has(candidate)) {
                candidate = `${base}-${n++}`;
            }

            entry.slug = candidate;
            used.add(candidate);
        });
    }

    setup() {
        this.injectDashboardHeaderStyles();

        // We need the layout populated before resolving a URL-supplied tab id
        // to an index, so build the layout first and then pick currentTab.
        this.currentTab = 0;
        this.setupCurrentTabLayout();

        const fromUrl = this.resolveTabFromUrl(this.options.tab);

        if (fromUrl != null) {
            this.currentTab = fromUrl;
        } else {
            const stored = this.getStorage().get('state', 'dashboardTab');

            this.currentTab = Number.isInteger(stored) ? stored : 0;
        }

        // Re-run with the resolved tab so `layoutData` etc. reflect it.
        this.setupCurrentTabLayout();

        this.setupDateRange();
        this.setupFunnel();

        this.cellHeight = this.getThemeManager().getParam('dashboardCellHeight');

        this.dashletIdList = [];

        this.screenWidthXs = this.getThemeManager().getParam('screenWidthXs');

        if (this.getUser().isPortal()) {
            this.layoutReadOnly = true;
            this.dashletsReadOnly = true;
        } else {
            const forbiddenPreferencesFieldList = this.getAcl().getScopeForbiddenFieldList('Preferences', 'edit');

            if (forbiddenPreferencesFieldList.includes('dashboardLayout')) {
                this.layoutReadOnly = true;
            }

            if (forbiddenPreferencesFieldList.includes('dashletsOptions')) {
                this.dashletsReadOnly = true;
            }
        }

        this.once('remove', () => {
            if (this.grid) {
                this.grid.destroy();
            }

            if (this.fallbackModeTimeout) {
                clearTimeout(this.fallbackModeTimeout);
            }

            $(window).off('resize.dashboard');
            $(document).off('mousedown.dashboard-date-range');
        });
    }

    /**
     * Inject the custom dashboard header stylesheet (idempotent).
     *
     * @private
     */
    injectDashboardHeaderStyles() {
        if (document.getElementById('dashboard-header-styles')) {
            return;
        }

        const link = document.createElement('link');
        link.id = 'dashboard-header-styles';
        link.rel = 'stylesheet';
        link.href = 'client/custom/modules/global/css/dashboard-header.css';

        document.head.appendChild(link);
    }

    /**
     * @private
     */
    setupDateRange() {
        const stored = this.getStorage().get('state', 'dashboardDateRange');

        let range = null;

        if (stored && typeof stored === 'object' && stored.start && stored.end) {
            range = {start: stored.start, end: stored.end, preset: stored.preset || null};
        }

        if (!range) {
            const end = moment().format(this.DATE_RANGE_INTERNAL_FORMAT);
            const start = moment().subtract(1, 'year').format(this.DATE_RANGE_INTERNAL_FORMAT);

            range = {start, end, preset: null};
        }

        this.dateRange = range;
    }

    /**
     * Whether the date range picker is exposed for the current tab.
     *
     * @return {boolean}
     */
    isDateRangeVisibleForCurrentTab() {
        const tab = this.dashboardLayout && this.dashboardLayout[this.currentTab];

        return !!tab && tab.showDateRange !== false;
    }

    /**
     * Get the currently active dashboard date range.
     *
     * The picker can be hidden per tab via the Edit Dashboard modal — in that
     * case we still keep the stored range, but `getDateRange()` returns
     * `null` so that bound dashlets stop filtering until the user re-enables
     * the picker for the active tab.
     *
     * @return {{start: string, end: string, preset: string|null}|null}
     */
    getDateRange() {
        if (!this.isDateRangeVisibleForCurrentTab()) {
            return null;
        }

        return this.dateRange
            ? {
                start: this.dateRange.start,
                end: this.dateRange.end,
                preset: this.dateRange.preset || null,
            }
            : null;
    }

    /**
     * @private
     * @param {{start: string, end: string}|null} range
     * @return {string}
     */
    formatDateRangeLabel(range) {
        if (!range || !range.start || !range.end) {
            return this.translate('Date Range', 'labels');
        }

        const start = moment(range.start, this.DATE_RANGE_INTERNAL_FORMAT);
        const end = moment(range.end, this.DATE_RANGE_INTERNAL_FORMAT);

        if (!start.isValid() || !end.isValid()) {
            return this.translate('Date Range', 'labels');
        }

        // Use moment's default locale — Espo overrides English month/day
        // names with the user's language translations, so this produces
        // localized output (e.g. "Abr 10, 2025" in pt_BR) without us having
        // to ship moment locale files.
        // Collapse identical start/end (e.g. the "Today" preset) into a
        // single date so the header shows "Mai 14, 2026" instead of the
        // duplicated "Mai 14, 2026 - Mai 14, 2026".
        if (start.isSame(end, 'day')) {
            return start.format('MMM D, YYYY');
        }

        return `${start.format('MMM D, YYYY')} - ${end.format('MMM D, YYYY')}`;
    }

    /**
     * Restore the dashboard-wide funnel filter from client storage.
     *
     * @private
     */
    setupFunnel() {
        const stored = this.getStorage().get('state', 'dashboardFunnel');

        this.funnel = stored && typeof stored === 'object' && stored.id
            ? {id: stored.id, name: stored.name || ''}
            : null;
    }

    /**
     * Whether the funnel filter can be offered to the current user at all —
     * the Funnel entity must exist and be readable.
     *
     * @private
     * @return {boolean}
     */
    isFunnelFilterAvailable() {
        if (!this.getMetadata().get(['scopes', 'Funnel'])) {
            return false;
        }

        return this.getAcl().checkScope('Funnel', 'read');
    }

    /**
     * Whether the funnel filter is exposed for the current tab.
     *
     * @return {boolean}
     */
    isFunnelFilterVisibleForCurrentTab() {
        if (!this.isFunnelFilterAvailable()) {
            return false;
        }

        const tab = this.dashboardLayout && this.dashboardLayout[this.currentTab];

        return !!tab && tab.showFunnelFilter !== false;
    }

    /**
     * Get the currently active dashboard funnel filter.
     *
     * Public API consumed by dashlets (mirrors `getDateRange()`). Returns
     * `null` when no funnel is selected ("all funnels") or when the filter
     * is hidden for the active tab — in both cases bound dashlets must not
     * filter by funnel.
     *
     * @return {{id: string, name: string}|null}
     */
    getFunnel() {
        if (!this.isFunnelFilterVisibleForCurrentTab()) {
            return null;
        }

        return this.funnel
            ? {id: this.funnel.id, name: this.funnel.name}
            : null;
    }

    /**
     * @private
     * @param {{id: string, name: string}|null} funnel
     * @return {string}
     */
    formatFunnelLabel(funnel) {
        if (funnel && funnel.name) {
            return funnel.name;
        }

        return this.translate('All Funnels', 'labels');
    }

    /**
     * Set (or clear, with `null`) the dashboard-wide funnel filter, persist
     * it to client storage, update the header control and notify dashlets.
     *
     * @param {{id: string, name: string}|null} funnel
     */
    setFunnel(funnel) {
        this.funnel = funnel || null;

        if (this.funnel) {
            this.getStorage().set('state', 'dashboardFunnel', this.funnel);
        } else {
            this.getStorage().clear('state', 'dashboardFunnel');
        }

        this.$el.find('.dashboard-funnel-filter-label').text(this.formatFunnelLabel(this.funnel));
        this.$el.find('.dashboard-funnel-filter-clear').toggleClass('hidden', !this.funnel);

        this.trigger('dashboard-funnel-change', this.getFunnel());
    }

    /**
     * Open the standard record-select modal for active funnels.
     *
     * @private
     */
    actionSelectFunnel() {
        const viewName = this.getMetadata()
                .get(['clientDefs', 'Funnel', 'modalViews', 'select']) ||
            'views/modals/select-records';

        this.createView('selectFunnelDialog', viewName, {
            scope: 'Funnel',
            multiple: false,
            createButton: false,
            primaryFilterName: 'active',
            boolFilterList: ['onlyActive'],
            forceSelectAllAttributes: true,
        }, view => {
            view.render();

            this.listenToOnce(view, 'select', model => {
                this.setFunnel({id: model.id, name: model.get('name') || ''});

                view.close();
            });
        });
    }

    afterRender() {
        this.$dashboard = this.$el.find('> .dashlets');

        this.initDateRangePicker();

        if (window.innerWidth >= this.screenWidthXs) {
            this.initGridstack();
        } else {
            this.initFallbackMode();
        }

        $(window).off('resize.dashboard');
        $(window).on('resize.dashboard', this.onResize.bind(this));
    }

    /**
     * @private
     */
    initDateRangePicker() {
        const $popover = this.$el.find('.dashboard-date-range-popover');

        if (!$popover.length) {
            return;
        }

        const range = this.dateRange || {};
        const format = (this.getDateTime().getDateFormat() || 'YYYY-MM-DD');
        // `DateTime.weekStart` already resolves the "use system default"
        // sentinel (`-1`) from preferences down to the configured fallback.
        // Reading the raw preference here previously surfaced `-1` to the
        // datepicker, which then indexed `daysMin[-1 % 7] = undefined` and
        // rendered a stray `undefined` cell in the weekday header.
        const weekStart = this.getDateTime().weekStart;

        const $startInput = $popover.find('.dashboard-date-range-start');
        const $endInput = $popover.find('.dashboard-date-range-end');

        const startDisplay = range.start
            ? moment(range.start, this.DATE_RANGE_INTERNAL_FORMAT).format(format)
            : '';

        const endDisplay = range.end
            ? moment(range.end, this.DATE_RANGE_INTERNAL_FORMAT).format(format)
            : '';

        $startInput.val(startDisplay);
        $endInput.val(endDisplay);

        // `'linked'` makes bootstrap-datepicker actually pick today's date
        // when the footer "Today" cell is clicked (and close, thanks to
        // `autoclose: true`). With `true` it only navigates the view.
        this.dateRangeStartDatepicker = new Datepicker($startInput.get(0), {
            format: format,
            weekStart: weekStart,
            todayButton: 'linked',
            date: startDisplay,
        });

        this.dateRangeEndDatepicker = new Datepicker($endInput.get(0), {
            format: format,
            weekStart: weekStart,
            todayButton: 'linked',
            date: endDisplay,
        });

        $(document).off('mousedown.dashboard-date-range');
        $(document).on('mousedown.dashboard-date-range', e => {
            const $target = $(e.target);

            if (
                !$target.closest('.dashboard-date-range').length &&
                !$target.closest('.datepicker').length
            ) {
                this.closeDateRangePopover();
            }
        });
    }

    /**
     * @private
     */
    toggleDateRangePopover() {
        const $popover = this.$el.find('.dashboard-date-range-popover');

        if (!$popover.length) {
            return;
        }

        if ($popover.is(':visible')) {
            this.closeDateRangePopover();

            return;
        }

        $popover.removeAttr('hidden').show();

        this.$el.find('.dashboard-date-range').addClass('open');
    }

    /**
     * @private
     */
    closeDateRangePopover() {
        const $popover = this.$el.find('.dashboard-date-range-popover');

        if (!$popover.length) {
            return;
        }

        $popover.hide();
        this.$el.find('.dashboard-date-range').removeClass('open');
    }

    /**
     * @private
     */
    applyDateRangeFromInputs() {
        const format = (this.getDateTime().getDateFormat() || 'YYYY-MM-DD');

        const $startInput = this.$el.find('.dashboard-date-range-start');
        const $endInput = this.$el.find('.dashboard-date-range-end');

        const startRaw = $startInput.val();
        const endRaw = $endInput.val();

        const start = startRaw ? moment(startRaw, format) : null;
        const end = endRaw ? moment(endRaw, format) : null;

        if (!start || !start.isValid() || !end || !end.isValid()) {
            Espo.Ui.warning(this.translate('Date Range', 'labels'));

            return;
        }

        if (end.isBefore(start)) {
            Espo.Ui.warning(this.translate('Date Range', 'labels'));

            return;
        }

        const range = {
            start: start.format(this.DATE_RANGE_INTERNAL_FORMAT),
            end: end.format(this.DATE_RANGE_INTERNAL_FORMAT),
            preset: null,
        };

        this.setDateRange(range);
        this.closeDateRangePopover();
    }

    /**
     * @private
     * @param {string} preset
     */
    applyDatePreset(preset) {
        const today = moment().startOf('day');

        let start = null;
        let end = today.clone();

        switch (preset) {
            case 'today':
                start = today.clone();
                end = today.clone();
                break;
            case 'last7Days':
                start = today.clone().subtract(6, 'days');
                break;
            case 'last30Days':
                start = today.clone().subtract(29, 'days');
                break;
            case 'thisMonth':
                start = today.clone().startOf('month');
                end = today.clone().endOf('month');
                break;
            case 'lastMonth':
                start = today.clone().subtract(1, 'month').startOf('month');
                end = today.clone().subtract(1, 'month').endOf('month');
                break;
            case 'thisYear':
                start = today.clone().startOf('year');
                end = today.clone().endOf('year');
                break;
            case 'last12Months':
                start = today.clone().subtract(1, 'year');
                break;
            default:
                return;
        }

        const range = {
            start: start.format(this.DATE_RANGE_INTERNAL_FORMAT),
            end: end.format(this.DATE_RANGE_INTERNAL_FORMAT),
            // Remember which preset was selected so downstream consumers
            // (e.g. dashlets) can render a friendly localized label like
            // "Últimos 7 Dias" instead of the raw date range.
            preset: preset,
        };

        this.setDateRange(range);
        this.closeDateRangePopover();
    }

    /**
     * @private
     * @param {{start: string, end: string}} range
     */
    setDateRange(range) {
        this.dateRange = range;

        this.getStorage().set('state', 'dashboardDateRange', range);

        this.$el.find('.dashboard-date-range-label').text(this.formatDateRangeLabel(range));

        const format = (this.getDateTime().getDateFormat() || 'YYYY-MM-DD');

        this.$el.find('.dashboard-date-range-start').val(
            moment(range.start, this.DATE_RANGE_INTERNAL_FORMAT).format(format)
        );
        this.$el.find('.dashboard-date-range-end').val(
            moment(range.end, this.DATE_RANGE_INTERNAL_FORMAT).format(format)
        );

        this.trigger('dashboard-date-range-change', range);
    }

    onResize() {
        if (this.isFallbackMode() && window.innerWidth >= this.screenWidthXs) {
            this.initGridstack();
        } else if (!this.isFallbackMode() && window.innerWidth < this.screenWidthXs) {
            this.initFallbackMode();
        }
    }

    isFallbackMode() {
        return this.$dashboard.hasClass('fallback');
    }

    preserveDashletViews() {
        this.preservedDashletViews = {};
        this.preservedDashletElements = {};

        this.currentTabLayout.forEach(o => {
            const key = `dashlet-${o.id}`;
            const view = this.getView(key);

            this.unchainView(key);

            this.preservedDashletViews[o.id] = view;

            const element = view.element;

            this.preservedDashletElements[o.id] = element;

            const parent = element.parentNode;
            parent.removeChild(element);
        });
    }

    /**
     * @param {string} id
     */
    async addPreservedDashlet(id) {
        /** @type {import('view').default} */
        const view = this.preservedDashletViews[id];
        /** @type {HTMLElement} */
        const element = this.preservedDashletElements[id];

        if (!element || !view) {
            return;
        }

        const container = this.element.querySelector(`.dashlet-container[data-id="${id}"]`);

        if (!container) {
            return;
        }

        container.append(...element.childNodes);

        view.element = undefined;

        await this.setView(`dashlet-${id}`, view);
    }

    clearPreservedDashlets() {
        this.preservedDashletViews = null;
        this.preservedDashletElements = null;
    }

    hasPreservedDashlets() {
        return !!this.preservedDashletViews;
    }

    initFallbackMode() {
        if (this.grid) {
            this.grid.destroy(false);
            this.grid = null;

            this.preserveDashletViews();
        }

        this.$dashboard.empty();

        const $dashboard = this.$dashboard;

        $dashboard.addClass('fallback');

        this.currentTabLayout.forEach(o => {
            const $item = this.prepareFallbackItem(o);

            $dashboard.append($item);
        });

        this.currentTabLayout.forEach(o => {
            if (!o.id || !o.name) {
                return;
            }

            if (!this.getMetadata().get(`dashlets.${o.name}`)) {
                console.error(`Dashlet ${o.name} doesn't exist or not available.`);

                return;
            }

            if (this.hasPreservedDashlets()) {
                this.addPreservedDashlet(o.id);

                return;
            }

            this.createDashletView(o.id, o.name);
        });

        this.clearPreservedDashlets();

        if (this.fallbackModeTimeout) {
            clearTimeout(this.fallbackModeTimeout);
        }

        this.$dashboard.css('height', '');

        this.fallbackControlHeights();
    }

    fallbackControlHeights() {
        this.currentTabLayout.forEach(o => {
            const $container = this.$dashboard.find(`.dashlet-container[data-id="${o.id}"]`);

            const headerHeight = $container.find('.panel-heading').outerHeight();

            const $body = $container.find('.dashlet-body');

            const bodyEl = $body.get(0);

            if (!bodyEl) {
                return;
            }

            if (bodyEl.scrollHeight > bodyEl.offsetHeight) {
                const height = bodyEl.scrollHeight + headerHeight;

                $container.css('height', `${height}px`);
            }
        });

        this.fallbackModeTimeout = setTimeout(() => this.fallbackControlHeights(), 300);
    }

    initGridstack() {
        if (this.isFallbackMode()) {
            this.preserveDashletViews();
        }

        this.$dashboard.empty();

        const $gridstack = this.$gridstack = this.$dashboard;

        $gridstack.removeClass('fallback');

        if (this.fallbackModeTimeout) {
            clearTimeout(this.fallbackModeTimeout);
        }

        let disableDrag = false;
        let disableResize = false;

        if (this.getUser().isPortal() || this.getPreferences().get('dashboardLocked')) {
            disableDrag = true;
            disableResize = true;
        }

        const paramCellHeight = this.cellHeight;
        const paramCellMargin = this.getThemeManager().getParam('dashboardCellMargin');

        const factor = this.getThemeManager().getFontSizeFactor();

        const cellHeight = Math.ceil(factor * paramCellHeight * 1.14);
        const margin = Math.round(factor * paramCellMargin / 2);

        const grid = this.grid = GridStack.init(
            {
                cellHeight: cellHeight,
                margin: margin,
                column: 12,
                handle: '.panel-heading',
                disableDrag: disableDrag,
                disableResize: disableResize,
                disableOneColumnMode: true,
                draggable: {
                    distance: 10,
                },
                dragInOptions: {
                    scroll: false,
                },
                float: false,
                animate: false,
                scroll: false,
            },
            $gridstack.get(0)
        );

        grid.removeAll();

        this.currentTabLayout.forEach(o => {
            const $item = this.prepareGridstackItem(o.id, o.name);

            if (!this.getMetadata().get(['dashlets', o.name])) {
                return;
            }

            grid.addWidget(
                $item.get(0),
                {
                    x: o.x * this.WIDTH_MULTIPLIER,
                    y: o.y * this.HEIGHT_MULTIPLIER,
                    w: o.width * this.WIDTH_MULTIPLIER,
                    h: o.height * this.HEIGHT_MULTIPLIER,
                }
            );
        });

        $gridstack.find('.grid-stack-item').css('position', 'absolute');

        this.currentTabLayout.forEach(o => {
            if (!o.id || !o.name) {
                return;
            }

            if (!this.getMetadata().get(`dashlets.${o.name}`)) {
                console.error(`Dashlet ${o.name} doesn't exist or not available.`);

                return;
            }

            if (this.hasPreservedDashlets()) {
                this.addPreservedDashlet(o.id);

                return;
            }

            this.createDashletView(o.id, o.name);
        });

        this.clearPreservedDashlets();

        this.grid.on('change', () => {
            this.fetchLayout();
            this.saveLayout();
        });

        // noinspection SpellCheckingInspection
        this.grid.on('resizestop', e => {
            const id = $(e.target).data('id');
            const view = this.getView(`dashlet-${id}`);

            if (!view) {
                return;
            }

            view.trigger('resize');
        });
    }

    fetchLayout() {
        this.dashboardLayout[this.currentTab].layout =
            _.map(this.$gridstack.find('.grid-stack-item'), el => {
                const $el = $(el);

                const x = $el.attr('gs-x');
                const y = $el.attr('gs-y');
                const h = $el.attr('gs-h');
                const w = $el.attr('gs-w');

                return {
                    id: $el.data('id'),
                    name: $el.data('name'),
                    x: x / this.WIDTH_MULTIPLIER,
                    y: y / this.HEIGHT_MULTIPLIER,
                    width: w / this.WIDTH_MULTIPLIER,
                    height: h / this.HEIGHT_MULTIPLIER,
                };
            });
    }

    /**
     * @param {string} id
     * @param {string} name
     * @return {JQuery}
     */
    prepareGridstackItem(id, name) {
        const $item = $('<div>').addClass('grid-stack-item');
        const $container = $('<div class="grid-stack-item-content dashlet-container"></div>');

        $container.attr('data-id', id);
        $container.attr('data-name', name);

        $item.attr('data-id', id);
        $item.attr('data-name', name);

        $item.append($container);

        return $item;
    }

    /**
     * @param {Record} o
     * @return {JQuery}
     */
    prepareFallbackItem(o) {
        const $item = $('<div>');
        const $container = $('<div class="dashlet-container">');

        $container.attr('data-id', o.id);
        $container.attr('data-name', o.name);
        $container.attr('data-x', o.x);
        $container.attr('data-y', o.y);
        $container.attr('data-height', o.height);
        $container.attr('data-width', o.width);
        $container.css('height', (o.height * this.cellHeight * this.HEIGHT_MULTIPLIER) + 'px');

        $item.attr('data-id', o.id);
        $item.attr('data-name', o.name);

        $item.append($container);

        return $item;
    }

    /**
     * @param {Record} [attributes]
     */
    saveLayout(attributes) {
        if (this.layoutReadOnly) {
            return;
        }

        attributes = {
            ...(attributes || {}),
            ...{dashboardLayout: this.dashboardLayout},
        };

        this.getPreferences().save(attributes, {patch: true});

        this.getPreferences().trigger('update');
    }

    /**
     * @param {string} id
     */
    removeDashlet(id) {
        let revertToFallback = false;

        if (this.isFallbackMode()) {
            this.initGridstack();

            revertToFallback = true;
        }

        const $item = this.$gridstack.find('.grid-stack-item[data-id="' + id + '"]');

        // noinspection JSUnresolvedReference
        this.grid.removeWidget($item.get(0), true);

        const layout = this.dashboardLayout[this.currentTab].layout;

        layout.forEach((o, i) => {
            if (o.id === id) {
                layout.splice(i, 1);
            }
        });

        const o = {};

        o.dashletsOptions = this.getPreferences().get('dashletsOptions') || {};

        delete o.dashletsOptions[id];

        o.dashboardLayout = this.dashboardLayout;

        if (this.layoutReadOnly) {
            return;
        }

        this.getPreferences().save(o, {patch: true});
        this.getPreferences().trigger('update');

        const index = this.dashletIdList.indexOf(id);

        if (~index) {
            this.dashletIdList.splice(index, index);
        }

        this.clearView('dashlet-' + id);

        this.setupCurrentTabLayout();

        if (revertToFallback) {
            this.initFallbackMode();
        }
    }

    /**
     * @param {string} name
     */
    addDashlet(name) {
        let revertToFallback = false;

        if (this.isFallbackMode()) {
            this.initGridstack();

            revertToFallback = true;
        }

        const id = 'd' + (Math.floor(Math.random() * 1000001)).toString();

        const $item = this.prepareGridstackItem(id, name);

        this.grid.addWidget(
            $item.get(0),
            {
                x: 0,
                y: 0,
                w: 2 * this.WIDTH_MULTIPLIER,
                h: 2 * this.HEIGHT_MULTIPLIER,
            }
        );

        this.createDashletView(id, name, name, view => {
            this.fetchLayout();
            this.saveLayout();

            this.setupCurrentTabLayout();

            if (view.getBodyView() && view.getBodyView().afterAdding) {
                view.getBodyView().afterAdding();
            }

            if (revertToFallback) {
                this.initFallbackMode();
            }
        });
    }

    /**
     * @param {string} id
     * @param {string} name
     * @param {string} [label]
     * @param {function(import('views/dashlet').default)} [callback]
     * @return {Promise<import('views/dashlet').default>}
     */
    createDashletView(id, name, label, callback) {
        const o = {
            id: id,
            name: name,
        };

        if (label) {
            o.label = label;
        }

        return this.createView(`dashlet-${id}`, 'views/dashlet', {
            label: name,
            name: name,
            id: id,
            selector: `> .dashlets .dashlet-container[data-id="${id}"]`,
            readOnly: this.dashletsReadOnly,
            locked: this.getPreferences().get('dashboardLocked'),
        }, view => {
            this.dashletIdList.push(id);

            view.render();

            this.listenToOnce(view, 'change', () => {
                this.clearView(id);

                this.createDashletView(id, name, label);
            });

            this.listenToOnce(view, 'remove-dashlet', () => {
                this.removeDashlet(id);
            });

            if (callback) {
                callback.call(this, view);
            }
        });
    }

    editTabs() {
        const dashboardLocked = this.getPreferences().get('dashboardLocked');

        this.createView('editTabs', 'views/modals/edit-dashboard', {
            dashboardLayout: this.dashboardLayout,
            dashboardLocked: dashboardLocked,
            fromDashboard: true,
        }, view => {
            view.render();

            this.listenToOnce(view, 'after:save', data => {
                view.close();

                const dashboardLayout = [];

                const tabTitles = data.tabTitles || {};
                const tabDescriptions = data.tabDescriptions || {};
                const tabShowDateRange = data.tabShowDateRange || {};

                data.dashboardTabList.forEach(name => {
                    let layout = [];
                    let id = null;
                    let slug = null;
                    // Existing per-tab values keyed by the *pre-rename* name
                    // so we can preserve them across a rename.
                    let title = tabTitles[name] || '';
                    let description = tabDescriptions[name] || '';
                    let showDateRange = tabShowDateRange[name];
                    let showFunnelFilter;

                    this.dashboardLayout.forEach(d => {
                        if (d.name === name) {
                            layout = d.layout;
                            id = d.id;
                            slug = d.slug;
                            showFunnelFilter = d.showFunnelFilter;
                        }
                    });

                    if (name in data.renameMap) {
                        name = data.renameMap[name];
                    }

                    const o = {
                        name: name,
                        layout: layout,
                    };

                    if (id) {
                        o.id = id;
                    }

                    // Preserve a stable slug across renames so previously
                    // shared URLs keep resolving. Brand-new tabs get a slug
                    // assigned lazily on the next setupCurrentTabLayout() via
                    // ensureTabSlugs(); we don't generate here to keep the
                    // de-duplication in one place.
                    if (slug) {
                        o.slug = slug;
                    }

                    if (title) {
                        o.title = title;
                    }

                    if (description) {
                        o.description = description;
                    }

                    // Only persist the toggle when it was explicitly set to
                    // false — omitting it keeps the layout compact and lets
                    // future versions change the default safely.
                    if (showDateRange === false) {
                        o.showDateRange = false;
                    }

                    // Not editable in the modal (yet) — preserved so that a
                    // flag set via a deployed template or manually in the
                    // layout JSON survives an Edit Dashboard save.
                    if (showFunnelFilter === false) {
                        o.showFunnelFilter = false;
                    }

                    dashboardLayout.push(o);
                });

                this.dashletIdList.forEach(item => {
                    this.clearView(`dashlet-${item}`);
                });

                this.dashboardLayout = dashboardLayout;

                this.saveLayout({
                    dashboardLocked: data.dashboardLocked,
                });

                this.storeCurrentTab(0);
                this.currentTab = 0;
                this.setupCurrentTabLayout();

                this.reRender();
            });
        });
    }
}

export default DashboardView;
