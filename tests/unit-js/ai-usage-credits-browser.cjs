// Actual report view/templates with deterministic HTTP fixtures, not deployed-shell acceptance.
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const {chromium} = require('playwright');
const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const client = 'client/custom/modules/feature-ai-usage/';
const compile = file => ts.transpileModule(read(file), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}}).outputText;
(async () => {
    const browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox']});
    try {
        for (const locale of ['en_US', 'pt_BR']) {
            const labels = JSON.parse(read(`custom/Espo/Modules/FeatureAiUsage/Resources/i18n/${locale}/AiUsage.json`)).labels;
            const page = await browser.newPage(); const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.addInitScript({content: readFileSync(require.resolve('handlebars/dist/handlebars.js'), 'utf8') + `
                (() => {
                    const labels = ${JSON.stringify(labels)};
                    Handlebars.registerHelper('translate', key => labels[key] || key);
                    const template = Handlebars.compile(${JSON.stringify(read(client + 'res/templates/page.tpl'))});
                    const modules = {};
                    function module(id, source) {const exports = {}; new Function('exports', 'require', source)(exports, key => modules[key]); modules[id] = exports;}
                    module('feature-credits:helpers/presentation', ${JSON.stringify(compile('client/custom/modules/feature-credits/src/helpers/presentation.js'))});
                    module('./format', ${JSON.stringify(compile(client + 'src/helpers/format.js'))});
                    module('./presentation', ${JSON.stringify(compile(client + 'src/helpers/presentation.js'))});
                    module('feature-ai-usage:helpers/index', ${JSON.stringify(compile(client + 'src/helpers/index.js'))});
                    modules['views/main'] = class {
                        setup() {} on() {} wait(p) {this.ready = p;}
                        getPreferences() {return {get: () => '${locale}'};}
                        getConfig() {return {get() {}};}
                        getUser() {return {isAdmin: () => false};}
                        getRouter() {return {navigate() {}};}
                        translate(key) {return labels[key] || key;}
                        async reRender() {document.getElementById('root').innerHTML = template(this.data());}
                    };
                    modules['ui/datepicker'] = class {};
                    window.Espo = {Ajax: {getRequest: async (url, query) => {
                        if (window.denied) throw {status: 403};
                        if (url === 'AiUsage/context') return {tenants: [{id: 'tenant', name: 'Workspace'}], currentMonth: '2026-10'};
                        return {tenantId: query.tenantId, period: {month: '2026-10', from: '2026-10-01', through: '2026-10-13', resetAt: '2026-11-01T00:00:00Z', timeZone: 'UTC'},
                            generatedAt: '2026-10-13T12:00:00Z', billing: {status: 'ready', model: 'credit', allowance: 1, consumed: 1, covered: 1, overage: 0, remaining: 0, charges: []}, rates: [],
                            credits: {chargedCredits: '1.4924', reversedCredits: '0.0001', netCredits: '1.4923'},
                            usage: {runs: 3, meteredRuns: 3}, filteredUsage: {runs: 3}, daily: [],
                            activity: {total: 3, list: [
                                {id: 'settled', runAt: '2026-10-13 11:00:00', kind: 'customer-message', actions: [], billingStatus: 'creditSettled', creditBilling: {settledCredits: '1.4924'}},
                                {id: 'pending', runAt: '2026-10-13 11:00:00', kind: 'customer-message', actions: [], billingStatus: 'creditPending', creditBilling: {settledCredits: null}},
                                {id: 'zero', runAt: '2026-10-13 11:00:00', kind: 'customer-message', actions: [], billingStatus: 'creditSettled', creditBilling: {settledCredits: '0.0000'}},
                            ]}};
                    }}};
                    module('page', ${JSON.stringify(compile(client + 'src/views/page.js'))});
                    document.addEventListener('DOMContentLoaded', async () => {
                        window.view = new modules.page.default(); view.options = {params: {tenantId: 'tenant'}};
                        for (const [event, handler] of Object.entries(view.events)) {
                            const split = event.indexOf(' ');
                            document.addEventListener(event.slice(0, split), e => {const target = e.target.closest(event.slice(split + 1)); if (target) handler.call(view, {currentTarget: target});});
                        }
                        view.setup(); await view.ready; await view.reRender(); window.ready = true;
                    });
                })();`});
            await page.route('**/*', route => route.fulfill({contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><main id="root"></main>'}));
            await page.goto('http://127.0.0.1:32197/'); await page.waitForFunction(() => window.ready);
            assert.ok((await page.locator('main').innerText()).includes(labels.postedCreditsExplanation));
            assert.ok((await page.locator('main').innerText()).includes('<0.01'));
            await page.locator('[data-au-view="activity"]').click();
            await page.waitForFunction(() => !view.loading && view.state.view === 'activity');
            const table = await page.locator('.au-activity-table').innerText();
            assert.ok(table.includes(labels.billing_creditSettled + ' · 1.49'));
            assert.ok(table.includes(labels.billing_creditPending));
            assert.ok(table.includes(labels.billing_creditSettled + ' · 0.00'));
            assert.equal(await page.locator('[data-au-view="analytics"]').count(), 0);
            await page.evaluate(() => {window.denied = true;});
            await page.locator('[data-au-refresh]').first().click();
            await page.waitForFunction(() => !view.loading && view.error);
            assert.equal(await page.locator('.au-activity-table').count(), 0);
            assert.equal(await page.locator('.au-content').count(), 0);
            assert.deepEqual(errors, []);
            console.log(locale + ': report navigation, exact display, pending/zero and denied refresh pass');
            await page.close();
        }
        console.log('Chromium ' + browser.version());
    } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
