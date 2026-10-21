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
        if (saved.stage !== 'quote' && (!receipt || receipt.id !== saved.quoteId || !['admitted', 'settled', 'released'].includes(receipt.admission))) {
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

const foldEmailName = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const genericEmailNames = new Set(('admin administracao atendimento billing cobranca comercial compras ' +
    'contabilidade contabil contato contatos contact customer dominios domains ' +
    'finance financeiro financial fiscal help hello info invoice invoices ' +
    'juridico marketing newsletter noreply office recepcao ' +
    'recrutamento recursos humanos revista rh sac sales secretaria ' +
    'suporte support vendas webmaster').split(' '));

// Keep the original value and purchase reference for application.
export function groupEnrichmentEmails(rows, contactName = '') {
    const names = foldEmailName(contactName).split(/[^a-z]+/).filter(name => name.length > 2 && !['das', 'dos'].includes(name));
    const aliases = names.flatMap(name => [`${names[0]}${name}`, `${names[0]?.[0]}${name}`]);
    const unique = new Map();
    rows.forEach(row => {
        const key = row.value.trim().toLowerCase();
        if (!key) return;
        const existing = unique.get(key);
        const sources = [...new Set([...(existing?.sources || []), row.source])]
            .filter(source => source !== undefined && source !== null).sort((a, b) => a - b);
        const preferred = existing && (existing.canEdit || !row.canEdit) ? existing : row;
        unique.set(key, {...preferred, key, sources});
    });
    const sorted = [...unique.values()].map(row => {
        const local = foldEmailName(row.key.split('@')[0].split('+')[0]);
        const tokens = local.split(/[^a-z]+/).filter(Boolean);
        const matchesName = tokens.some(token => names.includes(token)) || aliases.includes(tokens.join(''));
        return {...row, matchesName, generic: !matchesName && (tokens.some(token => genericEmailNames.has(token)) || genericEmailNames.has(tokens.join('')))};
    }).sort((a, b) => Number(b.matchesName) - Number(a.matchesName));
    return {personal: sorted.filter(row => !row.generic), generic: sorted.filter(row => row.generic)};
}
