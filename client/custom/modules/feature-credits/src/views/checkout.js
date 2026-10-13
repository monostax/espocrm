import MainView from 'views/main';
import {CheckoutJournal, paymentLink} from 'feature-credits:helpers/checkout';
import {formatCredits} from 'feature-credits:helpers/presentation';

export default class extends MainView {
    template = 'feature-credits:checkout';
    scope = 'Credits';
    events = {
        'change [data-checkout-tenant]': function (e) { this.changeTenant(e.currentTarget.value); },
        'click [data-checkout-refresh]': function () { if (!this.busy) this.load(); },
        'submit [data-checkout-country]': function (e) { e.preventDefault(); this.country(e.currentTarget); },
        'submit [data-checkout-override]': function (e) { e.preventDefault(); this.override(e.currentTarget); },
        'click [data-checkout-offer]': function (e) { this.selectOffer(e.currentTarget.dataset.checkoutOffer); },
        'submit [data-checkout-accept]': function (e) { e.preventDefault(); if (e.currentTarget.elements.acceptTerms.checked) this.accept(); },
        'click [data-checkout-open]': function (e) { this.open(e.currentTarget.dataset.checkoutOpen); },
        'submit [data-checkout-payer]': function (e) { e.preventDefault(); this.customer(e.currentTarget); },
        'submit [data-checkout-charge]': function (e) { e.preventDefault(); this.charge(e.currentTarget); },
        'click [data-checkout-recover]': function () { this.recover(); },
        'click [data-checkout-retry]': function () { this.retry(); },
        'click [data-checkout-cancel]': function () { this.cancel(); },
        'click [data-checkout-payment]': function () { this.payment(); },
        'click [data-subscription-reconcile]': function () { this.subscription('reconcile'); },
        'submit [data-subscription-cancel]': function (e) { e.preventDefault(); if (e.currentTarget.elements.stopSubscription.checked) this.subscription('cancel'); },
    };

    setup() {
        super.setup();
        this.tenantId = this.options.params?.tenantId || '';
        this.obligationId = this.options.params?.obligationId || '';
        this.version = 0;
        this.on('remove', () => { this.disposed = true; ++this.version; this.detail = this.handoff = null; });
        this.wait(this.bootstrap());
    }

    t(key) { return this.translate(key, 'labels', 'CreditCheckout'); }
    active(version) { return !this.disposed && this.version === version; }

    async bootstrap() {
        const version = ++this.version;
        this.busy = true;
        try {
            const context = await Espo.Ajax.getRequest('Credits/context');
            if (!this.active(version)) return;
            this.tenants = context.tenants;
            if (!this.tenantId) this.tenantId = context.tenants[0]?.id || '';
            if (!context.tenants.some(t => t.id === this.tenantId)) {
                this.tenantId = ''; this.error = this.t('forbidden'); return;
            }
            await this.load(false);
        } catch (e) { e?.setHandled?.(); if (this.active(version)) this.error = this.message(e); }
        finally { if (this.active(version)) this.busy = false; }
    }

    message(e) {
        return this.t(e?.status === 401 ? 'sessionExpired' : e?.status === 403 ? 'forbidden' : e?.status === 409 ? 'conflict' : e?.status === 400 ? 'invalidInput' : 'unresolved');
    }

    journal() { return new CheckoutJournal(window.sessionStorage, this.getUser().id, this.tenantId); }

