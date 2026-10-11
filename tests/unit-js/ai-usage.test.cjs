const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');
const handlebars = require('handlebars');
const {mkdtempSync, readdirSync, rmSync} = require('node:fs');
const {Transpiler} = require('espo-frontend-build-tools');
const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const client = 'client/custom/modules/feature-ai-usage/';
const resources = 'custom/Espo/Modules/FeatureAiUsage/Resources/';
const en = JSON.parse(read(resources + 'i18n/en_US/AiUsage.json')).labels;
const pt = JSON.parse(read(resources + 'i18n/pt_BR/AiUsage.json')).labels;

function load(file, imports = {}) {
    const exports = {};
    const {outputText} = ts.transpileModule(read(file), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}});
    vm.runInNewContext(outputText, {exports, require: key => imports[key], Intl, Date, console}, {filename: file});
    return exports;
}
const {allowance, present, presentMetrics} = load(client + 'src/helpers/presentation.js');
const Format = load(client + 'src/helpers/format.js').default;
const f = new Format('en_US');
const t = key => en[key] || key;
const state = {view: 'overview', dimension: 'kind', offset: 0, filters: {}};
const fixture = () => ({
    billing: {status: 'ready', model: 'credit', allowance: 100, consumed: 125, covered: 100, remaining: 0, overage: 25, charges: [{currency: 'BRL', amount: 12.25}]},
    period: {month: '2026-09', resetAt: '2026-10-01T00:00:00-03:00', timeZone: 'America/Sao_Paulo'},
    generatedAt: '2026-09-27T12:00:00Z', usage: {runs: 125, conversations: 5, conversationDays: 17, opportunities: 2, opportunityDays: 7, meteredRuns: 100}, filteredUsage: {runs: 125},
    rates: [{from: '2026-09-01', to: null, currency: 'BRL', model: 'credit', unitPrice: 0.49}],
    daily: [{day: '2026-09-01', runs: 125, consumed: 125, covered: 100, overage: 25, charges: [{currency: 'BRL', amount: 12.25}]}],
});

test('allowance states distinguish empty usage, approaching limit, overage, pay-as-you-go and unknown', () => {
    const b = fixture().billing;
    assert.equal(allowance(b).state, 'overLimit');
    assert.equal(allowance(b).coveredWidth, 80);
    assert.equal(allowance(b).overageWidth, 20);
    assert.equal(allowance({...b, consumed: 0, covered: 0, overage: 0}).state, 'withinPlan');
    assert.equal(allowance({...b, consumed: 85, covered: 85, overage: 0}).state, 'nearLimit');
    assert.equal(allowance({...b, allowance: 0}).percentage, null);
    assert.equal(allowance({...b, allowance: 0}).state, 'payAsYouGo');
    assert.equal(allowance({...b, status: 'configurationRequired'}).ready, false);
});

test('formatting keeps currencies separate, exact money accessible and unknown distinct from zero', () => {
    assert.equal(f.number(null), '—');
    assert.equal(f.number(0), '0');
    assert.equal(f.number(12.34, 2), '12.34');
    assert.equal(new Format('pt_BR').number(12.34, 2), '12,34');
    assert.equal(f.charges(null), '—');
    assert.match(f.charges([{currency: 'BRL', amount: 12.25}, {currency: 'USD', amount: 3}]), /12\.25.*3\.00/);
    const ui = present(fixture(), state, f, t);
    assert.match(ui.cards[3].exact, /12\.25/);
    assert.equal(ui.coverageText, '80%');
    assert.equal(ui.conversationDaysText, '17');
    assert.equal(ui.opportunityDaysText, '7');
    assert.equal(ui.daily[0].coveredHeight, 80);
});

