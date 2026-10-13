// Persist command identities, never payer details or payment links. Storage is scoped to user + tenant.
export class CheckoutJournal {
    constructor(storage, userId, tenantId) {
        if (!userId || !tenantId) throw new Error('Checkout identity required.');
        this.storage = storage;
        this.tenantId = tenantId;
        this.key = 'credit-checkout-v1:' + encodeURIComponent(userId) + ':' + encodeURIComponent(tenantId);
    }

    read() {
        const raw = this.storage.getItem(this.key);
        if (!raw) return null;
        const value = JSON.parse(raw);
        this.validate(value);
        return value;
    }

    validate(value) {
        const fields = {
            country: ['tenantId', 'requestKey', 'revision', 'billingCountry'],
            override: ['tenantId', 'requestKey', 'revision', 'providerOverride'],
            accept: ['tenantId', 'requestKey', 'revision', 'catalogId', 'offerId', 'termsId'],
            charge: ['tenantId', 'obligationId', 'customerCreationId', 'billingType', 'dueDate'],
            cancel: ['tenantId', 'requestKey', 'obligationId'],
        };
        const keys = fields[value?.action];
        if (!keys || Object.keys(value).sort().join() !== 'action,body' || !value.body ||
            Object.keys(value.body).sort().join() !== [...keys].sort().join() || value.body.tenantId !== this.tenantId ||
            keys.some(key => key === 'revision' ? !Number.isSafeInteger(value.body[key]) || value.body[key] < 0 :
                key === 'providerOverride' ? ![null, 'asaas', 'stripe'].includes(value.body[key]) : typeof value.body[key] !== 'string')) {
            throw new Error('Invalid checkout command.');
        }
    }

    save(value) {
        this.validate(value);
        const current = this.read();
        if (current && JSON.stringify(current) !== JSON.stringify(value)) throw new Error('Resolve the original checkout command first.');
        const raw = JSON.stringify(value);
        this.storage.setItem(this.key, raw);
        if (this.storage.getItem(this.key) !== raw) throw new Error('Checkout command could not be saved.');
    }

    clear(expected = null) {
        if (expected && JSON.stringify(this.read()) !== JSON.stringify(expected)) return;
        this.storage.removeItem(this.key);
    }
}

export function paymentLink(payload, tenantId, obligationId) {
    if (payload?.tenantId !== tenantId || payload?.obligationId !== obligationId || payload?.state !== 'pending') return null;
    const host = {sandbox: 'sandbox.asaas.com', production: 'www.asaas.com'}[payload.environment];
    if (!host || typeof payload.invoiceUrl !== 'string' || payload.invoiceUrl !== payload.invoiceUrl.trim()) return null;
    return new RegExp('^https://' + host.replaceAll('.', '\\.') + '/i/[a-zA-Z0-9_-]{1,160}$').test(payload.invoiceUrl) ? payload.invoiceUrl : null;
}
