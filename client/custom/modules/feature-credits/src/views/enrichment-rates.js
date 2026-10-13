import MainView from 'views/main';

export default class extends MainView {
    template = 'feature-credits:enrichment-rates';
    scope = 'Credits';

    setup() {
        super.setup();
        this.allowed = this.getUser().isAdmin();
        this.key = 'credit-enrichment-rates-v1:' + this.getUser().id;
        this.addHandler('submit', '[data-rate-draft]', e => { e.preventDefault(); this.review(e.currentTarget); });
        this.addHandler('click', '[data-rate-publish]', () => this.publish());
        this.addHandler('click', '[data-rate-edit]', () => { if (!this.busy && !this.pending) { this.command = null; this.reRender(); } });
        this.addHandler('click', '[data-rate-current]', () => this.load());
        this.on('remove', () => { this.disposed = true; });
        if (this.allowed) this.wait(this.load());
    }

    t(key) { return this.translate(key, 'labels', 'CreditEnrichmentRates'); }

    async run(action) {
        if (this.busy || !this.allowed) return;
        this.busy = true; this.error = null;
        try { await action(); }
        catch (e) {
            e?.setHandled?.();
            if (this.disposed) return;
            this.error = this.t(e?.status === 401 ? 'sessionExpired' : e?.status === 403 ? 'forbidden' : 'unavailable');
            if ([401,403].includes(e?.status)) { this.card = this.command = this.published = null; this.allowed = false; }
        } finally {
            if (!this.disposed) { this.busy = false; if (this.isRendered()) await this.reRender(); }
        }
    }

    load() {
        return this.run(async () => {
            const raw = window.sessionStorage.getItem(this.key);
            const saved = raw ? JSON.parse(raw) : null;
            if (saved && (!saved.command || typeof saved.policyId !== 'string')) throw new Error('Invalid publication journal.');
            const card = await Espo.Ajax.getRequest('CreditEnrichmentConfiguration',saved ? {policyId: saved.policyId} : {});
            if (this.disposed) return;
            this.card = card;
            this.command = saved?.command || null;
            this.pending = !!saved;
        });
    }

    review(form) {
        if (this.busy || this.pending || !this.card) return;
        this.command = {
            version: form.elements.version.value.trim(),
            effectiveFrom: form.elements.effectiveFrom.value.replace('T',' ') + ':00',
            routingPolicy: this.card.routingPolicy,
            rates: this.card.rates.map((rate, index) => ({...rate,
                credits: form.elements['rate-' + index].value.trim(),
            })),
        };
        this.published = null;
        this.reRender();
    }

    publish() {
        if (!this.command) return;
        const command = this.command;
        return this.run(async () => {
            // Persist exact reviewed terms before POST. Reload/retry never invents a new version.
            const raw = JSON.stringify({command, policyId: this.card.policyId});
            window.sessionStorage.setItem(this.key,raw);
            if (window.sessionStorage.getItem(this.key) !== raw) throw new Error('Retry storage unavailable.');
            this.pending = true;
            let receipt;
            try { receipt = await Espo.Ajax.postRequest('CreditEnrichmentConfiguration',command); }
            catch (e) {
                if ([400,409].includes(e?.status)) {
                    if (window.sessionStorage.getItem(this.key) === raw) window.sessionStorage.removeItem(this.key);
                    this.pending = false;
                }
                throw e;
            }
            if (window.sessionStorage.getItem(this.key) === raw) window.sessionStorage.removeItem(this.key);
            if (this.disposed) return;
            this.published = receipt.policy;
            this.pending = false; this.command = null;
        });
    }

    data() {
        return {...super.data(), allowed: this.allowed, busy: this.busy, error: this.error,
            card: this.card, command: this.command, published: this.published, pending: this.pending,
            canEdit: this.command && !this.pending,
            rates: (this.command?.rates || this.card?.rates || []).map((rate,index) => ({...rate,index,
                inactive: rate.provider === 'registrobr' || (rate.provider === 'hub' && rate.product === 'socios'),
            })),
            routes: this.card ? [
                {name: 'waterfallOff', providers: this.card.routing.waterfallOff.join(' → ')},
                ...['Contact','Account','company','combinedOrder'].map(name => ({name,providers: this.card.routing[name].join(' → ')})),
                ...Object.entries(this.card.routing.recordDiscovery || {}).filter(([name]) => name !== 'cnpj').map(([name,route]) => ({name,providers: route.waterfallOn.join(' → ')})),
            ] : [],
        };
    }
}
