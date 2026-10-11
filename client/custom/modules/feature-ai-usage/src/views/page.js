import MainView from 'views/main';
import Datepicker from 'ui/datepicker';
import {Format, present} from 'feature-ai-usage:helpers/index';

export default class extends MainView {
    template = 'feature-ai-usage:page';
    scope = 'AiUsage';
    events = {
        'change [data-au-tenant]': function (e) { this.changeContext('tenantId', e.currentTarget.value); },
        'change [data-au-month]': function (e) { this.selectMonth(e.currentTarget.value); },
        'click [data-au-view]': function (e) { this.selectView(e.currentTarget.dataset.auView); },
        'click [data-au-refresh]': function () { this.load(); },
        'change [data-au-dimension]': function (e) { this.state.dimension = e.currentTarget.value; this.state.offset = 0; this.load(); },
        'change [data-au-filter]': function (e) { this.setFilter(e.currentTarget.dataset.auFilter, e.currentTarget.value); },
        'click [data-au-clear]': function () { this.state.filters = {}; this.state.filterLabels = {}; this.state.offset = 0; this.load(); },
        'click [data-au-remove]': function (e) { this.setFilter(e.currentTarget.dataset.auRemove, ''); },
        'click [data-au-day]': function (e) { this.openDay(e.currentTarget.dataset.auDay); },
        'click [data-au-row]': function (e) { this.openRow(Number(e.currentTarget.dataset.auRow)); },
        'click [data-au-page]': function (e) { this.state.offset += Number(e.currentTarget.dataset.auPage) * 25; this.load(); },
        'click [data-au-detail]': function (e) { this.openDetail(e.currentTarget.dataset.auDetail); },
        'click [data-au-card]': function () { this.selectView('breakdown'); },
    };

    setup() {
        super.setup();
        const params = this.options.params || {};
        this.state = {tenantId: params.tenantId || '', month: params.month || '', view: 'overview', dimension: 'kind', offset: 0, filters: {}, filterLabels: {}};
        this.format = new Format(this.getPreferences().get('language') || this.getConfig().get('language') || 'en_US');
        this.requestVersion = 0;
        this.on('remove', () => { this.disposed = true; this.requestVersion++; });
        this.on('render remove', () => {
            this.$monthInput?.datepicker('destroy');
            this.$monthInput = null;
        });
        this.wait(this.bootstrap());
    }

    afterRender() {
        if (this.loading || !this.context?.currentMonth) return;
        this.$monthInput = this.$el.find('[data-au-month]');
        new Datepicker(this.$monthInput.get(0), {
            format: 'YYYY-MM', date: this.state.month,
            weekStart: this.getDateTime().weekStart,
            startDate: new Date(2000, 0, 1),
        });
    }

    t(key) {
        return this.translate(key, 'labels', 'AiUsage');
    }

    async bootstrap() {
        try {
            this.context = await Espo.Ajax.getRequest('AiUsage/context');
            if (this.disposed) return;
            if (!this.context.tenants.some(t => t.id === this.state.tenantId)) this.state.tenantId = this.context.tenants[0]?.id || '';
            this.state.month ||= this.context.currentMonth;
            if (this.state.tenantId) await this.load(false);
        } catch (error) {
            this.error = this.t('loadError');
            error?.setHandled?.();
        }
    }

    async load(render = true) {
        if (!this.context) {
            await this.bootstrap();
            if (!this.disposed) this.reRender();
            return;
        }
        if (!this.state.tenantId) return;
        const version = ++this.requestVersion;
        this.loading = true;
        this.error = null;
        if (render) await this.reRender();
        try {
            const data = await Espo.Ajax.getRequest('AiUsage', {
                // Analytics uses the overview aggregate, including the previous-month comparison.
                tenantId: this.state.tenantId, month: this.state.month, view: this.state.view === 'analytics' ? 'overview' : this.state.view,
                dimension: this.state.dimension, offset: this.state.offset, limit: 25, ...this.state.filters,
            });
            if (version !== this.requestVersion || this.disposed) return;
            this.payload = data;
            this.getRouter().navigate('AiUsage/index/' + new URLSearchParams({tenantId: this.state.tenantId, month: this.state.month}).toString(), {trigger: false, replace: true});
        } catch (error) {
            if (version !== this.requestVersion || this.disposed) return;
            this.payload = null;
            this.error = this.t(error?.status === 403 ? 'forbidden' : 'loadError');
            error?.setHandled?.();
        } finally {
            if (version === this.requestVersion && !this.disposed) {
                this.loading = false;
                if (render) await this.reRender();
            }
        }
    }

