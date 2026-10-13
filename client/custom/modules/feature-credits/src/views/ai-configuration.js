import MainView from 'views/main';
import {formatCredits} from 'feature-credits:helpers/presentation';

const copy = value => JSON.parse(JSON.stringify(value));
const modelFields = ['provider', 'model', 'multiplier', 'inputTokenBound', 'outputTokenLimit', 'boundProfile'];
const agreementFields = ['tenantId', 'version', 'effectiveFrom', 'effectiveUntil', 'currency', 'creditUnitPrice',
    'monthlyCredits', 'aiModelCreditRateId', 'aiNativeSearchModelCreditRateId', 'aiMaxRequests'];
const integers = ['inputTokenBound', 'outputTokenLimit', 'aiMaxRequests'];
const dates = ['effectiveFrom', 'effectiveUntil'];
const operations = ['publishModel', 'publishAgreement', 'replaceAgreement'];

export default class extends MainView {
    template = 'feature-credits:ai-configuration';
    scope = 'Credits';

    setup() {
        super.setup();
        this.actorId = this.getUser().id;
        this.allowed = this.getUser().isAdmin();
        this.key = 'credit-ai-configuration-v1:' + this.actorId;
        this.operation = 'publishModel'; this.draft = {};
        this.addHandler('click', '[data-config-mode]', e => this.mode(e.currentTarget.getAttribute('data-config-mode')));
        this.addHandler('submit', '[data-config-lookup]', e => { e.preventDefault(); this.lookup(e.currentTarget); });
        this.addHandler('submit', '[data-config-draft]', e => { e.preventDefault(); this.review(e.currentTarget); });
        this.addHandler('click', '[data-config-publish]', () => this.publish());
        this.addHandler('click', '[data-config-edit]', () => {
            if (!this.busy && !this.pending && this.authorized()) { this.command = null; this.reRender(); }
        });
        this.on('remove', () => { this.disposed = true; });
        if (this.allowed) this.wait(this.restore());
    }

    t(key) { return this.translate(key, 'labels', 'CreditAiConfiguration'); }

    authorized() {
        if (this.disposed) return false;
        if (!this.allowed || !this.getUser().isAdmin() || this.getUser().id !== this.actorId) {
            this.allowed = false; this.command = this.original = this.published = null; this.draft = {};
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
            if (!this.authorized()) return;
            this.error = this.t(e?.status === 401 ? 'sessionExpired' : e?.status === 403 ? 'forbidden' :
                [400, 404, 409].includes(e?.status) ? 'rejected' : 'unavailable');
            if ([401, 403].includes(e?.status)) { this.allowed = false; this.authorized(); }
        } finally {
            if (!this.disposed) { this.busy = false; if (this.isRendered()) await this.reRender(); }
        }
    }

    restore() {
        return this.run(async () => {
            const raw = window.sessionStorage.getItem(this.key);
            if (!raw) return;
            const saved = JSON.parse(raw);
            if (!operations.includes(saved.command?.operation) || !saved.command.data || typeof saved.command.data !== 'object') {
                throw new Error('Invalid publication journal.');
            }
            this.command = saved.command; this.operation = saved.command.operation;
            this.draft = copy(saved.command.data); this.original = saved.original || null; this.pending = true;
        });
    }

    mode(operation) {
        if (this.busy || this.pending || !this.authorized() || !operations.includes(operation)) return;
        this.operation = operation; this.draft = {}; this.command = this.original = this.published = null;
        this.error = null; this.reRender();
    }

    lookup(form) {
        if (this.command || this.pending || !form.reportValidity()) return;
        return this.run(async () => {
            const query = {kind: this.operation === 'publishModel' ? 'model' : 'agreement', id: form.elements.originalId.value.trim()};
            if (query.kind === 'agreement') query.tenantId = form.elements.tenantId.value.trim();
            const original = await Espo.Ajax.getRequest('CreditConfiguration', query);
            if (!this.authorized()) return;
            if (original?.id !== query.id || original.kind !== query.kind || !original.data ||
                (query.kind === 'agreement' && original.data.tenantId !== query.tenantId)) throw new Error('Wrong original terms.');
            this.original = copy(original); this.draft = copy(original.data);
            if (query.kind === 'agreement') {
                this.draft.version = ''; this.draft.effectiveFrom = ''; this.draft.effectiveUntil = null;
                if (this.operation === 'replaceAgreement') this.draft.replacesId = original.id;
            }
            this.published = null;
        });
    }

