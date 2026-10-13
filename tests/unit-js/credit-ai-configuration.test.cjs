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
const copy = value => JSON.parse(JSON.stringify(value));
const model = {provider: 'google', model: 'gemini-3.8-flash', multiplier: '1.0000000001',
    inputTokenBound: 1048576, outputTokenLimit: 65536, boundProfile: 'approved-profile'};
const agreement = {tenantId: 'tenant', version: 'v2', effectiveFrom: '2027-01-01 00:00:00', effectiveUntil: null,
    currency: 'BRL', creditUnitPrice: '0.50000001', monthlyCredits: '12.3456',
    aiModelCreditRateId: 'main-id', aiNativeSearchModelCreditRateId: null, aiMaxRequests: 100};
const receipt = command => command.operation === 'publishModel' ? {id: 'model-id', policyId: 'hash', policy: {...model, modelRateId: 'hash'}} : {id: 'agreement-id', version: command.data.version};
function fixture(api = {}, storage = new Map()) {
    let admin = true; let actor = 'admin'; let removed;
    const exports = {};
    const output = ts.transpileModule(read(client + 'src/views/ai-configuration.js'), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}}).outputText;
    const sessionStorage = {getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key)};
    vm.runInNewContext(output, {exports, require: key => key === 'views/main' ? class {
        setup() {} data() {return {};} getUser() {return {id: actor, isAdmin: () => admin};}
        addHandler() {} on(event, cb) {removed = cb;} wait(p) {this.ready = p;} isRendered() {return true;}
        reRender() {} translate(key) {return key;}
    } : {formatCredits: value => value == null ? '—' : Number(value).toFixed(2)}, window: {sessionStorage}, Espo: {Ajax: api}});
    const view = new exports.default(); view.setup();
    return {view, storage, sessionStorage, revoke: () => {admin = false;}, switchActor: () => {actor = 'other';}, remove: () => removed()};
}
const form = values => ({reportValidity: () => true, elements: Object.assign(Object.fromEntries(Object.entries(values).map(([key, value]) => [key, {value: value == null ? '' : String(value)}])), {
    namedItem(key) {return this[key];},
})});

