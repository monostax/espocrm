const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');
const handlebars = require('handlebars');
const root = path.join(__dirname, '../..');
const client = 'client/custom/modules/feature-credits/';
const read = file => readFileSync(path.join(root, file), 'utf8');
const en = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/i18n/en_US/CreditCheckout.json')).labels;
const pt = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/i18n/pt_BR/CreditCheckout.json')).labels;
function load(file, imports = {}, globals = {}) {
    const exports = {};
    const {outputText} = ts.transpileModule(read(file), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, esModuleInterop: true}});
    vm.runInNewContext(outputText, {exports, require: key => imports[key], URLSearchParams, ...globals}, {filename: file});
    return exports;
}
const helper = load(client + 'src/helpers/checkout.js');
const presentation = load(client + 'src/helpers/presentation.js');
const plain = value => JSON.parse(JSON.stringify(value));
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return {promise, resolve, reject}; };
const tick = () => new Promise(resolve => setImmediate(resolve));
const storage = () => { const values = new Map(); return {values, getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key)}; };
const terms = {offerId: 'monthly', version: 'v1', currency: 'BRL', price: '997.00', credits: '2000.0000', operation: 'subscription', termsId: 'original-terms'};
const context = tenantId => ({tenantId, revision: 1, billingCountry: 'BR', catalogId: 'catalog', earliestDueDate: '2026-10-13', checkouts: [], offers: [{terms, eligibility: {eligible: true}}]});
const detail = (tenantId = 'a', obligationId = 'obligation') => ({tenantId, obligationId, state: 'accepted', acceptance: {tenantId, obligationId, terms}, customer: null, charge: null, earliestDueDate: '2026-10-13'});
function page({getRequest = async (endpoint, {tenantId, obligationId} = {}) => endpoint === 'Credits/context' ? {tenants: [{id: 'a'}, {id: 'b'}]} : endpoint === 'CreditCheckout/context' ? context(tenantId) : detail(tenantId, obligationId), postRequest = async () => {}, session = storage()} = {}) {
    let key = 0;
    const Page = load(client + 'src/views/checkout.js', {'views/main': class {}, 'feature-credits:helpers/checkout': helper, 'feature-credits:helpers/presentation': presentation},
        {Espo: {Ajax: {getRequest, postRequest}}, window: {sessionStorage: session}, crypto: {randomUUID: () => 'key-' + (++key)}}).default;
    const p = new Page();
    Object.assign(p, {version: 0, tenantId: 'a', obligationId: '', tenants: [{id: 'a'}, {id: 'b'}], context: context('a'),
         reRender: async () => {}, translate: key => en[key] || key, getUser: () => ({id: 'user', isAdmin: () => false}), getRouter: () => ({navigate: () => {}})});
    return {p, session};
}
const acceptCommand = () => ({action: 'accept', body: {tenantId: 'a', requestKey: 'original', revision: 1, catalogId: 'catalog', offerId: 'monthly', termsId: 'original-terms'}});

const legacyOverride = (p, value) => {
    p.pending = {action: 'override', body: {tenantId: 'a', requestKey: 'checkout-key-1', revision: 1, providerOverride: value}};
    p.journal().save(p.pending);
};
const admin = p => { p.getUser = () => ({id: 'user', isAdmin: () => true}); return p; };

test('customer checkout has no provider controls and tenant admins cannot retry legacy operator commands', async () => {
    const {p} = page({postRequest: async () => assert.fail('Must not send override')});
    assert.equal(p.override, undefined);
    assert.equal(p.data().providerOptions, undefined);
    p.pending = {action: 'override', body: {tenantId: 'a', requestKey: 'saved', revision: 1, providerOverride: null}};
    p.getUser = () => ({id: 'user', isAdmin: () => false});
    assert.equal(p.data().canRetry, false); await p.retry();
});

test('override lost reply survives reload with original null value, revision and key; duplicate clicks coalesce', async () => {
    const sent = []; const session = storage(); const lost = deferred();
    const first = admin(page({session, postRequest: async (url, body) => { sent.push([url, plain(body)]); return lost.promise; }}).p);
    legacyOverride(first, null);
    const request = first.retry(); await first.retry(); await tick();
    assert.equal(sent.length, 1); lost.reject({status: 503}); await request;
    assert.deepEqual(sent[0], ['CreditBilling/override', {tenantId: 'a', requestKey: 'checkout-key-1', revision: 1, providerOverride: null}]);
    const second = admin(page({session, postRequest: async (url, body) => { sent.push([url, plain(body)]); return {after: {tenantId: 'a'}}; }}).p);
    await second.load(); assert.equal(sent.length, 1);
    second.context.revision = 99; await second.retry();
    assert.deepEqual(sent[1], sent[0]); assert.equal(session.values.size, 0);
    const journal = new helper.CheckoutJournal(session, 'user', 'a');
    for (const value of ['unknown', undefined, false]) assert.throws(() => journal.save({action: 'override', body: {...sent[0][1], providerOverride: value}}));
});