    async load(render = true) {
        const version = ++this.version;
        this.busy = true; this.error = null; this.detail = this.handoff = this.context = this.selectedOffer = null;
        this.pending = null; this.storageError = false;
        if (render) await this.reRender();
        try {
            const tenantId = this.tenantId;
            if (!tenantId || !this.tenants?.some(t => t.id === tenantId)) return;
            const context = await Espo.Ajax.getRequest('CreditCheckout/context', {tenantId});
            if (!this.active(version)) return;
            if (context.tenantId !== tenantId) throw new Error('Mismatched checkout tenant.');
            this.context = context;
            try { this.pending = this.journal().read(); } catch { this.storageError = true; }
            // A committed acceptance can be recovered even if its HTTP reply was lost.
            if (this.pending?.action === 'accept') {
                const found = context.checkouts.find(c => c.acceptance.requestKey === this.pending.body.requestKey);
                if (found) {
                    this.obligationId = found.acceptance.obligationId;
                    this.journal().clear(this.pending); this.pending = null;
                }
            }
            if (!this.obligationId && this.pending?.body.obligationId) this.obligationId = this.pending.body.obligationId;
            if (this.obligationId) {
                const detail = await Espo.Ajax.getRequest('CreditCheckout', {tenantId, obligationId: this.obligationId});
                if (!this.active(version)) return;
                if (detail.tenantId !== tenantId || detail.obligationId !== this.obligationId) throw new Error('Mismatched checkout.');
                this.detail = detail;
                if (this.pending?.action === 'charge' && this.pending.body.obligationId === detail.obligationId && detail.charge) {
                    this.journal().clear(this.pending); this.pending = null;
                }
            }
            this.navigate();
        } catch (e) {
            e?.setHandled?.();
            if (this.active(version)) {
                this.error = this.message(e);
                if ([401, 403].includes(e?.status)) this.context = this.detail = null;
            }
        } finally {
            if (this.active(version)) { this.busy = false; if (render) await this.reRender(); }
        }
    }

    navigate() {
        const query = new URLSearchParams({tenantId: this.tenantId});
        if (this.obligationId) query.set('obligationId', this.obligationId);
        this.getRouter().navigate('Credits/checkout/' + query.toString(), {trigger: false, replace: true});
    }

    changeTenant(id) {
        ++this.version;
        this.tenantId = this.tenants?.some(t => t.id === id) ? id : '';
        this.obligationId = ''; this.detail = this.context = this.handoff = this.selectedOffer = this.pending = null;
        return this.load();
    }

    open(id) {
        if (this.busy || !this.context?.checkouts.some(c => c.acceptance.obligationId === id)) return;
        this.obligationId = id;
        return this.load();
    }

    selectOffer(id) {
        if (this.busy || this.pending || this.storageError) return;
        this.selectedOffer = this.context?.offers.find(o => o.terms.offerId === id && o.eligibility.eligible) || null;
        this.detail = this.handoff = null; this.obligationId = '';
        this.navigate();
        return this.reRender();
    }

    async run(action) {
        if (this.busy || !this.context || !this.tenantId) return;
        const version = this.version;
        this.busy = true; this.error = null; this.handoff = null;
        try {
            await this.reRender();
            if (!this.active(version)) return;
            await action(version);
        } catch (e) {
            e?.setHandled?.();
            if (this.active(version)) {
                this.error = this.message(e);
                if ([401, 403].includes(e?.status)) this.context = this.detail = null;
            }
        } finally {
            if (this.active(version)) { this.busy = false; await this.reRender(); }
        }
    }

    async command(action, body, version) {
        // Capture the journal before awaiting; a tenant switch must never clear another tenant's command.
        const journal = this.journal(); const value = {action, body};
        journal.save(value); this.pending = value;
        let receipt;
        try { receipt = await Espo.Ajax.postRequest('CreditBilling/' + action, body); }
        catch (e) {
            // Explicit validation/conflict responses rejected this command. Network/5xx uncertainty retains its identity.
            if ([400, 409].includes(e?.status)) {
                journal.clear(value);
                if (this.active(version)) { this.pending = null; this.selectedOffer = null; }
            }
            throw e;
        }
        const tenant = receipt.tenantId ?? receipt.after?.tenantId;
        if (tenant !== body.tenantId) throw new Error('Mismatched billing receipt.');
        journal.clear(value);
        if (!this.active(version)) return;
        this.pending = null;
        if (action === 'accept') this.obligationId = receipt.obligationId;
        if (action === 'cancel') this.obligationId = '';
        await this.load();
    }

    key() { return 'checkout-' + crypto.randomUUID(); }

    country(form) {
        if (this.pending || this.storageError || !form.reportValidity()) return;
        const body = {tenantId: this.tenantId, requestKey: this.key(), revision: this.context.revision, billingCountry: form.elements.billingCountry.value};
        return this.run(version => this.command('country', body, version));
    }

