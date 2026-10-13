import MainView from 'views/main';
import {formatCredits} from 'feature-credits:helpers/presentation';

const copy = value => JSON.parse(JSON.stringify(value));
const offerKeys = ['offerId', 'version', 'productId', 'productVersion', 'countries', 'currency', 'price', 'interval'];

export default class extends MainView {
    template = 'feature-credits:billing-catalog';
    scope = 'Credits';

    setup() {
        super.setup();
        this.actorId = this.getUser().id;
        this.allowed = this.getUser().isAdmin();
        this.key = 'credit-billing-catalog-v1:' + this.actorId;
        this.draft = {version: '', effectiveFrom: '', products: [], offers: []};
        this.addHandler('submit', '[data-catalog-draft]', e => { e.preventDefault(); this.review(e.currentTarget); });
        this.addHandler('click', '[data-catalog-add]', e => this.changeRows(e.currentTarget, true));
        this.addHandler('click', '[data-catalog-remove]', e => this.changeRows(e.currentTarget, false));
        this.addHandler('click', '[data-catalog-publish]', () => this.publish());
        this.addHandler('click', '[data-catalog-load]', () => this.load());
        this.addHandler('click', '[data-catalog-edit]', () => {
            if (!this.busy && !this.pending && this.authorized()) { this.command = null; this.reRender(); }
        });
        this.on('remove', () => { this.disposed = true; });
        if (this.allowed) this.wait(this.load());
    }

    t(key) { return this.translate(key, 'labels', 'CreditBillingCatalog'); }

    authorized() {
        if (this.disposed) return false;
        if (!this.allowed || !this.getUser().isAdmin() || this.getUser().id !== this.actorId) {
            this.allowed = false;
            this.card = this.command = this.published = null;
            this.draft = {version: '', effectiveFrom: '', products: [], offers: []};
            return false;
        }
        return true;
    }

    async run(action) {
        if (this.busy || !this.authorized()) return;
        this.busy = true; this.error = null;
        try { await action(); }
        catch (e) {
            e?.setHandled?.();
            if (this.disposed) return;
            this.error = this.t(e?.status === 401 ? 'sessionExpired' : e?.status === 403 ? 'forbidden' :
                [400, 409].includes(e?.status) ? 'rejected' : 'unavailable');
            if ([401, 403].includes(e?.status)) { this.allowed = false; this.authorized(); }
        } finally {
            if (!this.disposed) { this.busy = false; if (this.isRendered()) await this.reRender(); }
        }
    }

    load() {
        return this.run(async () => {
            const raw = window.sessionStorage.getItem(this.key);
            if (raw) {
                const saved = JSON.parse(raw);
                if (!saved.command || !Array.isArray(saved.command.products) || !Array.isArray(saved.command.offers)) {
                    throw new Error('Invalid publication journal.');
                }
                this.command = saved.command; this.draft = copy(saved.command);
                this.pending = true; this.journal = raw;
                // Recovery must not depend on the current catalog still being available.
                return;
            }
            const card = await Espo.Ajax.getRequest('CreditBillingConfiguration');
            if (!this.authorized()) return;
            this.card = card; this.command = null; this.pending = false;
            this.draft = {version: '', effectiveFrom: '', products: copy(card.products),
                offers: card.offers.map(offer => Object.fromEntries(offerKeys.map(key => [key, copy(offer[key])])))};
        });
    }

    capture(form) {
        const value = name => form.elements.namedItem(name).value.trim();
        this.draft = {
            version: value('version'), effectiveFrom: value('effectiveFrom').replace('T', ' '),
            products: this.draft.products.map((product, i) => Object.fromEntries(
                ['productId', 'productVersion', 'name', 'operation', 'credits'].map(key => [key, value(`product-${i}-${key}`)]))),
            offers: this.draft.offers.map((offer, i) => Object.fromEntries(offerKeys.map(key => {
                const raw = value(`offer-${i}-${key}`);
                return [key, key === 'countries' ? raw.split(',').map(country => country.trim().toUpperCase()).filter(Boolean) :
                    key === 'interval' ? raw || null : raw];
            }))),
        };
        if (this.draft.effectiveFrom.length === 16) this.draft.effectiveFrom += ':00';
    }