    review(form) {
        if (this.busy || this.pending || !this.authorized() || !form.reportValidity()) return;
        const fields = this.operation === 'publishModel' ? modelFields : agreementFields;
        const data = Object.fromEntries(fields.map(key => {
            let value = form.elements.namedItem(key).value.trim();
            if (integers.includes(key)) value = Number(value);
            else if (dates.includes(key) && value) { value = value.replace('T', ' '); if (value.length === 16) value += ':00'; }
            else if (key === 'aiNativeSearchModelCreditRateId' || key === 'effectiveUntil') value = value || null;
            return [key, value];
        }));
        if (this.operation === 'replaceAgreement') {
            if (!this.original || data.tenantId !== this.original.data.tenantId) { this.error = this.t('loadOriginal'); this.reRender(); return; }
            data.replacesId = this.original.id;
        }
        this.draft = copy(data); this.command = {operation: this.operation, data};
        this.published = null; this.error = null; this.reRender();
    }

    publish() {
        if (!this.command) return;
        return this.run(async () => {
            const command = copy(this.command);
            const raw = JSON.stringify({command, original: this.original || null});
            const existing = window.sessionStorage.getItem(this.key);
            if (existing && existing !== raw) throw new Error('Another publication is pending.');
            window.sessionStorage.setItem(this.key, raw);
            if (window.sessionStorage.getItem(this.key) !== raw) throw new Error('Retry storage unavailable.');
            const wasPending = this.pending; this.pending = true;
            let receipt;
            try { receipt = await Espo.Ajax.postRequest('CreditConfiguration', copy(command)); }
            catch (e) {
                if (!wasPending && [400, 409].includes(e?.status) && window.sessionStorage.getItem(this.key) === raw) {
                    window.sessionStorage.removeItem(this.key); this.pending = false;
                }
                throw e;
            }
            if (typeof receipt?.id !== 'string' || !receipt.id || (command.operation === 'publishModel' ?
                typeof receipt.policyId !== 'string' || receipt.policy?.modelRateId !== receipt.policyId ||
                    modelFields.filter(key => key !== 'multiplier').some(key => receipt.policy?.[key] !== command.data[key]) :
                receipt.version !== command.data.version)) throw new Error('Uncertain publication receipt.');
            if (window.sessionStorage.getItem(this.key) === raw) window.sessionStorage.removeItem(this.key);
            if (!this.authorized()) return;
            this.published = receipt; this.pending = false; this.command = null; this.draft = {}; this.original = null;
        });
    }

    data() {
        const draft = this.command?.data || this.draft;
        const fields = this.operation === 'publishModel' ? modelFields : agreementFields;
        return {...super.data(), allowed: this.allowed, busy: this.busy, error: this.error,
            command: this.command, pending: this.pending, published: this.published,
            model: this.operation === 'publishModel', replacement: this.operation === 'replaceAgreement',
            canEdit: this.command && !this.pending, operationLabel: this.t(this.operation),
            modes: operations.map(value => ({value, label: this.t(value)})),
            original: this.original ? {id: this.original.id, fields: Object.entries(this.original.data).map(([key, value]) =>
                ({label: this.t(key), value: key === 'monthlyCredits' ? formatCredits(value) : value ?? '—'}))} : null,
            fields: [...fields, ...(this.command?.data.replacesId ? ['replacesId'] : [])].map(key => ({key,
                label: this.t(key), value: dates.includes(key) ? draft[key]?.replace(' ', 'T') : draft[key] ?? '',
                display: key === 'monthlyCredits' ? formatCredits(draft[key]) : draft[key] ?? '—',
                required: !['effectiveUntil', 'aiNativeSearchModelCreditRateId'].includes(key),
                type: dates.includes(key) ? 'datetime-local' : integers.includes(key) ? 'number' : 'text',
                step: dates.includes(key) ? '1' : integers.includes(key) ? '1' : undefined,
                min: integers.includes(key) ? '1' : undefined,
                max: key === 'aiMaxRequests' ? '100' : integers.includes(key) ? '9007199254740991' : undefined,
                pattern: key === 'monthlyCredits' ? '[0-9]+(\\.[0-9]{1,4})?' : key === 'creditUnitPrice' ? '[0-9]+(\\.[0-9]{1,8})?' :
                    key === 'multiplier' ? '[0-9]+(\\.[0-9]{1,10})?' : key === 'currency' ? '[A-Z]{3}' : undefined,
            })),
        };
    }
}
