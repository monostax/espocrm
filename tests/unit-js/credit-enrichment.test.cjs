const assert = require('node:assert/strict');
const test = require('node:test');
const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');
const handlebars = require('handlebars');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const client = 'client/custom/modules/feature-credits/';
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
function load(file, imports = {}, globals = {}) {
    const exports = {};
    vm.runInNewContext(ts.transpileModule(read(file), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}}).outputText,
        {exports, require: key => imports[key], crypto: require('node:crypto').webcrypto, ...globals});
    return exports;
}
const helpers = load(client + 'src/helpers/enrichment.js');
const scope = {actorId: 'user', tenantId: 'tenant', targetType: 'Account', targetId: 'account'};
const memory = () => { const data = new Map(); return {getItem: key => data.get(key) ?? null, setItem: (key, value) => data.set(key, value), removeItem: key => data.delete(key)}; };
const receipt = {id: 'quote', tenantId: 'tenant', targetType: 'Account', targetId: 'account', admission: 'not_admitted'};

test('lost admission response resumes the original identity after reload, never a new purchase', async () => {
    const storage = memory(); let lost = true; let admissions = 0; const keys = [];
    const post = async (url, body) => {
        if (url === 'CreditEnrichmentQuotes') keys.push(body.requestKey);
        if (url.endsWith('/admit')) { admissions++; if (lost) { lost = false; throw new Error('lost response'); } return {...receipt, admission: 'settled'}; }
        return receipt;
    };
    const first = new helpers.EnrichmentSession({...scope, post, storage});
    await first.quote({products: ['socios'], count: 10, waterfall: false, refresh: false});
    await assert.rejects(first.confirm());
    assert.equal(first.read().stage, 'admit');
    await assert.rejects(first.quote({products: ['employees']}));
    assert.throws(() => first.reset(receipt));
    const next = new helpers.EnrichmentSession({...scope, post, storage});
    const final = await next.resume();
    assert.equal(final.admission, 'settled');
    assert.equal(new Set(keys).size, 1);
    assert.equal(admissions, 2, 'same idempotent admission replay');
    next.reset(final); assert.equal(next.read(), null);
});

test('journals isolate actor, tenant and record and never store purchased values', async () => {
    const storage = memory(); const first = new helpers.EnrichmentSession({...scope, storage, post: async () => ({...receipt, values: ['private']})});
    await first.quote({products: ['email'], waterfall: false, refresh: false});
    assert.ok(!JSON.stringify(first.read()).includes('private'));
    for (const changed of [{actorId: 'other'}, {tenantId: 'other'}, {targetId: 'other'}, {targetType: 'Contact'}]) {
        assert.equal(new helpers.EnrichmentSession({...scope, ...changed, storage, post: async () => receipt}).read(), null);
    }
});

test('stale terms can be discarded after explicit confirmation rejection, uncertain confirmation cannot', async () => {
    for (const status of [409, 503]) {
        const session = new helpers.EnrichmentSession({...scope, storage: memory(), post: async url => {
            if (url.endsWith('/confirm')) throw {status};
            return receipt;
        }});
        await session.quote({products: ['email']});
        await assert.rejects(session.confirm());
        if (status === 409) { session.reset(receipt); assert.equal(session.read(), null); }
        else assert.throws(() => session.reset(receipt));
    }
});

test('late progress after record switch and duplicate click cannot affect the new view', async () => {
    let finish; let calls = 0;
    const View = load(client + 'src/views/enrichment.js', {'views/record/panels/bottom': class {}, 'feature-credits:helpers/enrichment': helpers,
        'feature-credits:helpers/presentation': {formatCredits: x => x}}).default;
    const page = new View();
    Object.assign(page, {version: 1, session: {resume: () => { calls++; return new Promise(r => { finish = r; }); }, read: () => ({})}, isRendered: () => false});
    const pending = page.progress('resume');
    await page.progress('resume'); assert.equal(calls, 1);
    page.version++; page.receipt = null; page.busy = false;
    finish(receipt); await pending; assert.equal(page.receipt, null);
});

test('native template escapes purchased data and translations cover every control', () => {
    const template = read(client + 'res/templates/enrichment.tpl');
    const en = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/i18n/en_US/CreditEnrichment.json')).labels;
    const pt = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/i18n/pt_BR/CreditEnrichment.json')).labels;
    assert.deepEqual(Object.keys(en).sort(), Object.keys(pt).sort());
    for (const labels of [en, pt]) {
        for (const [, key] of template.matchAll(/translate '([^']+)'/g)) assert.ok(labels[key], key);
        handlebars.registerHelper('translate', key => labels[key]);
        const html = handlebars.compile(template)({hasContext: true, detail: {application: {available: true}}, values: [{field: 'name', value: '<img src=x onerror=alert(1)>'}]});
        assert.ok(!html.includes('<img')); assert.ok(html.includes('&lt;img'));
    }
});