    changeRows(button, add) {
        if (this.busy || this.command || !this.authorized()) return;
        this.capture(button.closest('form'));
        const kind = button.getAttribute(add ? 'data-catalog-add' : 'data-catalog-remove');
        if (!['products', 'offers'].includes(kind)) return;
        if (add && this.draft[kind].length < 100) this.draft[kind].push(kind === 'products' ?
            {productId: '', productVersion: '', name: '', operation: 'purchase', credits: ''} :
            {offerId: '', version: '', productId: '', productVersion: '', countries: [], currency: 'BRL', price: '', interval: null});
        if (!add) this.draft[kind].splice(Number(button.getAttribute('data-index')), 1);
        this.reRender();
    }

    review(form) {
        if (this.busy || this.pending || !this.authorized() || !form.reportValidity()) return;
        this.capture(form);
        this.command = copy(this.draft); this.published = null; this.error = null;
        this.reRender();
    }

    publish() {
        if (!this.command) return;
        return this.run(async () => {
            const raw = JSON.stringify({command: this.command});
            const existing = window.sessionStorage.getItem(this.key);
            if (existing && existing !== raw) throw new Error('Another publication is pending.');
            window.sessionStorage.setItem(this.key, raw);
            if (window.sessionStorage.getItem(this.key) !== raw) throw new Error('Retry storage unavailable.');
            const wasPending = this.pending;
            this.pending = true; this.journal = raw;
            let receipt;
            try { receipt = await Espo.Ajax.postRequest('CreditBillingConfiguration', copy(this.command)); }
            catch (e) {
                // A first definitive rejection is editable. An earlier lost reply stays recoverable.
                if (!wasPending && [400, 409].includes(e?.status) && window.sessionStorage.getItem(this.key) === raw) {
                    window.sessionStorage.removeItem(this.key); this.pending = false;
                }
                throw e;
            }
            if (!receipt?.catalog || receipt.catalog.version !== this.command.version ||
                receipt.catalog.effectiveFrom !== this.command.effectiveFrom || typeof receipt.catalog.catalogId !== 'string') {
                throw new Error('Uncertain publication receipt.');
            }
            if (window.sessionStorage.getItem(this.key) === raw) window.sessionStorage.removeItem(this.key);
            if (!this.authorized()) return;
            this.published = receipt.catalog; this.pending = false; this.command = null;
            this.draft = {version: '', effectiveFrom: '', products: [], offers: []};
        });
    }

    data() {
        const draft = this.command || this.draft;
        const rows = (kind, fields) => (draft?.[kind] || []).map((row, index) => ({index,
            fields: fields.map(key => ({key, name: `${kind === 'products' ? 'product' : 'offer'}-${index}-${key}`,
                label: this.t(key), value: key === 'countries' ? row[key].join(', ') : row[key] ?? '',
                display: key === 'credits' ? formatCredits(row[key]) : key === 'countries' ? row[key].join(', ') :
                    key === 'interval' ? this.t(row[key] === 'month' ? 'month' : 'nonExpiring') : row[key],
                required: key !== 'interval',
                options: ({operation: ['purchase', 'subscription'], currency: ['BRL', 'USD'], interval: ['', 'month']}[key] || [])
                    .map(value => ({value, label: key === 'interval' ? this.t(value ? 'month' : 'nonExpiring') : value,
                        selected: value === (row[key] ?? '')})),
                pattern: key === 'credits' ? '[0-9]+(\\.[0-9]{1,4})?' : key === 'price' ? '[0-9]+(\\.[0-9]{1,2})?' :
                    key === 'operation' ? 'purchase|subscription' : key === 'currency' ? 'BRL|USD' :
                        key === 'interval' ? 'month' : key === 'countries' ? '[A-Za-z]{2}( *, *[A-Za-z]{2})*' : undefined,
            })),
        }));
        return {...super.data(), allowed: this.allowed, busy: this.busy, error: this.error,
            card: this.card, command: this.command, pending: this.pending, published: this.published,
            draft, effectiveInput: draft?.effectiveFrom?.replace(' ', 'T'), canEdit: this.command && !this.pending,
            products: rows('products', ['productId', 'productVersion', 'name', 'operation', 'credits']),
            offers: rows('offers', offerKeys),
        };
    }
}
