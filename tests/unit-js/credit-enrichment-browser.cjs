// Actual native CRM controls with deterministic API fixtures; no live supplier or financial writes.
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const {chromium} = require('playwright');
const root = path.join(__dirname, '../..');
const client = 'client/custom/modules/feature-credits/';
const read = file => readFileSync(path.join(root, file), 'utf8');
function moduleCode(id, file) {
    return `modules[${JSON.stringify(id)}] = {}; (function(exports, require) { ${ts.transpileModule(read(file), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}}).outputText}\n })(modules[${JSON.stringify(id)}], id => modules[id]);`;
}
(async () => {
    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined,
        args: ['--no-sandbox'],
    });
    try {
        for (const locale of ['en_US', 'pt_BR']) {
            const page = await browser.newPage(); const errors = []; page.on('pageerror', e => errors.push(e.message));
            let request; let lost = true; let admitted = false; let application = null; const keys = new Set();
            let finderApplication = null;
            const finderPurchase = () => ({receipt: {id: 'candidate', product: 'socios', provider: 'cpfcnpj', credits: '1.0000'},
                provenance: {actorId: 'human', delivery: 'live'}, values: {name: 'Supplier name'},
                application: {available: !finderApplication, applied: !!finderApplication, creationForm: {
                    available: true, teamsAllowed: true, teamsRequired: true, teams: [{id: 'own-team', name: 'Workspace team'}],
                    fields: [{name: 'lastName', type: 'varchar', required: true}, {name: 'cLevel', type: 'enum', required: true, options: ['A', 'B']},
                        {name: 'cConsent', type: 'bool', required: true}, {name: 'cCount', type: 'int', required: true}],
                }}});
            const receipt = () => ({id: 'quote', tenantId: 'tenant', targetType: 'Account', targetId: 'record',
                products: request.products, waterfall: request.waterfall, refresh: request.refresh, maximum: '5.1000',
                routes: {email: [{provider: 'hub', credits: '5.1000'}]}, confirmable: true,
                admission: admitted ? 'settled' : 'not_admitted', executionProducts: admitted ? {email: {state: 'purchased'}} : {}});
            const purchased = () => ({receipt: {id: 'purchase', quoteId: 'quote', product: 'email', provider: 'hub', credits: '5.1000', purchasedAt: '2026-10-13 12:00:00', acquiredAt: '2026-10-13 12:00:00'},
                provenance: {actorId: 'human', delivery: 'cache'}, values: {0: 'selected@example.test'},
                application: {applied: !!application, available: !application, permissions: {editableFields: ['emailAddress']}}});
            await page.exposeFunction('enrichmentApi', async (method, endpoint, body) => {
                if (endpoint === 'CreditEnrichmentQuotes') { request = body; keys.add(body.requestKey); return receipt(); }
                if (endpoint.endsWith('/confirm')) return receipt();
                if (endpoint.endsWith('/admit')) { admitted = true; if (lost) { lost = false; throw new Error('Lost admission reply'); } return receipt(); }
                if (endpoint === 'CreditEnrichmentHistory') { const item = body.product === 'socios' ? finderPurchase() : purchased(); delete item.values; return {list: [item], next: null}; }
                if (endpoint === 'CreditEnrichmentReview') return body.id === 'candidate' ? finderPurchase() : purchased();
                if (endpoint === 'Account/record') return {emailAddress: 'manual@example.test'};
                if (endpoint === 'CreditEnrichmentApplication') {
                    assert.deepEqual(body, {tenantId: 'tenant', purchaseId: 'candidate', createIfUnmatched: true,
                        contactData: {lastName: 'Human choice', cLevel: 'B', cConsent: false, cCount: 2, teamsIds: ['own-team']}});
                    finderApplication = body; return {purchaseId: 'candidate', contactId: 'created'};
                }
                assert.equal(endpoint, 'CreditEnrichmentFields'); assert.equal(method, 'POST');
                assert.deepEqual(body.fields, {emailAddress: 'selected@example.test'});
                assert.deepEqual(body.expected, {emailAddress: 'manual@example.test'});
                application = body; return {id: 'purchase', appliedAt: '2026-10-13 12:01:00'};
            });
            const labels = JSON.parse(read(`custom/Espo/Modules/FeatureCredits/Resources/i18n/${locale}/CreditEnrichment.json`)).labels;
            await page.addInitScript({content: readFileSync(require.resolve('handlebars/dist/handlebars.js'), 'utf8') + `
                const modules = {}; const labels = ${JSON.stringify(labels)};
                Handlebars.registerHelper('translate', key => labels[key] || key);
                const template = Handlebars.compile(${JSON.stringify(read(client + 'res/templates/enrichment.tpl'))});
                modules['views/record/panels/bottom'] = class {
                    constructor() { this.model = {id: 'record', entityType: 'Account', get: key => key === 'tenantId' ? 'tenant' : null, fetch() {}}; }
                    setup() {} data() { return {}; } listenTo() {} on() {} wait(p) { this.ready = p; } isRendered() { return true; }
                    getUser() { return {id: 'human'}; } translate(key) { return labels[key] || key; }
                    reRender() { document.getElementById('root').innerHTML = template(this.data()); return Promise.resolve(); }
                    addHandler(type, selector, handler) { document.addEventListener(type, e => { const target = e.target.closest(selector); if (target) handler({currentTarget: target, preventDefault: () => e.preventDefault()}); }); }
                };
                window.Espo = {Ajax: {getRequest: (url, body) => enrichmentApi('GET', url, body), postRequest: (url, body) => enrichmentApi('POST', url, body)}};
                ${moduleCode('feature-credits:helpers/presentation', client + 'src/helpers/presentation.js')}
                ${moduleCode('feature-credits:helpers/enrichment', client + 'src/helpers/enrichment.js')}
                ${moduleCode('view', client + 'src/views/enrichment.js')}
                document.addEventListener('DOMContentLoaded', async () => { window.view = new modules.view.default(); view.setup(); await view.ready; await view.reRender(); window.ready = true; });
            `});
            await page.route('**/*', route => {
                assert.ok(route.request().url().startsWith('http://127.0.0.1:32198/'));
                return route.fulfill({contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><main id="root"></main>'});
            });
            await page.goto('http://127.0.0.1:32198/'); await page.waitForFunction(() => window.ready);
            await page.locator('[name=waterfall]').check(); await page.locator('[name=budget]').fill('5.1000');
            await page.locator('[data-enrichment-quote] button').click();
            await page.locator('[data-enrichment-confirm]').click();
            await page.waitForFunction(() => !view.busy && !!view.error);
            await page.reload(); await page.waitForFunction(() => window.ready);
            await page.locator('[data-enrichment-resume]').click(); await page.waitForFunction(() => !view.busy && view.receipt?.admission === 'settled');
            assert.equal(keys.size, 1);
            await page.locator('[data-enrichment-history]').click(); await page.locator('[data-enrichment-review]').click();
            await page.locator('[data-enrichment-field]').selectOption('selected@example.test');
            await page.locator('[data-enrichment-apply] button').click(); await page.waitForFunction(() => !view.busy && !view.detail);
            assert.ok(application);
            assert.ok(!(await page.evaluate(() => JSON.stringify({...sessionStorage}))).includes('selected@example.test'));
            await page.locator('[data-enrichment-history-product]').selectOption('socios');
            await page.locator('[data-enrichment-review="candidate"]').click();
            await page.locator('[name=createIfUnmatched]').check();
            await page.locator('[data-enrichment-apply] button').click();
            assert.equal(finderApplication, null, 'required fields prevent incomplete creation');
            await page.locator('[name=contact-lastName]').fill('Human choice');
            await page.locator('[name=contact-cLevel]').selectOption('B');
            await page.locator('[name=contact-cConsent]').selectOption('false');
            await page.locator('[name=contact-cCount]').fill('2');
            await page.locator('[name=teamsIds]').selectOption('own-team');
            await page.locator('[data-enrichment-apply] button').click();
            await page.waitForFunction(() => !view.busy && !view.detail);
            assert.ok(finderApplication);
            assert.deepEqual(errors, []);
            console.log(`PASS ${locale}: quote, confirmation, reload recovery, field application and Finder required scalar/team creation (${await browser.version()}).`);
            await page.close();
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