test('model and agreement forms preserve exact strings, nullable helper and typed ceilings', async () => {
    const {view} = fixture(); await view.ready;
    view.review(form(model)); assert.deepEqual(copy(view.command), {operation: 'publishModel', data: model});
    view.mode('publishAgreement'); view.review(form({...agreement, effectiveFrom: '2027-01-01T00:00'}));
    assert.deepEqual(copy(view.command), {operation: 'publishAgreement', data: agreement});
});
test('replacement requires original same-tenant lookup and preserves original prices for review', async () => {
    const {view} = fixture({getRequest: async (url, query) => {assert.equal(url, 'CreditConfiguration'); assert.equal(query.tenantId, 'tenant'); return {kind: 'agreement', id: 'old', data: {...agreement, version: 'old'}};}});
    await view.ready; view.mode('replaceAgreement'); view.review(form(agreement)); assert.equal(view.command, null);
    await view.lookup(form({originalId: 'old', tenantId: 'tenant'}));
    assert.equal(view.draft.version, ''); assert.equal(view.original.data.version, 'old');
    view.review(form({...agreement, tenantId: 'foreign'})); assert.equal(view.command, null);
    view.review(form(agreement)); assert.equal(view.command.data.replacesId, 'old');
    assert.equal(view.command.data.creditUnitPrice, '0.50000001');
});
test('lost model and replacement responses recover exact commands without GET or duplicate click', async () => {
    for (const command of [{operation: 'publishModel', data: model}, {operation: 'replaceAgreement', data: {...agreement, replacesId: 'old'}}]) {
        const storage = new Map(); let calls = 0; let finish;
        const first = fixture({postRequest: async (url, body) => {++calls; assert.deepEqual(copy(body), command); throw new Error('lost');}}, storage);
        await first.view.ready; first.view.command = copy(command); await first.view.publish();
        const second = fixture({getRequest: () => {throw new Error('No GET during recovery');}, postRequest: async (url, body) => {
            ++calls; assert.equal(url, 'CreditConfiguration'); assert.deepEqual(copy(body), command); return new Promise(resolve => {finish = resolve;});
        }}, storage);
        await second.view.ready; assert.equal(second.view.pending, true);
        const result = second.view.publish(); await second.view.publish(); assert.equal(calls, 2);
        finish(receipt(command)); await result; assert.equal(storage.size, 0); assert.ok(second.view.published);
    }
});
test('storage failure, conflicting journal, lost role and changed actor cannot submit', async () => {
    let calls = 0;
    for (const change of ['revoke', 'switchActor', 'remove']) {
        const f = fixture({postRequest: async () => {++calls;}}); await f.view.ready;
        f.view.command = {operation: 'publishModel', data: model}; f[change](); await f.view.publish();
    }
    const f = fixture({postRequest: async () => {++calls;}}); await f.view.ready;
    f.view.command = {operation: 'publishModel', data: model};
    f.storage.set(f.view.key, 'another command'); await f.view.publish(); f.storage.clear();
    f.sessionStorage.setItem = () => {throw new Error('quota');}; await f.view.publish(); assert.equal(calls, 0);
});
test('uncertain receipt and earlier lost response cannot clear journal; first rejection allows editing', async () => {
    const f = fixture({postRequest: async () => {throw {status: 409};}}); await f.view.ready;
    f.view.command = {operation: 'publishModel', data: model}; await f.view.publish(); assert.equal(f.storage.size, 0);
    f.view.pending = true; await f.view.publish(); assert.equal(f.storage.size, 1);
    const bad = fixture({postRequest: async () => ({id: 'wrong', policyId: 'hash', policy: {...model, model: 'other', modelRateId: 'hash'}})});
    await bad.view.ready; bad.view.command = {operation: 'publishModel', data: model}; await bad.view.publish(); assert.equal(bad.storage.size, 1);
});
test('late lookup and publication responses do not revive another actor or clear a newer journal', async () => {
    let finish;
    const f = fixture({getRequest: () => new Promise(resolve => {finish = resolve;})}); await f.view.ready;
    const load = f.view.lookup(form({originalId: 'model-id'})); f.switchActor(); finish({kind: 'model', id: 'model-id', data: model}); await load;
    assert.equal(f.view.original, null);
    const p = fixture({postRequest: () => new Promise(resolve => {finish = resolve;})}); await p.view.ready;
    const command = {operation: 'publishModel', data: model}; p.view.command = command; const result = p.view.publish();
    p.storage.set(p.view.key, 'newer'); p.revoke(); finish(receipt(command)); await result;
    assert.equal(p.view.published, null); assert.equal(p.storage.get(p.view.key), 'newer');
});
test('EN/PT review is escaped and discloses native debt, original selection and immutable terms', async () => {
    const f = fixture(); await f.view.ready; f.view.mode('replaceAgreement');
    f.view.command = {operation: 'replaceAgreement', data: {...agreement, replacesId: '<script>old</script>'}};
    const en = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/i18n/en_US/CreditAiConfiguration.json')).labels;
    const pt = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/i18n/pt_BR/CreditAiConfiguration.json')).labels;
    assert.deepEqual(Object.keys(en).sort(), Object.keys(pt).sort());
    for (const labels of [en, pt]) {
        handlebars.registerHelper('translate', key => {assert.ok(labels[key], key); return labels[key];});
        const html = handlebars.compile(read(client + 'res/templates/ai-configuration.tpl'))(f.view.data());
        assert.ok(html.includes('&lt;script&gt;')); assert.ok(!html.includes('<script>'));
        assert.ok(html.includes('12.35')); assert.ok(html.includes('0.50000001'));
        assert.ok(html.includes(labels.nativeWarning)); assert.ok(html.includes(labels.replacementNotice));
        assert.ok(!html.includes('data-config-draft'));
    }
});
