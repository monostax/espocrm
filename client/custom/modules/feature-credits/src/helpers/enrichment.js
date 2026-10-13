// Only retry identities/settings are stored. Purchased values stay in the current view.
export class EnrichmentSession {
    constructor({post, storage, actorId, tenantId, targetType, targetId}) {
        if (!actorId || !tenantId || !targetId || !['Contact', 'Account'].includes(targetType)) throw new Error('Enrichment context required.');
        this.post = post;
        this.storage = storage;
        this.scope = {tenantId, targetType, targetId};
        this.key = 'credit-enrichment-v1:' + [actorId, tenantId, targetType, targetId].map(encodeURIComponent).join(':');
    }

    read() {
        const raw = this.storage.getItem(this.key);
        if (!raw) return null;
        const saved = JSON.parse(raw);
        if (!['quote', 'confirm', 'admit'].includes(saved.stage) ||
            Object.entries(this.scope).some(([key, value]) => saved.request?.[key] !== value) ||
            typeof saved.request?.requestKey !== 'string' ||
            (saved.stage !== 'quote' && typeof saved.quoteId !== 'string')) throw new Error('Invalid saved enrichment request.');
        return saved;
    }

    save(saved) {
        const raw = JSON.stringify(saved);
        this.storage.setItem(this.key, raw);
        if (this.storage.getItem(this.key) !== raw) throw new Error('Enrichment request could not be saved.');
    }

    async quote(settings) {
        const saved = this.read();
        if (saved) throw new Error('Resolve the previous request first.');
        this.save({stage: 'quote', request: {...settings, ...this.scope, requestKey: crypto.randomUUID()}});
        return this.resume();
    }

    async resume() {
        let saved = this.read();
        if (!saved) return null;
        // Replaying the original quote is read-only after its first creation, including progress.
        let receipt = await this.post('CreditEnrichmentQuotes', saved.request);
        if (Object.entries(this.scope).some(([key, value]) => receipt[key] !== value) ||
            (saved.quoteId && receipt.id !== saved.quoteId)) throw new Error('Enrichment receipt scope differs.');
        saved = {...saved, quoteId: receipt.id};
        this.save(saved);
        if (saved.stage === 'confirm') {
            try {
                receipt = await this.post('CreditEnrichmentQuotes/confirm', {tenantId: this.scope.tenantId, quoteId: saved.quoteId});
            } catch (e) {
                if ([400, 409].includes(e?.status)) this.save({...saved, stage: 'quote'});
                throw e;
            }
            saved = {...saved, stage: 'admit'};
            this.save(saved);
        }
        if (saved.stage === 'admit') {
            receipt = await this.post('CreditEnrichmentQuotes/admit', {tenantId: this.scope.tenantId, quoteId: saved.quoteId});
        }
        return receipt;
    }

    async confirm() {
        const saved = this.read();
        if (!saved?.quoteId) throw new Error('Review a quote first.');
        if (saved.stage === 'quote') this.save({...saved, stage: 'confirm'});
        return this.resume();
    }

    reset(receipt) {
        const saved = this.read();
        if (!saved) return;
        if (saved.stage !== 'quote' && (!receipt || receipt.id !== saved.quoteId || !['settled', 'released'].includes(receipt.admission))) {
            throw new Error('The original operation is unresolved.');
        }
        this.storage.removeItem(this.key);
    }
}

export function fieldChoices(review) {
    const product = review.receipt.product;
    const values = review.values || {};
    const choices = product === 'email' || product === 'phone'
        ? {[product === 'email' ? 'emailAddress' : 'phoneNumber']: Object.values(values)}
        : Object.fromEntries(Object.entries(values).filter(([, value]) => typeof value === 'string').map(([key, value]) => [key, [value]]));
    const editable = review.application?.permissions?.editableFields || [];
    return Object.entries(choices).filter(([field]) => editable.includes(field)).map(([field, options]) => ({field, options}));
}