    data() {
        return {
            ...present(this.payload, this.state, this.format, key => this.t(key)),
            loading: this.loading, error: this.error, hasData: !!this.payload,
            showTokenUsage: this.getUser().isAdmin(),
            noAccess: this.context && !this.context.tenants.length,
            tenants: (this.context?.tenants || []).map(t => ({...t, selected: t.id === this.state.tenantId})),
            month: this.state.month, maxMonth: this.context?.currentMonth, selectedView: this.state.view,
            creditsUrl: this.state.tenantId && this.context?.tenants.some(t => t.id === this.state.tenantId)
                ? '#Credits/index/' + new URLSearchParams({tenantId: this.state.tenantId}).toString() : null,
            creditsLabel: this.translate('title', 'labels', 'Credits'),
            filters: this.state.filters,
            chips: Object.entries(this.state.filters).map(([key, value]) => ({key, label: this.t(key), value: this.state.filterLabels[key] || (['kind', 'action'].includes(key) ? this.t(value) : value)})),
            hasFilters: Object.keys(this.state.filters).length > 0,
        };
    }

    changeContext(key, value) {
        this.state[key] = value;
        this.state.offset = 0;
        this.state.filters = {};
        this.state.filterLabels = {};
        this.payload = null;
        this.load();
    }

    selectMonth(value) {
        if (this.loading || this.disposed || value === this.state.month) return;
        if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(value) || value < '2000-01' || value > this.context.currentMonth) {
            this.$monthInput.datepicker('update', this.state.month);
            return;
        }
        this.changeContext('month', value);
    }

    selectView(view) {
        if (!['overview', 'breakdown', 'activity', 'analytics'].includes(view)) return;
        if (view === 'analytics' && !this.getUser().isAdmin()) return;
        this.state.view = view;
        this.state.offset = 0;
        // Overview always describes the complete tenant-month.
        if (view === 'overview') {
            this.state.filters = {};
            this.state.filterLabels = {};
        }
        this.load();
    }

    setFilter(key, value) {
        if (['from', 'to'].includes(key) && value) {
            const filters = {...this.state.filters, [key]: value};
            if (value.slice(0, 7) !== this.state.month || (filters.from && filters.to && filters.from > filters.to)) {
                this.error = this.t('invalidDates');
                this.reRender();
                return;
            }
        }
        if (value) this.state.filters[key] = value;
        else { delete this.state.filters[key]; delete this.state.filterLabels[key]; }
        this.state.offset = 0;
        this.load();
    }

    openDay(day) {
        this.state.filters.from = day;
        this.state.filters.to = day;
        this.selectView('activity');
    }

    openRow(index) {
        const row = this.payload?.breakdown?.list[index];
        if (!row?.key) return;
        const dimension = this.state.dimension;
        const key = {agent: 'agentId', account: 'chatwootAccountId', conversation: 'conversationId', opportunity: 'opportunityId'}[dimension] || dimension;
        this.state.filters[key] = row.key;
        this.state.filterLabels[key] = row.record?.name || (['kind', 'action'].includes(key) ? this.t(row.key) : this.t('restrictedRecord'));
        this.selectView('activity');
    }

    async openDetail(id) {
        const view = await this.createView('usageDetail', 'feature-ai-usage:views/detail', {
            id, tenantId: this.state.tenantId, month: this.state.month, format: this.format, timeZone: this.payload.period.timeZone,
        });
        view.render();
    }
}
