// Real native forms; deterministic API fixtures, not deployed shell/provider acceptance.
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const {chromium} = require('playwright');
const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const client = 'client/custom/modules/feature-credits/';
const transpile = file => ts.transpileModule(read(client + file), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}}).outputText;
(async () => {
    const browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox']});
    try {
        for (const locale of ['en_US', 'pt_BR']) {
            const page = await browser.newPage(); const errors = []; let calls = 0; let original;
            page.on('pageerror', error => errors.push(error.message));
            const current = {catalogId: 'original', version: 'v1', effectiveFrom: '2026-10-13 00:00:00',
                products: [{productId: 'pack', productVersion: 'v1', name: 'Package', operation: 'purchase', credits: '500.0000'}],
                offers: [{schema: 'billing-offer-v1', termsId: 'terms', offerId: 'pack-br', version: 'v1', productId: 'pack', productVersion: 'v1',
                    countries: ['BR'], currency: 'BRL', price: '299.00', interval: null, operation: 'purchase', credits: '500.0000'}]};
            await page.exposeFunction('catalogApi', async (method, url, body) => {
                assert.equal(url, 'CreditBillingConfiguration');
                if (method === 'GET') return current;
                ++calls;
                if (calls === 1) { original = body; throw new Error('Lost committed response'); }
                if (calls === 2) assert.deepEqual(body, original, 'reload retries exact terms');
                return {id: 'catalog', catalog: {...body, catalogId: 'published'}};
            });
            const labels = JSON.parse(read(`custom/Espo/Modules/FeatureCredits/Resources/i18n/${locale}/CreditBillingCatalog.json`)).labels;
            await page.addInitScript({content: readFileSync(require.resolve('handlebars/dist/handlebars.js'), 'utf8') + `
                (() => {
                    const labels = ${JSON.stringify(labels)};
                    Handlebars.registerHelper('translate', key => labels[key] || key);
                    const template = Handlebars.compile(${JSON.stringify(read(client + 'res/templates/billing-catalog.tpl'))});
                    const presentation = (() => { const exports = {}; ${transpile('src/helpers/presentation.js')} return exports; })();
                    const exports = {};
                    const require = key => key === 'feature-credits:helpers/presentation' ? presentation : class {
                        setup() {} data() {return {};} on() {} wait(p) {this.ready = p;} isRendered() {return true;}
                        getUser() {return {id: 'admin', isAdmin: () => !location.search.includes('denied')};}
                        translate(key) {return labels[key] || key;}
                        reRender() {document.getElementById('root').innerHTML = template(this.data()); return Promise.resolve();}
                        addHandler(type, selector, handler) {document.addEventListener(type, e => {const target=e.target.closest(selector); if (target) handler({currentTarget: target, preventDefault: () => e.preventDefault()});});}
                    };
                    window.Espo = {Ajax: {getRequest: (url, body) => catalogApi('GET', url, body), postRequest: (url, body) => catalogApi('POST', url, body)}};
                    ${transpile('src/views/billing-catalog.js')}
                    document.addEventListener('DOMContentLoaded', async () => {window.view = new exports.default(); view.setup(); await view.ready; await view.reRender(); window.ready = true;});
                })();
            `});
            await page.route('**/*', route => route.fulfill({contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><main id="root"></main>'}));
            await page.goto('http://127.0.0.1:32199/'); await page.waitForFunction(() => window.ready);
            await page.locator('[name=version]').fill('catalog-v2');
            await page.locator('[name=effectiveFrom]').fill('2027-01-01T00:00');
            await page.locator('[name=product-0-productVersion]').fill('v2');
            await page.locator('[name=product-0-credits]').fill('500.1234');
            await page.locator('[name=offer-0-productVersion]').fill('v2');
            await page.locator('[name=offer-0-version]').fill('v2');
            await page.locator('[name=offer-0-price]').fill('309.99');
            await page.locator('[data-catalog-add=products]').click();
            assert.equal(await page.locator('[name=offer-0-price]').inputValue(), '309.99', 'row changes preserve unsaved edits');
            await page.locator('[data-catalog-remove=products][data-index="1"]').click();
            await page.locator('[data-catalog-draft] button[type=submit]').click();
            assert.equal(calls, 0); assert.equal(await page.locator('[name=product-0-credits]').count(), 0);
            assert.ok((await page.locator('main').innerText()).includes('500.12'));
            await page.locator('[data-catalog-publish]').click(); await page.waitForFunction(() => !view.busy && view.error);
            await page.reload(); await page.waitForFunction(() => window.ready);
            assert.equal(calls, 1); assert.equal(await page.locator('[data-catalog-edit]').count(), 0);
            await page.locator('[data-catalog-publish]').click(); await page.waitForFunction(() => !view.busy && view.published);
            assert.equal(calls, 2); assert.equal(original.products[0].credits, '500.1234');
            assert.equal(original.offers[0].price, '309.99'); assert.equal(original.offers[0].interval, null);
            assert.equal(original.effectiveFrom, '2027-01-01 00:00:00');
            assert.ok(!('termsId' in original.offers[0]));
            assert.equal(await page.evaluate(() => sessionStorage.length), 0);
            await page.locator('[data-catalog-load]').click(); await page.waitForFunction(() => !view.busy && view.draft.products.length === 1);
            await page.locator('[data-catalog-remove=offers]').click(); await page.locator('[data-catalog-remove=products]').click();
            await page.locator('[name=version]').fill('withdraw-all'); await page.locator('[name=effectiveFrom]').fill('2027-02-01T00:00');
            await page.locator('[data-catalog-draft] button[type=submit]').click();
            assert.equal(calls, 2, 'withdrawal also requires explicit publication');
            assert.ok((await page.locator('main').innerText()).includes(labels.completeCatalog));
            await page.locator('[data-catalog-publish]').click(); await page.waitForFunction(() => !view.busy && view.published?.version === 'withdraw-all');
            assert.equal(calls, 3);
            await page.goto('http://127.0.0.1:32199/?denied'); await page.waitForFunction(() => window.ready);
            assert.equal(await page.locator('form').count(), 0); assert.equal(calls, 3); assert.deepEqual(errors, []);
            console.log('PASS ' + locale + ': catalog edits, exact lost-response recovery, explicit withdrawal and admin controls (' + await browser.version() + ').');
            await page.close();
        }
    } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
