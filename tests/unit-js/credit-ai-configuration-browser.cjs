// Actual native controls, deterministic API fixtures; no deployed-shell acceptance claim.
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
            const page = await browser.newPage(); const errors = []; const commands = [];
            page.on('pageerror', error => errors.push(error.message));
            const model = {provider: 'google', model: 'gemini-3.8-flash', multiplier: '1', inputTokenBound: 1048576, outputTokenLimit: 65536, boundProfile: 'approved-profile'};
            const agreement = {tenantId: 'tenant', version: 'v1', effectiveFrom: '2026-01-01 00:00:00', effectiveUntil: null,
                currency: 'BRL', creditUnitPrice: '0.50000000', monthlyCredits: '0.0000', aiModelCreditRateId: 'main', aiNativeSearchModelCreditRateId: 'native', aiMaxRequests: 100};
            await page.exposeFunction('configApi', async (method, url, body) => {
                assert.equal(url, 'CreditConfiguration');
                if (method === 'GET') return {id: body.id, kind: body.kind, data: body.kind === 'model' ? model : agreement};
                commands.push(body);
                if ([1, 3].includes(commands.length)) throw new Error('Lost committed reply');
                assert.deepEqual(body, commands[commands.length - 2]);
                return body.operation === 'publishModel' ? {id: 'model-new', policyId: 'hash', policy: {...body.data, modelRateId: 'hash'}} : {id: 'agreement-new', version: body.data.version};
            });
            const labels = JSON.parse(read(`custom/Espo/Modules/FeatureCredits/Resources/i18n/${locale}/CreditAiConfiguration.json`)).labels;
            await page.addInitScript({content: readFileSync(require.resolve('handlebars/dist/handlebars.js'), 'utf8') + `
                (() => {
                    const labels = ${JSON.stringify(labels)};
                    Handlebars.registerHelper('translate', key => labels[key] || key);
                    const template = Handlebars.compile(${JSON.stringify(read(client + 'res/templates/ai-configuration.tpl'))});
                    const presentation = (() => {const exports = {}; ${transpile('src/helpers/presentation.js')} return exports;})();
                    const exports = {};
                    const require = key => key === 'feature-credits:helpers/presentation' ? presentation : class {
                        setup() {} data() {return {};} on() {} wait(p) {this.ready = p;} isRendered() {return true;}
                        getUser() {return {id: 'admin', isAdmin: () => !location.search.includes('denied')};}
                        translate(key) {return labels[key] || key;}
                        reRender() {document.getElementById('root').innerHTML = template(this.data()); return Promise.resolve();}
                        addHandler(type, selector, handler) {document.addEventListener(type, e => {const target=e.target.closest(selector); if (target) handler({currentTarget: target, preventDefault: () => e.preventDefault()});});}
                    };
                    window.Espo = {Ajax: {getRequest: (url, body) => configApi('GET', url, body), postRequest: (url, body) => configApi('POST', url, body)}};
                    ${transpile('src/views/ai-configuration.js')}
                    document.addEventListener('DOMContentLoaded', async () => {window.view = new exports.default(); view.setup(); await view.ready; await view.reRender(); window.ready = true;});
                })();
            `});
            await page.route('**/*', route => route.fulfill({contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><main id="root"></main>'}));
            await page.goto('http://127.0.0.1:32198/'); await page.waitForFunction(() => window.ready);
            await page.locator('[name=originalId]').fill('model-original');
            await page.locator('[data-config-lookup] button').click(); await page.waitForFunction(() => !view.busy && view.original);
            await page.locator('[name=multiplier]').fill('1.0000000001');
            await page.locator('[data-config-draft] button').click();
            assert.equal(commands.length, 0); assert.ok((await page.locator('main').innerText()).includes('1.0000000001'));
            await page.locator('[data-config-publish]').click(); await page.waitForFunction(() => !view.busy && view.error);
            await page.reload(); await page.waitForFunction(() => window.ready);
            assert.equal(await page.locator('[data-config-edit]').count(), 0);
            await page.locator('[data-config-publish]').click(); await page.waitForFunction(() => !view.busy && view.published);
            assert.equal(commands.length, 2); assert.equal(commands[0].data.inputTokenBound, 1048576);
            assert.equal(commands[0].data.multiplier, '1.0000000001');
            await page.locator('[data-config-mode=replaceAgreement]').click();
            await page.locator('[data-config-lookup] [name=tenantId]').fill('tenant');
            await page.locator('[name=originalId]').fill('old-agreement');
            await page.locator('[data-config-lookup] button').click(); await page.waitForFunction(() => !view.busy && view.original);
            assert.equal(await page.locator('[name=version]').inputValue(), '');
            await page.locator('[name=version]').fill('v2');
            await page.locator('[name=effectiveFrom]').fill('2027-01-01T00:00:01');
            await page.locator('[name=creditUnitPrice]').fill('0.50000001');
            await page.locator('[name=monthlyCredits]').fill('12.3456');
            await page.locator('[name=aiNativeSearchModelCreditRateId]').fill('');
            await page.locator('[data-config-draft] button').click();
            assert.equal(commands.length, 2); assert.ok((await page.locator('main').innerText()).includes('12.35'));
            await page.locator('[data-config-publish]').click(); await page.waitForFunction(() => !view.busy && view.error);
            await page.reload(); await page.waitForFunction(() => window.ready);
            await page.locator('[data-config-publish]').click(); await page.waitForFunction(() => !view.busy && view.published);
            assert.equal(commands.length, 4); assert.equal(commands[2].data.replacesId, 'old-agreement');
            assert.equal(commands[2].data.aiNativeSearchModelCreditRateId, null);
            assert.equal(commands[2].data.effectiveFrom, '2027-01-01 00:00:01');
            assert.equal(commands[2].data.creditUnitPrice, '0.50000001'); assert.equal(commands[2].data.monthlyCredits, '12.3456');
            assert.equal(await page.evaluate(() => sessionStorage.length), 0);
            await page.goto('http://127.0.0.1:32198/?denied'); await page.waitForFunction(() => window.ready);
            assert.equal(await page.locator('form').count(), 0); assert.equal(commands.length, 4); assert.deepEqual(errors, []);
            console.log('PASS ' + locale + ': model and original agreement forms, exact recovery, native selection and admin controls (' + await browser.version() + ').');
            await page.close();
        }
    } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