test('override stale revisions require fresh consent and late success cannot replace another tenant', async () => {
    const rejected = admin(page({postRequest: async () => { throw {status: 409}; }}).p);
    legacyOverride(rejected, 'asaas'); await rejected.retry();
    assert.equal(rejected.pending, null); assert.equal(rejected.error, en.conflict);
    const late = deferred(); const {p, session} = page({postRequest: () => late.promise}); admin(p);
    legacyOverride(p, 'asaas'); const request = p.retry(); await tick();
    const other = new helper.CheckoutJournal(session, 'user', 'b');
    other.save({action: 'override', body: {tenantId: 'b', requestKey: 'other', revision: 1, providerOverride: 'stripe'}});
    await p.changeTenant('b'); late.resolve({after: {tenantId: 'a'}}); await request;
    assert.equal(p.context.tenantId, 'b'); assert.equal(other.read().body.requestKey, 'other');
});

test('customer checkout omits routing identities and controls, even with a historical response and instance admin', () => {
    for (const labels of [en, pt]) {
        handlebars.registerHelper('translate', key => labels[key] || key);
        const {p} = page(); p.translate = key => labels[key] || key;
        p.context = {...context('a'), providerOverride: null, requestedProvider: 'stripe', effectiveProvider: 'asaas', pinned: true, routeMismatch: true, openObligations: 2};
        const render = () => handlebars.compile(read(client + 'res/templates/checkout.tpl'))(p.data());
        assert.ok(!render().includes('data-checkout-override'));
        admin(p); const html = render();
        assert.doesNotMatch(html, /\b(?:asaas|stripe|apollo|cpf\.cnpj)\b/i);
        assert.ok(!html.includes(labels.effectiveProvider));
        assert.ok(!html.includes('data-checkout-override'));
    }
});

test('subscription stop retains one command after lost replies and isolates late tenant responses', async () => {
    const calls = []; const pending = deferred();
    const {p} = page({postRequest: async (url, body) => { calls.push([url, plain(body)]); if (calls.length === 1) throw {status: 503}; return pending.promise; }});
    p.obligationId = 'sub-original';
    p.detail = {...detail('a', 'sub-original'), state: 'active', charge: {kind: 'subscription', state: 'bound'}};
    assert.equal(p.data().canManageSubscription, true);
    await p.subscription('cancel');
    const second = p.subscription('cancel'); await tick();
    assert.deepEqual(calls[0], calls[1]);
    assert.equal(calls[0][0], 'CreditSubscriptions/cancel');
    await p.changeTenant('b');
    pending.resolve({tenantId: 'a', obligationId: 'sub-original', state: 'cancelled'}); await second;
    assert.equal(p.tenantId, 'b'); assert.equal(p.detail, null);
});

test('pending subscription cancellation offers lookup recovery without another consent action', async () => {
    const {p} = page();
    p.detail = {...detail(), state: 'pending', charge: {kind: 'subscription', state: 'bound'}, subscription: {cancellationPending: true, status: 'INACTIVE'}};
    const data = p.data();
    assert.equal(data.cancellationPending, true);
    assert.equal(data.subscriptionStatus, en.subscription_INACTIVE);
    p.detail.state = 'cancelled'; assert.equal(p.data().canManageSubscription, false);
});

test('journal scopes commands to user and tenant and rejects payer data and replacing unresolved identity', () => {
    const session = storage(); const journal = new helper.CheckoutJournal(session, 'user', 'a');
    journal.save(acceptCommand());
    assert.deepEqual(plain(journal.read()), acceptCommand());
    assert.equal(new helper.CheckoutJournal(session, 'other-user', 'a').read(), null);
    assert.equal(new helper.CheckoutJournal(session, 'user', 'b').read(), null);
    assert.throws(() => journal.save({...acceptCommand(), body: {...acceptCommand().body, requestKey: 'replacement'}}));
    assert.throws(() => journal.save({action: 'customer', body: {tenantId: 'a', payer: {cpfCnpj: 'secret'}}}));
    assert.throws(() => journal.save({...acceptCommand(), body: {...acceptCommand().body, payer: 'secret'}}));
    journal.clear(); assert.equal(journal.read(), null);
    const newer = {...acceptCommand(), body: {...acceptCommand().body, requestKey: 'newer'}};
    journal.save(newer); journal.clear(acceptCommand());
    assert.equal(journal.read().body.requestKey, 'newer', 'A late old receipt cannot erase a newer checkout command.');
});

