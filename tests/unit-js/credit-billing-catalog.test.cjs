const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const ts = require('typescript');
const handlebars = require('handlebars');
const root = path.join(__dirname, '../..');
const client = 'client/custom/modules/feature-credits/';
const read = file => readFileSync(path.join(root, file), 'utf8');
const clone = value => JSON.parse(JSON.stringify(value));
const command = {version: 'catalog-v2', effectiveFrom: '2027-01-01 00:00:00',
    products: [{productId: 'pack', productVersion: 'v1', name: '<script>bad</script>', operation: 'purchase', credits: '12.3456'}],
    offers: [{offerId: 'pack-br', version: 'v1', productId: 'pack', productVersion: 'v1', countries: ['BR'], currency: 'BRL', price: '10.99', interval: null}]};
function fixture(api = {}, storage = new Map()) {
    let admin = true; let actor = 'admin'; let removed;
    const exports = {};
    const output = ts.transpileModule(read(client + 'src/views/billing-catalog.js'), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}}).outputText;
    const sessionStorage = {getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key)};
    vm.runInNewContext(output, {exports, require: key => key === 'views/main' ? class {
        setup() {} data() {return {};} getUser() { return {id: actor, isAdmin: () => admin}; }
        addHandler() {} on(event, cb) { removed = cb; } wait(p) {this.ready = p;} isRendered() {return true;}
        reRender() {} translate(key) {return key;}
    } : {formatCredits: value => Number(value).toFixed(2)}, window: {sessionStorage}, Espo: {Ajax: {
        getRequest: async () => ({...clone(command), catalogId: 'original', offers: command.offers.map(o => ({...o, schema: 'billing-offer-v1', termsId: 'terms', credits: '12.3456', operation: 'purchase'}))}),
        ...api,
    }}});
    const view = new exports.default(); view.setup();
    return {view, storage, sessionStorage, revoke: () => {admin = false;}, switchActor: () => {actor = 'other';}, remove: () => removed()};
}
test('loaded sale catalog drops receipt-only offer fields and preserves exact terms', async () => {
    const {view} = fixture(); await view.ready;
    assert.deepEqual(clone(view.draft.products), command.products);
    assert.deepEqual(clone(view.draft.offers), command.offers);
    assert.equal(view.draft.version, '');
    assert.equal(view.draft.effectiveFrom, '');
});
test('lost response reload and duplicate clicks preserve one immutable publication command', async () => {
    const storage = new Map(); let calls = 0; let finish;
    const first = fixture({postRequest: async (url, body) => { ++calls; assert.equal(url, 'CreditBillingConfiguration'); assert.deepEqual(clone(body), command); throw new Error('lost'); }}, storage);
    await first.view.ready; first.view.command = clone(command);
    await first.view.publish(); assert.equal(first.view.pending, true);
    const second = fixture({getRequest: () => {throw new Error('must not read current during recovery');},
        postRequest: async (url, body) => { ++calls; assert.deepEqual(clone(body), command); return new Promise(resolve => {finish = resolve;}); }}, storage);
    await second.view.ready; assert.equal(calls, 1);
    const result = second.view.publish(); await second.view.publish(); assert.equal(calls, 2);
    finish({catalog: {...clone(command), catalogId: 'published'}}); await result;
    assert.equal(storage.size, 0); assert.equal(second.view.published.version, command.version);
});
test('storage failure and another unresolved publication block POST', async () => {
    let calls = 0;
    const f = fixture({postRequest: async () => {++calls;}}); await f.view.ready;
    f.view.command = clone(command); f.sessionStorage.setItem = () => {throw new Error('quota');};
    await f.view.publish(); assert.equal(calls, 0);
    f.storage.set(f.view.key, JSON.stringify({command: {...command, version: 'other'}}));
    await f.view.publish(); assert.equal(calls, 0);
});
test('role loss, changed actor and removal block new publication; late success cannot restore the view', async () => {
    for (const change of ['revoke', 'switchActor', 'remove']) {
        let calls = 0; const f = fixture({postRequest: async () => {++calls;}}); await f.view.ready;
        f.view.command = clone(command); f[change](); await f.view.publish(); assert.equal(calls, 0);
    }
    let finish; const f = fixture({postRequest: () => new Promise(resolve => {finish = resolve;})});
    await f.view.ready; f.view.command = clone(command); const result = f.view.publish(); f.revoke();
    finish({catalog: {...command, catalogId: 'published'}}); await result;
    assert.equal(f.view.allowed, false); assert.equal(f.view.published, null);
});
test('definitive first rejection permits editing but uncertainty and mismatched receipts retain recovery', async () => {
    const f = fixture({postRequest: async () => {throw {status: 409};}}); await f.view.ready;
    f.view.command = clone(command); await f.view.publish(); assert.equal(f.view.pending, false); assert.equal(f.storage.size, 0);
    f.view.pending = true; await f.view.publish(); assert.equal(f.view.pending, true); assert.equal(f.storage.size, 1);
    const malformed = fixture({postRequest: async () => ({catalog: {...command, version: 'foreign', catalogId: 'wrong'}})});
    await malformed.view.ready; malformed.view.command = clone(command); await malformed.view.publish();
    assert.equal(malformed.view.pending, true); assert.equal(malformed.storage.size, 1);
});
test('EN/PT review escapes terms, shows two-decimal credits, expiry and complete withdrawal disclosure', async () => {
    const f = fixture(); await f.view.ready; f.view.command = clone(command);
    const en = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/i18n/en_US/CreditBillingCatalog.json')).labels;
    const pt = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/i18n/pt_BR/CreditBillingCatalog.json')).labels;
    assert.deepEqual(Object.keys(en).sort(), Object.keys(pt).sort());
    for (const labels of [en, pt]) {
        handlebars.registerHelper('translate', key => {assert.ok(labels[key], key); return labels[key];});
        const html = handlebars.compile(read(client + 'res/templates/billing-catalog.tpl'))(f.view.data());
        assert.ok(html.includes('&lt;script&gt;')); assert.ok(!html.includes('<script>'));
        assert.ok(html.includes('12.35')); assert.ok(!html.includes('name="product-'));
        assert.ok(html.includes(labels.completeCatalog)); assert.ok(html.includes('role="status"'));
    }
    assert.equal(f.view.command.products[0].credits, '12.3456');
});