    accept() {
        if (!this.selectedOffer?.eligibility.eligible || this.pending || this.storageError) return;
        const {terms} = this.selectedOffer;
        const body = {tenantId: this.tenantId, requestKey: this.key(), revision: this.context.revision,
            catalogId: this.context.catalogId, offerId: terms.offerId, termsId: terms.termsId};
        return this.run(version => this.command('accept', body, version));
    }

    retry() {
        if (!this.pending || this.storageError) return;
        const {action, body} = this.pending;
        if (action === 'override' && !this.getUser().isAdmin()) return;
        return this.run(version => this.command(action, body, version));
    }

    override(form) {
        if (!this.getUser().isAdmin() || !this.context || this.pending || this.storageError ||
            !form.reportValidity() || !form.elements.confirmOverride.checked) return;
        const providerOverride = form.elements.providerOverride.value || null;
        if (![null, 'asaas', 'stripe'].includes(providerOverride)) return;
        const requested = providerOverride || ({BR: 'asaas', US: 'stripe'}[this.context.billingCountry] || null);
        if (this.context.pinned && requested !== this.context.effectiveProvider) {
            this.error = this.t('resolveObligations');
            return this.reRender();
        }
        const body = {tenantId: this.tenantId, requestKey: this.key(), revision: this.context.revision, providerOverride};
        return this.run(version => this.command('override', body, version));
    }

    customer(form) {
        if (this.pending || this.detail?.customer || !['accepted', 'creating'].includes(this.detail?.state) || !form.reportValidity()) return;
        const tenantId = this.tenantId; const obligationId = this.obligationId;
        const payer = {name: form.elements.payerName.value.trim(), cpfCnpj: form.elements.cpfCnpj.value.replace(/\D/g, ''),
            email: form.elements.email.value.trim() || null, notificationDisabled: !form.elements.notifications.checked};
        // Payer data exists only for this request and is not written to browser storage or URLs.
        return this.run(async version => {
            const receipt = await Espo.Ajax.postRequest('CreditBilling/customer', {tenantId, obligationId, payer});
            if (receipt.tenantId !== tenantId) throw new Error('Mismatched billing customer.');
            if (this.active(version)) await this.load();
        });
    }

    charge(form) {
        if (this.pending || this.storageError || this.detail?.charge || this.detail?.customer?.state !== 'bound' || !form.reportValidity()) return;
        const body = {tenantId: this.tenantId, obligationId: this.obligationId, customerCreationId: this.detail.customer.creationId,
            billingType: form.elements.billingType.value, dueDate: form.elements.dueDate.value};
        return this.run(version => this.command('charge', body, version));
    }

    recover() {
        const creation = this.detail?.charge || this.detail?.customer;
        if (!creation || creation.state === 'bound') return;
        const tenantId = this.tenantId;
        return this.run(async version => {
            const receipt = await Espo.Ajax.postRequest('CreditBilling/recoverCreation', {tenantId, creationId: creation.creationId});
            if (receipt.tenantId !== tenantId) throw new Error('Mismatched recovery.');
            if (this.active(version)) await this.load();
        });
    }

    cancel() {
        if (this.pending || this.storageError || this.detail?.state !== 'accepted') return;
        const body = {tenantId: this.tenantId, obligationId: this.obligationId, requestKey: this.key()};
        return this.run(version => this.command('cancel', body, version));
    }

    payment() {
        if (this.detail?.charge?.state !== 'bound') return;
        const tenantId = this.tenantId; const obligationId = this.obligationId;
        return this.run(async version => {
            const handoff = await Espo.Ajax.postRequest('CreditCheckout/payment', {tenantId, obligationId});
            if (!this.active(version)) return;
            if (handoff.tenantId !== tenantId || handoff.obligationId !== obligationId) throw new Error('Mismatched handoff.');
            this.handoff = handoff;
        });
    }

