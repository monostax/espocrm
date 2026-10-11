const assert = require('node:assert/strict');
const {readFileSync, mkdtempSync, readdirSync, rmSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');
const handlebars = require('handlebars');
const {Transpiler} = require('espo-frontend-build-tools');
const root = path.join(__dirname, '../..');
const client = 'client/custom/modules/feature-credits/';
const read = file => readFileSync(path.join(root, file), 'utf8');
const en = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/i18n/en_US/Credits.json')).labels;
const pt = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/i18n/pt_BR/Credits.json')).labels;
function load(file, imports = {}, globals = {}) {
    const exports = {};
    const {outputText} = ts.transpileModule(read(file), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}});
    vm.runInNewContext(outputText, {exports, require: key => imports[key], URLSearchParams, ...globals}, {filename: file});
    return exports;
}
const helpers = load(client + 'src/helpers/presentation.js');
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return {promise, resolve, reject}; };
const tick = () => new Promise(resolve => setImmediate(resolve));
function view(getRequest) {
    const Page = load(client + 'src/views/page.js', {'views/main': class {}, 'feature-credits:helpers/presentation': helpers}, {Espo: {Ajax: {getRequest}}}).default;
    const page = new Page();
    page.navigation = [];
    Object.assign(page, {version: 0, tenantId: 'a', tab: 'operations', cursors: [null],
        context: {tenants: [{id: 'a'}, {id: 'b'}]}, translate: key => en[key] || key,
        reRender: async () => {}, getRouter: () => ({navigate: (...args) => page.navigation.push(args)})});
    return page;
}
const balance = tenantId => ({tenantId, walletExists: true, availableCredits: '9999999999.9999', reservedCredits: '0.0000', pendingExpirationCredits: '0.0000', balance: '9999999999.9999'});
const report = tenantId => ({tenantId, list: [{id: 'op', settledCredits: null, accruedCreditsExact: '0.0001875'}], nextCursor: null});

test('exact amounts, unknown final charges and waived requests remain distinct', () => {
    const ui = helpers.present(balance('a'), report('a'), 'operations', key => en[key]);
    assert.equal(ui.cards[0].value, '9999999999.9999');
    assert.equal(ui.rows[0].cells[4].value, '0.0001875');
    assert.equal(ui.rows[0].cells[5].value, en.notFinal);
    const zero = helpers.present(null, {list: [{settledCredits: '0.0000'}]}, 'operations', key => en[key]);
    assert.equal(zero.rows[0].cells[5].value, '0.0000');
    const waived = helpers.present(null, {list: [{billingState: 'waived', pricedCreditsExact: null}]}, 'requests', key => en[key]);
    assert.equal(waived.rows[0].cells[3].value, en.waived);
    assert.equal(waived.rows[0].cells[6].value, en.notFinal);
    assert.equal(helpers.present({...balance('a'), walletExists: false}, null, 'operations', key => key).walletMissing, true);
    assert.equal(helpers.present({...balance('a'), availableCredits: '0.0000'}, null, 'operations', key => key).exhausted, true);
    const debt = helpers.present({...balance('a'), availableCredits: '-5.0000', balance: '-5.0000'}, null, 'history', key => key);
    assert.equal(debt.exhausted, true);
    assert.equal(debt.cards[0].value, '-5.0000');
});

test('template escapes names, renders all reports, and has bilingual accessible states', () => {
    assert.deepEqual(Object.keys(en).sort(), Object.keys(pt).sort());
    const template = read(client + 'res/templates/page.tpl');
    for (const match of template.matchAll(/translate '([^']+)'/g)) {
        assert.ok(en[match[1]] && pt[match[1]], match[1]);
    }
    for (const labels of [en, pt]) {
        handlebars.registerHelper('translate', key => labels[key] || key);
        for (const tab of Object.keys(helpers.reports)) {
            const html = handlebars.compile(template)({...helpers.present(balance('a'), report('a'), tab, key => labels[key]),
                hasData: true, tenants: [{id: 'a', name: '<script>secret</script>'}], sourceMessage: labels.sourceUnavailable});
            assert.ok(!html.includes('<script>secret</script>'));
            assert.ok(html.includes('&lt;script&gt;'));
            assert.ok(html.includes('role="status"'));
            assert.ok(html.includes('scope="col"'));
            assert.ok(!html.includes('undefined'));
        }
    }
});

test('tenant switch clears balances immediately and discards late old responses', async () => {
    const requests = [];
    const page = view((endpoint, query) => { const d = deferred(); requests.push({endpoint, query, ...d}); return d.promise; });
    page.balance = balance('a'); page.page = report('a');
    const first = page.load();
    assert.equal(page.balance, null);
    await tick();
    const second = page.changeTenant('b');
    assert.equal(page.page, null);
    await tick();
    requests.filter(r => r.query.tenantId === 'b').forEach(r => { r.resolve(r.endpoint === 'CreditBalance' ? balance('b') : report('b')); });
    await second;
    requests.filter(r => r.query.tenantId === 'a').forEach(r => { r.resolve(r.endpoint === 'CreditBalance' ? balance('a') : report('a')); });
    await first;
    assert.equal(page.balance.tenantId, 'b');
    assert.equal(page.page.tenantId, 'b');
    assert.equal(page.navigation.length, 1);
    assert.ok(page.navigation[0][0].includes('tenantId=b'));
});

