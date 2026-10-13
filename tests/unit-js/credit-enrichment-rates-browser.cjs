// Native administrator controls against deterministic CRM API fixtures.
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const {chromium} = require('playwright');
const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root,file),'utf8');
const client = 'client/custom/modules/feature-credits/';
(async () => {
    const browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox']});
    try {
        for (const locale of ['en_US','pt_BR']) {
            const page = await browser.newPage(); const errors = [];
            page.on('pageerror',error => { errors.push(error.message); console.error(error.message); });
            let original; let calls = 0; let lost = true;
            const current = {version: 'original', policyId: 'old-policy', effectiveFrom: '2026-10-13 00:00:00', routingPolicy: 'br-discovery-v2',
                rates: [{target: 'Contact', provider: 'apollo', lookup: 'person', product: 'email', credits: '1.0000'}],
                routing: {waterfallOff: ['apollo'], Contact: ['cpfcnpj','hub','apollo'], Account: ['hub','cpfcnpj','apollo'], company: ['apollo'], combinedOrder: ['email','phone']}};
            await page.exposeFunction('rateApi',async (method,url,body) => {
                assert.equal(url,'CreditEnrichmentConfiguration');
                if (method === 'GET') {
                    if (original) assert.equal(body.policyId,'old-policy','reload reviews the original routing snapshot');
                    return current;
                }
                ++calls;
                if (!original) original = body;
                assert.deepEqual(body,original,'retry keeps immutable version and exact terms');
                if (lost) { lost = false; throw new Error('Lost publication reply'); }
                return {id: 'catalog', policy: {...body, policyId: 'published-policy'}};
            });
            const labels = JSON.parse(read(`custom/Espo/Modules/FeatureCredits/Resources/i18n/${locale}/CreditEnrichmentRates.json`)).labels;
            const output = ts.transpileModule(read(client + 'src/views/enrichment-rates.js'),{compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}}).outputText;
            await page.addInitScript({content: readFileSync(require.resolve('handlebars/dist/handlebars.js'),'utf8') + `
                (function () {
                const labels = ${JSON.stringify(labels)};
                Handlebars.registerHelper('translate',key => labels[key] || key);
                const template = Handlebars.compile(${JSON.stringify(read(client + 'res/templates/enrichment-rates.tpl'))});
                const exports = {};
                const require = () => class {
                    setup() {} data() { return {}; } on() {} wait(p) { this.ready = p; } isRendered() { return true; }
                    getUser() { return {id: 'admin', isAdmin: () => !location.search.includes('denied')}; }
                    translate(key) { return labels[key] || key; }
                    reRender() { document.getElementById('root').innerHTML = template(this.data()); return Promise.resolve(); }
                    addHandler(type,selector,handler) { document.addEventListener(type,e => { const target=e.target.closest(selector); if(target) handler({currentTarget:target, preventDefault:()=>e.preventDefault()}); }); }
                };
                window.Espo={Ajax:{getRequest:(url,body)=>rateApi('GET',url,body),postRequest:(url,body)=>rateApi('POST',url,body)}};
                ${output}
                document.addEventListener('DOMContentLoaded',async()=>{ window.view=new exports.default();view.setup();await view.ready;await view.reRender();window.ready=true; });
                })();
            `});
            await page.route('**/*',route => {
                assert.ok(route.request().url().startsWith('http://127.0.0.1:32198/'));
                return route.fulfill({contentType: 'text/html',body: '<!doctype html><meta charset="utf-8"><main id="root"></main>'});
            });
            await page.goto('http://127.0.0.1:32198/'); await page.waitForFunction(() => window.ready);
            await page.locator('[name=version]').fill('prospective-v3');
            await page.locator('[name=effectiveFrom]').fill('2027-01-01T00:00');
            await page.locator('[name=rate-0]').fill('2.1234');
            await page.locator('[data-rate-draft] button').click();
            assert.equal(calls,0,'review never publishes');
            await page.locator('[data-rate-publish]').click();
            await page.waitForFunction(() => !view.busy && !!view.error);
            await page.reload(); await page.waitForFunction(() => window.ready);
            assert.equal(calls,1,'mount never replays a mutation');
            assert.equal(await page.locator('[name=version]').count(),0,'unresolved version cannot be replaced');
            await page.locator('[data-rate-publish]').click();
            await page.waitForFunction(() => !view.busy && !!view.published);
            assert.equal(calls,2); assert.equal(original.rates[0].credits,'2.1234');
            assert.equal(original.effectiveFrom,'2027-01-01 00:00:00');
            assert.equal(await page.evaluate(() => sessionStorage.length),0);
            await page.goto('http://127.0.0.1:32198/?denied'); await page.waitForFunction(() => window.ready);
            assert.equal(await page.locator('form').count(),0);
            assert.deepEqual(errors,[]);
            console.log(`PASS ${locale}: native rate review/publication, exact reload recovery and non-admin UI denial (${await browser.version()}).`);
            await page.close();
        }
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