test('lost acceptance reply retries exact original key and terms after reload, never automatically on load', async () => {
    const sent = []; const session = storage();
    const first = page({session, postRequest: async (endpoint, body) => { sent.push({endpoint, body: plain(body)}); throw new Error('lost response'); }}).p;
    await first.selectOffer('monthly'); await first.accept();
    assert.equal(sent.length, 1); assert.equal(session.values.size, 1);
    const second = page({session, postRequest: async (endpoint, body) => { sent.push({endpoint, body: plain(body)}); return {tenantId: 'a', obligationId: 'obligation'}; }}).p;
    await second.load(); assert.equal(sent.length, 1);
    await second.retry();
    assert.deepEqual(sent[0], sent[1]); assert.equal(session.values.size, 0);
    assert.equal(second.detail.obligationId, 'obligation');
});

test('committed acceptance is discovered by original request key without a second POST', async () => {
    const session = storage(); new helper.CheckoutJournal(session, 'user', 'a').save(acceptCommand());
    const {p} = page({session, getRequest: async (endpoint, {tenantId, obligationId}) => endpoint === 'CreditCheckout/context' ?
        {...context(tenantId), checkouts: [{state: 'accepted', acceptance: {requestKey: 'original', obligationId: 'original-obligation', terms}}]} : detail(tenantId, obligationId),
    postRequest: async () => assert.fail('Refresh must not mutate')});
    await p.load();
    assert.equal(p.obligationId, 'original-obligation'); assert.equal(p.pending, null); assert.equal(session.values.size, 0);
});

test('double submit produces one acceptance and blocked storage produces no POST', async () => {
    const response = deferred(); let calls = 0;
    const {p} = page({postRequest: async () => { ++calls; return response.promise; }});
    await p.selectOffer('monthly');
    const first = p.accept(); const second = p.accept(); await tick();
    assert.equal(calls, 1); response.resolve({tenantId: 'a', obligationId: 'obligation'}); await Promise.all([first, second]);
    const blocked = page({session: {getItem: () => null, setItem: () => { throw new Error('storage denied'); }}, postRequest: async () => assert.fail('No persisted identity')}).p;
    await blocked.selectOffer('monthly'); await blocked.accept(); assert.ok(blocked.error);
});

test('tenant switch during mutation cannot leak a late receipt or clear another tenant journal', async () => {
    const response = deferred(); const session = storage();
    const other = new helper.CheckoutJournal(session, 'user', 'b');
    other.save({action: 'country', body: {tenantId: 'b', requestKey: 'other-command', revision: 0, billingCountry: 'US'}});
    const {p} = page({session, postRequest: async () => response.promise});
    await p.selectOffer('monthly'); const pending = p.accept(); await tick();
    await p.changeTenant('b');
    response.resolve({tenantId: 'a', obligationId: 'old-obligation'}); await pending;
    assert.equal(p.tenantId, 'b'); assert.equal(p.obligationId, ''); assert.equal(p.detail, null);
    assert.equal(other.read().body.requestKey, 'other-command');
});

test('late context and payment handoff responses are discarded on tenant switch', async () => {
    const late = deferred();
    const {p} = page({getRequest: async (endpoint, {tenantId}) => tenantId === 'a' ? late.promise : context(tenantId)});
    const first = p.load(); await tick(); await p.changeTenant('b'); late.resolve(context('a')); await first;
    assert.equal(p.context.tenantId, 'b');
    const handoff = deferred(); const other = page({postRequest: () => handoff.promise}).p;
    other.obligationId = 'obligation'; other.detail = {...detail(), state: 'pending', charge: {state: 'bound'}};
    const request = other.payment(); await tick(); await other.changeTenant('b');
    handoff.resolve({tenantId: 'a', obligationId: 'obligation', state: 'pending', environment: 'sandbox', invoiceUrl: 'https://sandbox.asaas.com/i/pay'}); await request;
    assert.equal(other.handoff, null);
});