test('denied, expired and failed reads clear prior balances and offer an honest error', async () => {
    for (const status of [401, 403, 500]) {
        const page = view(async () => { throw {status, setHandled() {}}; });
        page.balance = balance('a'); page.page = report('a');
        await page.load();
        assert.equal(page.balance, null);
        assert.equal(page.page, null);
        assert.equal(page.loading, false);
        assert.equal(page.error, status === 401 ? en.sessionExpired : status === 403 ? en.forbidden : en.loadError);
    }
});

test('clearing tenant selection invalidates pending reads and clears all financial data', async () => {
    const d = deferred();
    const page = view(() => d.promise);
    const pending = page.load();
    await tick();
    await page.changeTenant('');
    d.resolve(report('a'));
    await pending;
    assert.equal(page.tenantId, '');
    assert.equal(page.balance, null);
    assert.equal(page.page, null);
    assert.equal(page.loading, false);
});

test('cross-tenant responses and removed-view responses are discarded', async () => {
    const page = view(async endpoint => endpoint === 'CreditBalance' ? balance('other') : report('other'));
    await page.load();
    assert.equal(page.balance, null);
    assert.equal(page.error, en.loadError);
    const d = deferred();
    const removed = view(() => d.promise);
    const pending = removed.load();
    await tick();
    removed.disposed = true;
    d.resolve(balance('a'));
    await pending;
    assert.equal(removed.balance, null);
    assert.equal(removed.navigation.length, 0);
});

test('pagination uses opaque cursors and refresh and tab changes restart paging', async () => {
    const calls = [];
    const page = view(async (endpoint, query) => {
        calls.push({endpoint, query});
        return endpoint === 'CreditBalance' ? balance(query.tenantId) : {...report(query.tenantId), nextCursor: 'opaque'};
    });
    await page.load();
    await page.next();
    assert.equal(calls.at(-1).query.cursor, 'opaque');
    await page.previous();
    assert.equal(calls.at(-1).query.cursor, undefined);
    await page.next();
    await page.refresh();
    assert.equal(page.cursors.length, 1);
    assert.equal(calls.at(-1).query.cursor, undefined);
    await page.changeTab('requests');
    assert.equal(calls.at(-1).endpoint, 'CreditRequests');
    assert.equal(calls.at(-1).query.cursor, undefined);
});

test('source navigation is ACL resolved, allowlisted and ignores stale or duplicate clicks', async () => {
    let count = 0;
    const d = deferred();
    const page = view(() => { count++; return d.promise; });
    page.page = report('a');
    const pending = page.openSource('op');
    await page.openSource('op');
    assert.equal(count, 1);
    page.version++;
    d.resolve({tenantId: 'a', id: 'op', source: {scope: 'Opportunity', id: 'source'}});
    await pending;
    assert.equal(page.navigation.length, 0);
    const valid = {tenantId: 'a', id: 'op', source: {scope: 'Opportunity', id: 'source'}};
    assert.equal(helpers.sourceRoute(valid, 'a', 'op'), 'Opportunity/view/source');
    assert.equal(helpers.sourceRoute(valid, 'b', 'op'), null);
    assert.equal(helpers.sourceRoute({...valid, source: {scope: 'User', id: 'source'}}, 'a', 'op'), null);
    assert.equal(helpers.sourceRoute({...valid, source: {scope: 'Opportunity', id: '../bad'}}, 'a', 'op'), null);
    const available = view(async () => valid); available.page = report('a');
    await available.openSource('op');
    assert.equal(available.navigation[0][0], 'Opportunity/view/source');
    const restricted = view(async () => ({...valid, source: null})); restricted.page = report('a');
    await restricted.openSource('op');
    assert.equal(restricted.sourceMessage, en.sourceUnavailable);
    assert.equal(restricted.navigation.length, 0);
});

test('explicit unauthorized tenant does not silently display another tenant', async () => {
    const page = view(async endpoint => { assert.equal(endpoint, 'Credits/context'); return {tenants: [{id: 'b'}]}; });
    await page.bootstrap();
    assert.equal(page.tenantId, '');
    assert.equal(page.balance, null);
    assert.equal(page.error, en.forbidden);
});

test('production transpiler emits loadable AMD for the credit page and helpers', () => {
    const metadata = JSON.parse(read('custom/Espo/Modules/FeatureCredits/Resources/module.json'));
    assert.equal(metadata.jsTranspiled, true, 'Espo must discover and load the transpiled credit module');
    const dir = mkdtempSync('/tmp/opencode/credits-amd-');
    try {
        new Transpiler({path: path.resolve(root, client), destDir: dir}).process();
        const definitions = new Map();
        const modules = new Map([['views/main', class {}], ['controller', class {}]]);
        for (const file of readdirSync(dir, {recursive: true}).filter(file => file.endsWith('.js'))) {
            vm.runInNewContext(readFileSync(path.join(dir, file), 'utf8'), {
                define(id, deps, factory) { definitions.set('feature-credits:' + id, {deps, factory}); },
            });
        }
        function resolve(id) {
            if (modules.has(id)) return modules.get(id);
            const definition = definitions.get(id);
            assert.ok(definition, id);
            const exports = {};
            definition.factory(...definition.deps.map(d => d === 'exports' ? exports : resolve(d)));
            const value = exports.default || exports;
            modules.set(id, value);
            return value;
        }
        for (const id of definitions.keys()) resolve(id);
        assert.equal(definitions.size, 3);
        assert.equal(typeof resolve('feature-credits:helpers/presentation').sourceRoute, 'function');
    } finally { rmSync(dir, {recursive: true, force: true}); }
});
