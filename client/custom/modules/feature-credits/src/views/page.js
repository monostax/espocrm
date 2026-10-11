import MainView from 'views/main';
import {reports, present, sourceRoute} from 'feature-credits:helpers/presentation';

export default class extends MainView {
    template = 'feature-credits:page';
    scope = 'Credits';
    events = {
        'change [data-credit-tenant]': function (e) { this.changeTenant(e.currentTarget.value); },
        'click [data-credit-tab]': function (e) { this.changeTab(e.currentTarget.dataset.creditTab); },
        'click [data-credit-refresh]': function () { this.refresh(); },
        'click [data-credit-next]': function () { this.next(); },
        'click [data-credit-previous]': function () { this.previous(); },
        'click [data-credit-source]': function (e) { this.openSource(e.currentTarget.dataset.creditSource); },
    };

    setup() {
        super.setup();
        this.tenantId = this.options.params?.tenantId || '';
        this.tab = 'operations';
        this.version = 0;
        this.cursors = [null];
        this.on('remove', () => { this.disposed = true; ++this.version; });
        this.wait(this.bootstrap());
    }

    t(key) { return this.translate(key, 'labels', 'Credits'); }

    async bootstrap() {
        const version = ++this.version;
        this.clear();
        this.context = null;
        this.loading = true;
        try {
            const context = await Espo.Ajax.getRequest('Credits/context');
            if (this.disposed || version !== this.version) return;
            this.context = context;
            if (!this.tenantId) this.tenantId = context.tenants[0]?.id || '';
            if (this.tenantId && !context.tenants.some(t => t.id === this.tenantId)) {
                this.tenantId = '';
                this.error = this.t('forbidden');
            } else if (this.tenantId) await this.load(false);
        } catch (error) {
            error?.setHandled?.();
            if (this.disposed || version !== this.version) return;
            this.error = this.t(error?.status === 401 ? 'sessionExpired' : error?.status === 403 ? 'forbidden' : 'loadError');
        } finally {
            if (!this.disposed && version === this.version) this.loading = false;
        }
    }

    clear() {
        this.balance = null;
        this.page = null;
        this.error = null;
        this.sourceMessage = null;
        this.openingSource = false;
    }

    async load(render = true) {
        const version = ++this.version;
        const tenantId = this.tenantId;
        const tab = this.tab;
        const cursor = this.cursors[this.cursors.length - 1];
        this.clear();
        this.loading = true;
        if (render) await this.reRender();
        if (this.disposed || version !== this.version) return;
        try {
            const query = {tenantId, limit: '25'};
            if (cursor) query.cursor = cursor;
            const [balance, page] = await Promise.all([
                Espo.Ajax.getRequest('CreditBalance', {tenantId}),
                Espo.Ajax.getRequest(reports[tab].endpoint, query),
            ]);
            if (this.disposed || version !== this.version) return;
            if (balance.tenantId !== tenantId || page.tenantId !== tenantId) throw new Error('Mismatched credit tenant.');
            this.balance = balance;
            this.page = page;
            this.getRouter().navigate('Credits/index/' + new URLSearchParams({tenantId}).toString(), {trigger: false, replace: true});
        } catch (error) {
            error?.setHandled?.();
            if (this.disposed || version !== this.version) return;
            this.error = this.t(error?.status === 403 ? 'forbidden' : error?.status === 401 ? 'sessionExpired' : 'loadError');
        } finally {
            if (!this.disposed && version === this.version) {
                this.loading = false;
                if (render) await this.reRender();
            }
        }
    }

    changeTenant(id) {
        if (!this.context?.tenants.some(t => t.id === id)) {
            ++this.version;
            this.tenantId = '';
            this.clear();
            this.loading = false;
            this.cursors = [null];
            return this.reRender();
        }
        this.tenantId = id;
        this.cursors = [null];
        return this.load();
    }

    changeTab(tab) {
        if (!Object.hasOwn(reports, tab) || !this.tenantId) return;
        this.tab = tab;
        this.cursors = [null];
        return this.load();
    }

    async refresh() {
        if (this.loading) return;
        this.cursors = [null];
        if (this.context && this.tenantId) return this.load();
        await this.bootstrap();
        if (!this.disposed) await this.reRender();
    }

    next() {
        if (this.loading || !this.page?.nextCursor) return;
        this.cursors.push(this.page.nextCursor);
        return this.load();
    }

    previous() {
        if (this.loading || this.cursors.length < 2) return;
        this.cursors.pop();
        return this.load();
    }

    async openSource(id) {
        if (this.loading || this.openingSource || !this.page?.list.some(row =>
            (this.tab === 'operations' ? row.id : row.usageId) === id)) return;
        const version = this.version;
        const tenantId = this.tenantId;
        this.openingSource = true;
        this.sourceMessage = null;
        try {
            const payload = await Espo.Ajax.getRequest('CreditOperationSource', {tenantId, id});
            if (this.disposed || version !== this.version) return;
            const route = sourceRoute(payload, tenantId, id);
            if (route) this.getRouter().navigate(route, {trigger: true});
            else this.sourceMessage = this.t('sourceUnavailable');
        } catch (error) {
            error?.setHandled?.();
            if (this.disposed || version !== this.version) return;
            if ([401, 403].includes(error?.status)) {
                this.clear();
                this.error = this.t(error.status === 401 ? 'sessionExpired' : 'forbidden');
            } else this.sourceMessage = this.t('sourceUnavailable');
        } finally {
            if (!this.disposed && version === this.version) {
                this.openingSource = false;
                await this.reRender();
            }
        }
    }

    data() {
        return {
            ...present(this.balance, this.page, this.tab, key => this.t(key)),
            loading: this.loading, error: this.error, sourceMessage: this.sourceMessage,
            hasData: !!this.page, empty: this.page?.list.length === 0,
            noAccess: this.context && !this.context.tenants.length,
            tenants: (this.context?.tenants || []).map(t => ({...t, selected: t.id === this.tenantId})),
            tabs: Object.keys(reports).map(key => ({key, label: this.t(key), selected: key === this.tab})),
            canNext: !this.loading && !!this.page?.nextCursor,
            canPrevious: !this.loading && this.cursors.length > 1,
            legacyUrl: '#AiUsage/index/' + new URLSearchParams({tenantId: this.tenantId}).toString(),
        };
    }
}
