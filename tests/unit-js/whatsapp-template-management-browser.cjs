// Real browser inputs, native XMLHttpRequest/multipart and deterministic dependency responses.
// Live Meta/Chatwoot compatibility is a separate predeployment gate.
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const client = 'client/custom/modules/feature-meta-whatsapp-business/src/';

(async () => {
    const browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
        args: ['--no-sandbox']});
    try {
        for (const locale of ['en_US', 'pt_BR']) {
            const page = await browser.newPage();
            const errors = []; const requests = [];
            page.on('pageerror', error => { errors.push(error.message); console.error(error.message); });
            const translations = JSON.parse(read(`custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Resources/i18n/${locale}/WhatsAppBusinessAccountMessageTemplate.json`));
            const script = readFileSync(require.resolve('jquery/dist/jquery.js'), 'utf8') + '\n' +
                readFileSync(require.resolve('handlebars/dist/handlebars.js'), 'utf8') + `
                (() => {
                    const translations = ${JSON.stringify(translations)};
                    class Base {
                        constructor(options) {this.options = options; this.$el = $('#root');}
                        static extend(defs) {class Child extends this {} Object.assign(Child.prototype, defs); return Child;}
                        setup() {} afterRender() {} data() {return {};}
                        translate(key, category) {return translations[category]?.[key] || key;}
                        confirm() {return Promise.resolve();}
                        close() {this.closed = true;}
                        trigger(event) {this.lastEvent = event;}
                        disableButton(name) {this.$el.find('[data-name="' + name + '"]').prop('disabled', true);}
                        enableButton(name) {this.$el.find('[data-name="' + name + '"]').prop('disabled', false);}
                        reRender() {
                            this.$el.off().html(Handlebars.compile(this.templateContent)(this.data()));
                            const footer = $('<footer>').appendTo(this.$el);
                            this.buttonList.forEach(button => $('<button>').attr('data-name', button.name).text(button.label).appendTo(footer)
                                .on('click', () => this['action' + button.name[0].toUpperCase() + button.name.slice(1)]?.()));
                            for (const [event, handler] of Object.entries(this.events || {})) {
                                const split = event.indexOf(' ');
                                this.$el.on(event.slice(0, split), event.slice(split + 1), handler.bind(this));
                            }
                            this.afterRender();
                            return Promise.resolve();
                        }
                    }
                    const modules = {'views/modal': Base};
                    window.define = (id, deps, fn) => {modules[id] = fn(...deps.map(dep => modules[dep]));};
                    window.Espo = {};
                    const exports = {};
                    const require = id => ({jquery: $, utils: {obtainBaseUrl: () => location.origin + '/', trimSlash: value => value},
                        'util/ajax': {AjaxPromise: Promise, Xhr: class extends XMLHttpRequest {
                            get responseJSON() {try {return JSON.parse(this.responseText);} catch {return null;}}
                        }}}[id]);
                    ${ts.transpileModule(read('client/src/ajax.ts'), {compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS}}).outputText}
                    Espo.Ajax.configure({apiUrl: 'api/v1', timeout: 125000, onSuccess() {}, onError() {}, onTimeout() {}, beforeSend() {}});
                    ${read(client + 'template-definition.js')}
                    ${read(client + 'views/template-editor.js')}
                    (async () => {
                        window.mount = async options => {
                            window.view = new modules['feature-meta-whatsapp-business:views/template-editor']({context: {oAuthAccountId: 'oauth', businessAccountId: '123'}, ...options});
                            view.setup(); await view.reRender();
                        };
                        await mount({}); window.ready = true;
                    })();
                })();`;
            await page.route('**/*', async route => {
                const request = route.request();
                if (request.url().includes('/api/v1/')) {
                    requests.push({url: request.url(), contentType: request.headers()['content-type'], body: request.postDataBuffer()});
                    return route.fulfill({status: requests.length === 1 ? 504 : 200, contentType: 'application/json',
                        body: JSON.stringify(requests.length === 1 ? {error: 'uncertain', message: 'Refresh before retrying.'} : {accepted: true, templateId: '999'})});
                }
                return route.fulfill({contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><main id="root"></main>'});
            });
            await page.goto('http://127.0.0.1:32219/');
            await page.addScriptTag({content: script});
            await page.waitForFunction(() => window.ready);
            await page.locator('[data-field=name]').fill('order_ready');
            await page.locator('[data-field=headerFormat]').selectOption('TEXT');
            await page.locator('[data-field=header]').fill('Hello {{1}}');
            await page.locator('[data-field=body]').fill('Order {{1}} is ready');
            await page.locator('.template-examples input').nth(0).fill('123');
            await page.locator('.template-examples input').nth(1).fill('Ana');
            await page.locator('[data-action=addButton]').click();
            await page.locator('[data-button=text]').fill('Thanks');
            assert.equal(await page.locator('[data-field=body]').inputValue(), 'Order {{1}} is ready');
            assert.match(await page.locator('.template-preview').innerText(), /Hello Ana/);
            await page.locator('[data-name=submit]').click();
            await page.waitForFunction(() => view.error && !view.busy);
            assert.equal(requests.length, 1, 'an uncertain mutation is never automatically retried');
            const payload = JSON.parse(requests[0].body.toString());
            assert.equal(payload.definition.components[0].example.header_text[0], 'Ana');
            assert.equal(payload.definition.components[1].example.body_text[0][0], '123');
            assert.equal(await page.locator('[data-field=name]').inputValue(), 'order_ready');
            await page.locator('[data-field=headerFormat]').selectOption('IMAGE');
            await page.locator('[data-name=submit]').click();
            assert.equal(requests.length, 1, 'media requires fresh sample selection');
            await page.locator('[data-sample]').setInputFiles({name: 'sample.png', mimeType: 'image/png',
                buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0ioAAAAASUVORK5CYII=', 'base64')});
            await page.locator('[data-name=submit]').click();
            await page.waitForFunction(() => view.closed);
            assert.equal(requests.length, 2);
            assert.match(requests[1].contentType, /multipart\/form-data; boundary=/);
            assert.match(requests[1].body.toString(), /name="payload"/);
            assert.match(requests[1].body.toString(), /filename="sample.png"/);
            await page.evaluate(() => mount({inspect: true, templateRecord: {name: 'advanced', language: 'pt_BR', category: 'UTILITY',
                status: 'FUTURE_STATUS', supported: false, canEdit: false, components: '[{"type":"CAROUSEL","cards":[]}]'}}));
            assert.equal(await page.locator('[data-name=submit]').count(), 0);
            assert.match(await page.locator('pre').innerText(), /CAROUSEL/);
            assert.match(await page.locator('#root').innerText(), /FUTURE_STATUS/);
            assert.deepEqual(errors, []);
            console.log(`PASS ${locale}: editor, independent examples, uncertain-outcome preservation, multipart sample and unsupported inspection`);
            await page.close();
        }
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