test('templates render real presentation data with accessible labels and escaped record content', () => {
    handlebars.registerHelper('translate', key => en[key] || key);
    const page = handlebars.compile(read(client + 'res/templates/page.tpl'));
    const html = page({...present(fixture(), state, f, t), hasData: true, tenants: [{id: 't', name: '<script>bad()</script>', selected: true}], month: '2026-09', maxMonth: '2026-09'});
    assert.match(html, /Covered by your plan/);
    assert.match(html, /au-impact-value">17<\/span><span>Conversation-days with AI runs/);
    assert.match(html, /au-impact-value">7<\/span><span>Opportunity-days with AI runs/);
    assert.ok(html.includes(en.conversationDaysEpisodeDistinction));
    assert.ok(html.includes('America/Sao_Paulo'));
    assert.match(html, /width:80%/);
    assert.match(html, /aria-label="R\$12\.25"/);
    assert.ok(!html.includes('<script>bad()'));
    assert.match(html, /&lt;script&gt;/);
    const unknown = fixture();
    unknown.billing = {status: 'configurationRequired', reason: 'missingRate', model: null, allowance: null, consumed: null, covered: null, remaining: null, overage: null, charges: null};
    assert.match(page({...present(unknown, state, f, t), hasData: true}), /Billing configuration required/);
    handlebars.compile(read(client + 'res/templates/detail.tpl'))({failed: true});
});

test('all template and explicitly used presentation labels have Portuguese and English translations', () => {
    assert.deepEqual(Object.keys(en).sort(), Object.keys(pt).sort());
    for (const file of ['page.tpl', 'detail.tpl']) {
        for (const match of read(client + 'res/templates/' + file).matchAll(/translate '([^']+)'/g)) {
            assert.ok(en[match[1]], `Missing English label ${match[1]}`);
            assert.ok(pt[match[1]], `Missing Portuguese label ${match[1]}`);
        }
    }
});

test('breakdown does not invent individual costs and historical comparison uses engagements explicitly', () => {
    const data = fixture();
    data.breakdown = {total: 1, list: [{key: 'search', runs: 4, days: 2, share: 80, billing: null}]};
    data.comparison = {usage: {runs: 0}, period: {from: '2026-08-01', through: '2026-08-27'}};
    const ui = present(data, {...state, view: 'breakdown', dimension: 'action'}, f, t);
    assert.equal(ui.rows[0].chargesText, '—');
    assert.equal(ui.rows[0].consumedText, '—');
    assert.ok(!ui.comparisonText.includes('Infinity'));
});

test('activity billing keeps failures, plan coverage, shared charges and unavailable data distinct in both languages', () => {
    const statuses = ['failed', 'waived', 'pending', 'billed', 'partiallyBilled', 'included', 'notBilled', 'excluded', 'unavailable', undefined];
    const data = fixture();
    data.activity = {total: statuses.length, list: statuses.map((billingStatus, i) => ({
        id: String(i), runAt: '2026-09-01 12:00:00', actions: [], billingStatus,
    }))};
    for (const labels of [en, pt]) {
        const ui = present(data, {...state, view: 'activity'}, f, key => labels[key] || key);
        for (const [i, status] of statuses.entries()) {
            assert.equal(ui.activities[i].billingText, labels['billing_' + (status || 'unavailable')]);
            assert.ok(ui.activities[i].billingHint);
        }
        handlebars.registerHelper('translate', key => labels[key] || key);
        const html = handlebars.compile(read(client + 'res/templates/page.tpl'))({...ui, hasData: true});
        assert.ok(html.includes(labels.billing_failed));
        assert.ok(html.includes(labels.billingStatus));
    }
});

test('breakdown shows failures inside activity totals and only offers financial columns for attributable groupings', () => {
    const data = fixture();
    data.filteredUsage = {runs: 8, failedRuns: 2};
    data.breakdown = {total: 1, list: [{key: 'customer-message', runs: 8, failedRuns: 2, days: 2, share: 100, billing: null}]};
    handlebars.registerHelper('translate', key => pt[key] || key);
    const page = handlebars.compile(read(client + 'res/templates/page.tpl'));
    for (const dimension of ['kind', 'action', 'agent', 'account', 'conversation', 'opportunity']) {
        const ui = present(data, {...state, view: 'breakdown', dimension}, f, key => pt[key] || key);
        const html = page({...ui, hasData: true});
        const table = html.match(/<table class="table au-breakdown-table">.*?<\/table>/s)[0];
        assert.ok(table.includes(pt.failedNotBilled));
        assert.equal(ui.rows[0].runsText, '8');
        assert.equal(ui.rows[0].failedRunsText, '2');
        assert.ok(html.includes('2 ' + pt.failedNotBilled));
        assert.equal(table.includes(pt.billingUnits), ['conversation', 'opportunity'].includes(dimension));
        assert.equal(html.includes(pt.billingGroupingHint), !['conversation', 'opportunity'].includes(dimension));
    }
});

test('waived and pending usage have distinct neutral customer explanations', () => {
    const data = fixture();
    data.usage.waivedRuns = 22;
    data.usage.pendingRuns = 2;
    for (const labels of [en, pt]) {
        handlebars.registerHelper('translate', key => labels[key] || key);
        const ui = present(data, state, f, key => labels[key] || key);
        const html = handlebars.compile(read(client + 'res/templates/page.tpl'))({...ui, hasData: true});
        assert.ok(html.includes('22 ' + labels.waivedRuns));
        assert.ok(html.includes('2 ' + labels.pendingRuns));
        assert.ok(html.includes(labels.waivedExplanation));
        assert.ok(html.includes(labels.pendingExplanation));
        assert.ok(!html.includes('alert-warning'));
    }
});

test('token columns and detail values are admin-only and distinguish missing counts from zero', () => {
    const data = fixture();
    data.activity = {total: 1, list: [{id: 'r', actions: [], inputTokens: 1234, outputTokens: 0, cachedInputTokens: null}]};
    const ui = present(data, {...state, view: 'activity'}, f, t);
    assert.equal(ui.activities[0].inputTokensText, '1,234');
    assert.equal(ui.activities[0].outputTokensText, '0');
    assert.equal(ui.activities[0].cachedTokensText, '—');
    const Page = load(client + 'src/views/page.js', {
        'views/main': class {}, 'feature-ai-usage:helpers/index': {present},
    }).default;
    const Detail = load(client + 'src/views/detail.js', {'views/modal': class {}, 'feature-ai-usage:helpers/index': {presentMetrics}}).default;
    for (const isAdmin of [false, true]) {
        const page = new Page();
        Object.assign(page, {payload: data, state, format: f, t, translate: t, getUser: () => ({isAdmin: () => isAdmin})});
        assert.equal(page.data().showTokenUsage, isAdmin);
        const detail = new Detail();
        Object.assign(detail, {payload: {activity: data.activity.list[0]}, options: {format: f},
            translate: t, getUser: () => ({isAdmin: () => isAdmin})});
        assert.equal(detail.data().showTokenUsage, isAdmin);
        for (const labels of [en, pt]) {
            handlebars.registerHelper('translate', key => labels[key] || key);
            const html = handlebars.compile(read(client + 'res/templates/page.tpl'))({...ui, hasData: true, showTokenUsage: isAdmin});
            const detailHtml = handlebars.compile(read(client + 'res/templates/detail.tpl'))(detail.data());
            for (const key of ['inputTokens', 'outputTokens', 'cachedTokens']) {
                assert.equal(html.includes(labels[key]), isAdmin);
                assert.equal(detailHtml.includes(labels[key]), isAdmin);
            }
        }
    }
});

test('internal dashboard renders filtered metrics, sources and grouped intelligence only for admins', () => {
    const data = fixture();
    const metrics = {runs: 2, totalTokens: 1100, inputTokens: 1000, outputTokens: 100, cachedInputTokens: 100,
        uncachedInputTokens: 900, tokenCacheHitPct: 10, requestCacheHitPct: 25, reasoningTokens: null,
        sources: {main: {requests: 4, measuredRequests: 4, knownRuns: 2, inputTokens: 1000}, search: {requests: 0}}};
    data.analytics = {totals: {...metrics, totalTokens: 2200}, filtered: metrics, previous: {...metrics, totalTokens: 1000},
        models: [{key: '<script>model</script>', ...metrics}], outcomes: [{key: 'failed', ...metrics}], daily: [{key: '2026-09-01', ...metrics}]};
    const ui = present(data, state, f, t);
    assert.equal(ui.analytics.monthTotal, '2,200');
    assert.equal(ui.analytics.cards[0].value, '1,100');
    assert.equal(ui.analytics.change, '+120%');
    assert.equal(ui.analytics.cards.find(card => card.key === 'tokenCacheHitPct').value, '10%');
    assert.equal(ui.analytics.cards.find(card => card.key === 'reasoningTokens').value, '—');
    for (const labels of [en, pt]) {
        const translate = key => { assert.ok(labels[key], `Missing analytics label: ${key}`); return labels[key]; };
        const localized = present(data, {...state, view: 'analytics'}, f, translate);
        handlebars.registerHelper('translate', key => labels[key] || key);
        const page = handlebars.compile(read(client + 'res/templates/page.tpl'));
        const admin = page({...localized, hasData: true, showTokenUsage: true});
        assert.ok(admin.includes(labels.internalAnalytics));
        assert.ok(admin.includes(labels.outcome_failed));
        assert.ok(admin.includes('data-au-day="2026-09-01"'));
        assert.ok(admin.includes('&lt;script&gt;model&lt;/script&gt;'));
        assert.ok(admin.includes(labels.previousTokens));
        assert.ok(admin.includes(labels.adminOnly));
        assert.ok(!admin.includes('EspoCRM'));
        assert.ok(!admin.includes('au-allowance panel'));
        assert.ok(!admin.includes('au-activity-table'));
        assert.ok(admin.includes('data-au-filter="from"'));
        for (const view of ['overview', 'breakdown', 'activity']) {
            const other = page({...present(data, {...state, view}, f, translate), hasData: true, showTokenUsage: true});
            assert.ok(!other.includes(labels.internalAnalytics));
            assert.ok(other.includes('data-au-view="analytics"'));
        }
        assert.ok(!page({...localized, hasData: true, showTokenUsage: false}).includes(labels.internalAnalytics));
        assert.ok(!page({...localized, hasData: true, showTokenUsage: false}).includes('data-au-view="analytics"'));
    }
});

test('analytics navigation preserves filters and daily drill-down opens activity', () => {
    const Page = load(client + 'src/views/page.js', {'views/main': class {}}).default;
    const page = new Page();
    let loads = 0;
    Object.assign(page, {state: {...state, filters: {kind: 'private-mention'}, filterLabels: {}, offset: 25},
        getUser: () => ({isAdmin: () => true}), load: () => loads++});
    page.selectView('analytics');
    assert.equal(page.state.view, 'analytics');
    assert.equal(page.state.offset, 0);
    assert.equal(page.state.filters.kind, 'private-mention');
    page.openDay('2026-09-01');
    assert.equal(page.state.view, 'activity');
    assert.equal(page.state.filters.from, '2026-09-01');
    assert.equal(page.state.filters.to, '2026-09-01');
    page.getUser = () => ({isAdmin: () => false});
    page.selectView('analytics');
    assert.equal(page.state.view, 'activity');
    assert.equal(loads, 2);
});

test('the production transpiler emits loadable AMD for every usage module', () => {
    const dir = mkdtempSync('/tmp/opencode/ai-usage-amd-');
    try {
        new Transpiler({path: path.resolve(root, client), destDir: dir}).process();
        const definitions = new Map();
        const modules = new Map([['views/main', class {}], ['views/modal', class {}], ['controller', class {}], ['ui/datepicker', class {}]]);
        for (const file of readdirSync(dir, {recursive: true}).filter(file => file.endsWith('.js'))) {
            vm.runInNewContext(readFileSync(path.join(dir, file), 'utf8'), {
                Intl, Date, define(id, deps, factory) {definitions.set('feature-ai-usage:' + id, {deps, factory});},
            });
        }
        function resolve(id) {
            if (modules.has(id)) return modules.get(id);
            const definition = definitions.get(id);
            assert.ok(definition, `Missing built module: ${id}`);
            const exports = {};
            definition.factory(...definition.deps.map(dependency => dependency === 'exports' ? exports : resolve(
                dependency.startsWith('.') ? id.slice(0, id.lastIndexOf('/') + 1) + dependency.slice(2) : dependency
            )));
            const value = 'default' in exports ? exports.default : exports;
            modules.set(id, value);
            return value;
        }
        for (const id of definitions.keys()) resolve(id);
        assert.equal(definitions.size, 6);
        assert.equal(typeof resolve('feature-ai-usage:helpers/index').present, 'function');
    } finally {
        rmSync(dir, {recursive: true, force: true});
    }
});
