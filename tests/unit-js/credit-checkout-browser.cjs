// Explicit browser component acceptance with deterministic CRM responses, not deployed CRM/provider acceptance.
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const {chromium} = require('playwright');
const root = path.join(__dirname, '../..');
const modulePath = 'client/custom/modules/feature-credits/';
const read = file => readFileSync(path.join(root, file), 'utf8');
function moduleCode(id, file) {
    const {outputText} = ts.transpileModule(read(file), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}});
    return `modules[${JSON.stringify(id)}] = {}; (function(exports, require) { ${outputText}\n })(modules[${JSON.stringify(id)}], id => modules[id]);`;
}

(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
    try {
        for (const locale of ['en_US', 'pt_BR']) {
            const terms = {offerId: 'monthly-2000-br', version: 'launch-v1', productId: 'monthly-2000', currency: 'BRL',
                price: '997.00', credits: '2000.0000', operation: 'subscription', termsId: 'terms-v1'};
            let accepted = null; let customer = null; let charge = null;
            let acceptPosts = 0; let customerPosts = 0; let chargePosts = 0; let recoveries = 0;
            const context = tenantId => ({tenantId, revision: 1, billingCountry: 'BR', catalogId: 'catalog-v1', earliestDueDate: '2026-10-13',
                offers: [{terms, eligibility: {eligible: tenantId === 'a', blockedReason: tenantId === 'a' ? null : 'billing_provider_unavailable'}}],
                checkouts: accepted && tenantId === 'a' ? [{state: charge ? 'pending' : 'accepted', acceptance: accepted}] : []});
            const page = await browser.newPage(); const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.exposeFunction('checkoutApi', async (method, endpoint, body) => {
                if (method === 'GET') {
                    if (endpoint === 'Credits/context') return {tenants: [{id: 'a', name: 'Test tenant A'}, {id: 'b', name: 'Test tenant B'}]};
                    if (endpoint === 'CreditCheckout/context') return context(body.tenantId);
                    assert.equal(endpoint, 'CreditCheckout'); assert.equal(body.tenantId, 'a');
                    return {tenantId: 'a', obligationId: 'obligation', acceptance: accepted, state: charge ? (charge.state === 'bound' ? 'pending' : 'unknown') : customer ? 'creating' : 'accepted', customer, charge, earliestDueDate: '2026-10-13'};
                }
                assert.equal(body.tenantId, 'a');
                if (endpoint === 'CreditBilling/accept') {
                    ++acceptPosts; accepted = {tenantId: 'a', obligationId: 'obligation', requestKey: body.requestKey, terms}; return accepted;
                }
                if (endpoint === 'CreditBilling/customer') {
                    ++customerPosts; assert.equal(body.payer.cpfCnpj, '52998224725');
                    customer = {creationId: 'customer', state: 'bound', environment: 'sandbox'}; return {...customer, tenantId: 'a'};
                }
                if (endpoint === 'CreditBilling/charge') {
                    ++chargePosts; assert.equal(body.billingType, 'BOLETO'); assert.equal(body.dueDate, '2026-10-13');
                    charge = {creationId: 'charge', state: 'unknown', environment: 'sandbox'};
                    throw new Error('Simulated lost response after charge creation');
                }
                if (endpoint === 'CreditBilling/recoverCreation') {
                    ++recoveries; assert.equal(body.creationId, 'charge'); charge.state = 'bound'; return {...charge, tenantId: 'a'};
                }
                assert.equal(endpoint, 'CreditCheckout/payment');
                return {tenantId: 'a', obligationId: 'obligation', environment: 'sandbox', state: 'pending', invoiceUrl: 'https://sandbox.asaas.com/i/verified-fixture'};
            });
            const labels = JSON.parse(read(`custom/Espo/Modules/FeatureCredits/Resources/i18n/${locale}/CreditCheckout.json`)).labels;
            const bootstrap = `
                const modules = {};
                modules['views/main'] = class {
                    constructor(options) { this.options = options; }
                    setup() {} on() {} wait(promise) { this.ready = promise; }
                    getUser() { return {id: 'browser-test-user'}; }
                    getRouter() { return {navigate: url => history.replaceState(null, '', '#' + url)}; }
                    translate(key) { return labels[key] || key; }
                    async reRender() { document.getElementById('root').innerHTML = template(this.data()); }
                };
                window.Espo = {Ajax: {getRequest: (endpoint, body) => window.checkoutApi('GET', endpoint, body), postRequest: (endpoint, body) => window.checkoutApi('POST', endpoint, body)}};
                const labels = ${JSON.stringify(labels)};
                Handlebars.registerHelper('translate', key => labels[key] || key);
                const template = Handlebars.compile(${JSON.stringify(read(modulePath + 'res/templates/checkout.tpl'))});
                ${moduleCode('feature-credits:helpers/presentation', modulePath + 'src/helpers/presentation.js')}
                ${moduleCode('feature-credits:helpers/checkout', modulePath + 'src/helpers/checkout.js')}
                ${moduleCode('checkout-view', modulePath + 'src/views/checkout.js')}
                document.addEventListener('DOMContentLoaded', async () => {
                    const params = Object.fromEntries(new URLSearchParams(location.hash.split('/').slice(2).join('/')));
                    window.view = new modules['checkout-view'].default({params: {tenantId: 'a', ...params}});
                    for (const [key, handler] of Object.entries(view.events)) {
                        const space = key.indexOf(' '), type = key.slice(0, space), selector = key.slice(space + 1);
                        document.addEventListener(type, event => {
                            const target = event.target.closest(selector);
                            if (target) handler.call(view, {currentTarget: target, preventDefault: () => event.preventDefault()});
                        });
                    }
                    view.setup(); await view.ready; await view.reRender(); window.checkoutReady = true;
                });
            `;
            await page.addInitScript({content: readFileSync(require.resolve('handlebars/dist/handlebars.js'), 'utf8') + '\n' + bootstrap});
            await page.route('**/*', route => {
                assert.ok(route.request().url().startsWith('http://127.0.0.1:32199/'), 'Browser must not contact a live provider');
                return route.fulfill({contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"><title>Checkout component test</title></head><body><main id="root"></main></body></html>'});
            });
            await page.goto('http://127.0.0.1:32199/');
            await page.waitForFunction(() => window.checkoutReady && !view.busy);
            await page.locator('[data-checkout-offer]').click();
            await page.locator('[data-checkout-accept] button').click();
            assert.equal(acceptPosts, 0, 'Browser validation requires explicit terms consent');
            await page.locator('[name="acceptTerms"]').check(); await page.locator('[data-checkout-accept] button').click();
            await page.locator('[name="payerName"]').fill('Authorized Browser Payer');
            await page.locator('[name="cpfCnpj"]').fill('529.982.247-25');
            await page.locator('[name="email"]').fill('browser-payer@example.test');
            await page.locator('[data-checkout-payer] button').click();
            await page.locator('[data-checkout-charge] button').click();
            await page.waitForFunction(() => !view.busy && !!view.error);
            assert.equal(chargePosts, 1);
            await page.reload(); await page.waitForFunction(() => window.checkoutReady && !view.busy);
            await page.locator('[data-checkout-recover]').click();
            await page.locator('[data-checkout-payment]').click();
            const link = page.locator('a[target="_blank"]'); await link.waitFor();
            assert.equal(await link.getAttribute('href'), 'https://sandbox.asaas.com/i/verified-fixture');
            assert.equal(await link.getAttribute('rel'), 'noopener noreferrer');
            assert.equal(await link.getAttribute('referrerpolicy'), 'no-referrer');
            const saved = await page.evaluate(() => JSON.stringify({...sessionStorage}));
            assert.ok(!saved.includes('52998224725') && !saved.includes('browser-payer@example.test'));
            await page.locator('[data-checkout-tenant]').selectOption('b');
            await page.waitForFunction(() => !view.busy && view.tenantId === 'b');
            assert.equal(await link.count(), 0); assert.equal(await page.locator('[data-checkout-offer]').isDisabled(), true);
            assert.deepEqual([acceptPosts, customerPosts, chargePosts, recoveries], [1, 1, 1, 1]);
            assert.deepEqual(errors, []);
            console.log(`PASS ${locale}: real forms/terms validation, one charge, reload/recovery, private hosted link, disabled sales and tenant isolation (${await browser.version()}).`);
            await page.close();
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
