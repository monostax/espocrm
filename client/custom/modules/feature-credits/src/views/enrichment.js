import BottomPanelView from 'views/record/panels/bottom';
import {EnrichmentSession, fieldChoices} from 'feature-credits:helpers/enrichment';
import {formatCredits} from 'feature-credits:helpers/presentation';

export default class extends BottomPanelView {
    template = 'feature-credits:enrichment';

    setup() {
        super.setup();
        this.version = 0;
        this.product = 'email';
        this.items = [];
        this.addHandler('submit', '[data-enrichment-quote]', e => { e.preventDefault(); this.quote(e.currentTarget); });
        this.addHandler('click', '[data-enrichment-confirm]', () => this.progress('confirm'));
        this.addHandler('click', '[data-enrichment-resume]', () => this.progress('resume'));
        this.addHandler('click', '[data-enrichment-new]', () => this.run(async () => { this.session.reset(this.receipt); this.receipt = null; }));
        this.addHandler('change', '[data-enrichment-history-product]', e => { if (this.busy) return; this.product = e.currentTarget.value; this.history(false); });
        this.addHandler('click', '[data-enrichment-history]', () => this.history(false));
        this.addHandler('click', '[data-enrichment-more]', () => this.history(true));
        this.addHandler('click', '[data-enrichment-review]', e => this.review(e.currentTarget.dataset.enrichmentReview));
        this.addHandler('submit', '[data-enrichment-apply]', e => { e.preventDefault(); this.apply(e.currentTarget); });
        this.addHandler('change', '[name=createIfUnmatched]', e => {
            const fields = e.currentTarget.form.querySelector('[data-enrichment-create]');
            fields.disabled = !e.currentTarget.checked;
            fields.hidden = !e.currentTarget.checked;
        });
        this.listenTo(this.model, 'change:tenantId change:id', () => this.context());
        this.on('remove', () => { ++this.version; this.disposed = true; this.detail = this.current = null; });
        this.wait(this.context());
    }

    t(key) { return this.translate(key, 'labels', 'CreditEnrichment'); }
    active(version) { return !this.disposed && version === this.version; }

    async context() {
        ++this.version;
        this.busy = false; this.error = null; this.receipt = this.detail = this.current = this.session = this.saved = this.applicationCommand = null; this.items = []; this.next = null;
        if (!this.model.id || !this.model.get('tenantId')) return;
        try {
            this.session = new EnrichmentSession({post: (url, body) => Espo.Ajax.postRequest(url, body), storage: window.sessionStorage,
                actorId: this.getUser().id, tenantId: this.model.get('tenantId'), targetType: this.model.entityType, targetId: this.model.id});
            // Recovery requires an explicit click; mounting never starts a saved confirmation.
            this.saved = this.session.read();
        } catch { this.error = this.t('storageUnavailable'); }
        if (this.isRendered()) await this.reRender();
    }

    async run(action) {
        if (this.busy || !this.session) return;
        const version = this.version;
        this.busy = true; this.error = null;
        try {
            // Keep the callback's session local; stale callbacks must never mutate a new record.
            const result = await action(version);
            if (!this.active(version)) return;
            this.saved = this.session.read();
            return result;
        } catch (e) {
            e?.setHandled?.();
            if (!this.active(version)) return;
            this.error = this.t(e?.status === 401 ? 'sessionExpired' : e?.status === 403 ? 'forbidden' : e?.status === 409 ? 'conflict' : 'unavailable');
            if ([401, 403].includes(e?.status)) { this.detail = this.current = this.receipt = null; this.items = []; }
        } finally {
            if (this.active(version)) {
                this.busy = false;
                try { this.saved = this.session.read(); } catch { this.error = this.t('storageUnavailable'); }
                if (this.isRendered()) await this.reRender();
            }
        }
    }

    progress(action) {
        const session = this.session;
        return this.run(async version => {
            const receipt = await session[action]();
            if (this.active(version)) this.receipt = receipt;
        });
    }

    quote(form) {
        const f = form.elements;
        const product = f.product.value;
        const settings = {products: product === 'combined' ? ['email', 'phone'] : [product], waterfall: f.waterfall.checked, refresh: f.refresh.checked};
        if (f.budget.value.trim()) settings.budget = f.budget.value.trim();
        if (['employees', 'socios'].includes(product)) {
            settings.count = Number(f.count.value);
            if (product === 'employees') settings.filters = {
                titles: f.titles.value.split(',').map(v => v.trim()).filter(Boolean),
                seniorities: f.seniorities.value.split(',').map(v => v.trim()).filter(Boolean),
            };
        }
        const session = this.session;
        return this.run(async version => {
            const receipt = await session.quote(settings);
            if (this.active(version)) this.receipt = receipt;
        });
    }

    history(more) {
        const params = {...this.session?.scope, product: this.product, ...(more ? this.next : {}), limit: 20};
        if (this.busy) return;
        if (!more) { this.items = []; this.next = null; this.detail = this.current = null; }
        return this.run(async version => {
            const data = await Espo.Ajax.getRequest('CreditEnrichmentHistory', params);
            if (!this.active(version)) return;
            this.items = more ? [...this.items, ...data.list] : data.list;
            this.next = data.next;
        });
    }