test('payer stays out of browser storage and errors clear the form without exposing provider text', async () => {
    const sent = []; const {p, session} = page({postRequest: async (endpoint, body) => { sent.push({endpoint, body: plain(body)}); throw {status: 400, message: 'sensitive-provider-body'}; }});
    p.obligationId = 'obligation'; p.detail = detail();
    const value = value => ({value});
    await p.customer({reportValidity: () => true, elements: {payerName: value(' Authorized Payer '), cpfCnpj: value('529.982.247-25'), email: value('payer@example.test'), notifications: {checked: false}}});
    assert.equal(sent[0].body.payer.cpfCnpj, '52998224725'); assert.equal(sent[0].body.payer.notificationDisabled, true);
    assert.equal(session.values.size, 0); assert.equal(p.error, en.invalidInput);
    assert.ok(!JSON.stringify(p.data()).includes('52998224725'));
});

test('charge is journaled before sending and unknown creations use lookup-only recovery', async () => {
    const sent = []; const {p, session} = page({postRequest: async (endpoint, body) => { sent.push({endpoint, body: plain(body)}); throw new Error('lost'); }});
    p.detail = {...detail(), customer: {state: 'bound', creationId: 'customer'}}; p.obligationId = 'obligation';
    await p.charge({reportValidity: () => true, elements: {billingType: {value: 'BOLETO'}, dueDate: {value: '2026-10-13'}}});
    assert.equal(new helper.CheckoutJournal(session, 'user', 'a').read().body.dueDate, '2026-10-13');
    p.detail = {...detail(), state: 'unknown', customer: {state: 'bound', creationId: 'customer'}, charge: {state: 'unknown', creationId: 'charge'}};
    await p.recover();
    assert.equal(sent[1].endpoint, 'CreditBilling/recoverCreation'); assert.deepEqual(sent[1].body, {tenantId: 'a', creationId: 'charge'});
});

test('stale terms require review again and authorization loss clears sensitive checkout state', async () => {
    const {p, session} = page({postRequest: async () => { throw {status: 409}; }});
    await p.selectOffer('monthly'); await p.accept();
    assert.equal(p.selectedOffer, null); assert.equal(session.values.size, 0); assert.equal(p.error, en.conflict);
    const denied = page({getRequest: async () => { throw {status: 403}; }}).p;
    denied.detail = detail(); await denied.load(); assert.equal(denied.detail, null); assert.equal(denied.context, null);
    const explicit = page({getRequest: async () => ({tenants: [{id: 'b'}]})}).p;
    await explicit.bootstrap(); assert.equal(explicit.tenantId, ''); assert.equal(explicit.error, en.forbidden);
});

test('invoice link validation excludes foreign tenant, paid state, hostile URLs and environment crossover', () => {
    const payload = {tenantId: 'a', obligationId: 'obligation', state: 'pending', environment: 'sandbox', invoiceUrl: 'https://sandbox.asaas.com/i/pay_123'};
    assert.equal(helper.paymentLink(payload, 'a', 'obligation'), payload.invoiceUrl);
    for (const change of [{tenantId: 'b'}, {obligationId: 'other'}, {state: 'paid'}, {environment: 'production'},
        {invoiceUrl: 'javascript:alert(1)'}, {invoiceUrl: 'https://sandbox.asaas.com.evil/i/p'}, {invoiceUrl: payload.invoiceUrl + '?next=evil'}, {invoiceUrl: payload.invoiceUrl + '\n'}]) {
        assert.equal(helper.paymentLink({...payload, ...change}, 'a', 'obligation'), null);
    }
});

test('checkout template escapes provider/catalog content and includes bilingual exact-price terms and accessible forms', () => {
    assert.deepEqual(Object.keys(en).sort(), Object.keys(pt).sort());
    const template = read(client + 'res/templates/checkout.tpl');
    for (const match of template.matchAll(/translate '([^']+)'/g)) assert.ok(en[match[1]] && pt[match[1]], match[1]);
    for (const labels of [en, pt]) {
        handlebars.registerHelper('translate', key => labels[key] || key);
        const {p} = page(); p.selectedOffer = {terms: {...terms, offerId: '<script>unsafe</script>'}};
        p.detail = detail(); p.obligationId = 'obligation';
        const html = handlebars.compile(template)(p.data());
        assert.ok(html.includes('BRL 997.00')); assert.ok(html.includes('2000.00'));
        assert.ok(html.includes('&lt;script&gt;')); assert.ok(!html.includes('<script>unsafe'));
        assert.ok(html.includes('name="acceptTerms" required')); assert.ok(html.includes('autocomplete="off"'));
        assert.ok(!html.includes('undefined')); assert.ok(html.includes('scope="col"'));
    }
});