    subscription(action) {
        if (this.detail?.charge?.kind !== 'subscription' || this.detail.charge.state !== 'bound' || !['pending', 'active'].includes(this.detail.state)) return;
        const body = {tenantId: this.tenantId, obligationId: this.obligationId};
        // One permanent stop command per subscription, stable across reloads and lost responses.
        if (action === 'cancel') body.requestKey = 'subscription-stop-' + this.obligationId;
        return this.run(async version => {
            const receipt = await Espo.Ajax.postRequest('CreditSubscriptions/' + action, body);
            if (receipt.tenantId !== body.tenantId || receipt.obligationId !== body.obligationId) throw new Error('Mismatched subscription.');
            if (this.active(version)) await this.load();
        });
    }

    terms(terms) {
        return terms ? {...terms, creditsDisplay: formatCredits(terms.credits), monthly: terms.operation === 'subscription'} : null;
    }

    data() {
        const detail = this.detail;
        const creation = detail?.charge || detail?.customer;
        const continuing = ['accepted', 'creating'].includes(detail?.state);
        return {
            busy: this.busy, error: this.error, storageError: this.storageError, pending: this.pending,
            hasContext: !!this.context, tenantId: this.tenantId,
            tenants: (this.tenants || []).map(t => ({...t, selected: t.id === this.tenantId})),
            countries: ['BR', 'US'].map(id => ({id, selected: id === this.context?.billingCountry})),
            billingCountry: this.context?.billingCountry || '—', hasMoreCheckouts: this.context?.hasMoreCheckouts,
            canOverride: this.getUser().isAdmin(),
            canRetry: this.pending?.action !== 'override' || this.getUser().isAdmin(),
            providerOptions: [null, 'asaas', 'stripe'].map(id => ({id: id || '',
                label: this.t(id ? 'provider_' + id : 'automaticProvider'),
                selected: id === (this.context?.providerOverride ?? null)})),
            requestedProvider: this.t(this.context?.requestedProvider ? 'provider_' + this.context.requestedProvider : 'noProvider'),
            effectiveProvider: this.t(this.context?.effectiveProvider ? 'provider_' + this.context.effectiveProvider : 'noProvider'),
            routePinned: this.context?.pinned, routeMismatch: this.context?.routeMismatch,
            openObligations: this.context?.openObligations,
            offers: (this.context?.offers || []).map(o => ({...this.terms(o.terms), eligible: o.eligibility.eligible && !this.pending && !this.storageError,
                blocked: o.eligibility.eligible ? null : this.t(o.eligibility.blockedReason)})),
            checkouts: (this.context?.checkouts || []).map(c => ({...this.terms(c.acceptance.terms), id: c.acceptance.obligationId, stateLabel: this.t('state_' + c.state)})),
            selectedTerms: this.terms(this.selectedOffer?.terms), acceptedTerms: this.terms(detail?.acceptance.terms),
            stateLabel: detail ? this.t('state_' + detail.state) : null,
            canPayer: continuing && !detail.customer && !this.pending,
            canCharge: continuing && detail.customer?.state === 'bound' && !detail.charge && !this.pending && !this.storageError,
            canRecover: !!creation && creation.state !== 'bound',
            canCancel: detail?.state === 'accepted' && !this.pending && !this.storageError,
            canPayment: detail?.charge?.state === 'bound' && ['pending', 'active', 'completed'].includes(detail.state),
            canManageSubscription: detail?.charge?.kind === 'subscription' && detail.charge.state === 'bound' && ['pending', 'active'].includes(detail.state),
            cancellationPending: detail?.subscription?.cancellationPending,
            subscriptionStatus: detail?.subscription?.providerStatus ? this.t('subscription_' + detail.subscription.providerStatus) : null,
            dueDate: detail?.earliestDueDate || this.context?.earliestDueDate,
            sandbox: creation?.environment === 'sandbox' || this.handoff?.environment === 'sandbox',
            invoiceUrl: paymentLink(this.handoff, this.tenantId, this.obligationId),
            paymentState: this.handoff ? this.t('payment_' + this.handoff.state) : null,
            backUrl: '#Credits/index/' + new URLSearchParams({tenantId: this.tenantId}),
        };
    }
}