    review(id) {
        if (!this.items.some(item => item.receipt.id === id)) return;
        const finder = ['employees', 'socios'].includes(this.product);
        const scope = {...this.session.scope};
        this.detail = this.current = null;
        this.applicationCommand = null;
        return this.run(async version => {
            const detail = await Espo.Ajax.getRequest('CreditEnrichmentReview', {tenantId: scope.tenantId, id, ...(finder ? {} : {source: 'field'})});
            const current = finder ? null : await Espo.Ajax.getRequest(scope.targetType + '/' + encodeURIComponent(scope.targetId));
            if (!this.active(version)) return;
            this.detail = detail; this.current = current; this.finder = finder;
        });
    }

    apply(form) {
        if (!this.detail?.application.available) return;
        const tenantId = this.session.scope.tenantId;
        const id = this.detail.receipt.id;
        let url; let body;
        if (this.finder) {
            url = 'CreditEnrichmentApplication'; body = {tenantId, purchaseId: id, createIfUnmatched: !!form.elements.createIfUnmatched?.checked};
            if (body.createIfUnmatched) {
                const schema = this.detail.application.creationForm;
                if (!schema?.available) return;
                body.contactData = {};
                for (const field of schema.fields) {
                    const value = form.elements['contact-' + field.name].value;
                    if (!field.required && !value) continue;
                    body.contactData[field.name] = field.type === 'bool' ? value === 'true' : field.type === 'int' ? Number(value) : value.trim();
                }
                if (schema.teamsAllowed) body.contactData.teamsIds = [...form.elements.teamsIds.selectedOptions].map(option => option.value);
            }
        } else {
            const fields = {}; const expected = {};
            for (const select of form.querySelectorAll('[data-enrichment-field]')) {
                if (!select.value) continue;
                const field = select.dataset.enrichmentField;
                fields[field] = select.value; expected[field] = this.current[field] ?? null;
            }
            if (!Object.keys(fields).length) return;
            url = 'CreditEnrichmentFields'; body = {tenantId, id, source: 'field', fields, expected};
        }
        // A lost response retains the exact command in memory; review after reload reads the protected application status.
        if (this.applicationCommand?.body.id === id || this.applicationCommand?.body.purchaseId === id) {
            ({url, body} = this.applicationCommand);
        } else this.applicationCommand = {url, body};
        return this.run(async version => {
            try { await Espo.Ajax.postRequest(url, body); }
            catch (e) { if (this.active(version) && [400, 409].includes(e?.status)) this.applicationCommand = null; throw e; }
            if (!this.active(version)) return;
            this.detail = null; this.current = null; this.applicationCommand = null;
            this.model.fetch();
        });
    }

    data() {
        const products = ['email', 'phone', ...(this.model.entityType === 'Account' ? ['company', 'employees', 'socios'] : [])];
        const receipt = this.receipt;
        return {...super.data(), busy: this.busy, error: this.error, hasContext: !!this.session, saved: this.saved,
            canQuote: !this.saved, receipt, canConfirm: receipt?.confirmable && receipt.admission === 'not_admitted',
            canReset: this.saved?.stage === 'quote' || ['settled', 'released'].includes(receipt?.admission),
            maximum: formatCredits(receipt?.maximum),
            routes: Object.entries(receipt?.routes || {}).map(([product, steps]) => ({product: this.t(product), steps: steps.map(step => ({...step, credits: formatCredits(step.credits)}))})),
            progress: Object.entries(receipt?.executionProducts || {}).map(([product, value]) => ({product: this.t(product), state: this.t(value.state), stopReason: value.stopReason})),
            products: products.map(value => ({value, label: this.t(value), selected: this.product === value})), account: this.model.entityType === 'Account',
            items: this.items.map(item => ({...item, credits: formatCredits(item.receipt.credits), product: this.t(item.receipt.product)})), next: this.next,
            detail: this.detail, finder: this.finder,
            creationTeams: (this.detail?.application.creationForm?.teams || []).map((team, index, teams) => ({...team, selected: teams.length === 1})),
            creationFields: (this.detail?.application.creationForm?.fields || []).map(field => ({...field,
                label: this.translate(field.name, 'fields', 'Contact'),
                value: field.name === 'lastName' ? this.detail?.values.name || '' : '',
                isEnum: field.type === 'enum', isBool: field.type === 'bool', isText: field.type === 'text',
                inputType: field.type === 'int' ? 'number' : field.type === 'date' ? 'date' : 'text',
            })),
            values: Object.entries(this.detail?.values || {}).map(([field, value]) => ({field, value: typeof value === 'object' ? JSON.stringify(value) : value})),
            choices: this.detail && !this.finder ? fieldChoices(this.detail).map(choice => ({...choice, current: this.current?.[choice.field] ?? ''})) : [],
        };
    }
}
