// Actual Vue component + EN/PT text; mocked CRM transport, not deployed-shell acceptance.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createRequire} = require('node:module');
const {chromium} = require('playwright');
const source = process.env.CHATWOOT_SOURCE_DIR || path.resolve(__dirname, '../../../../chatwoot/source');
const chatwoot = createRequire(path.join(source, 'package.json'));
const {build} = createRequire(chatwoot.resolve('vite'))('esbuild');
const {parse, compileScript} = chatwoot('@vue/compiler-sfc');
const component = path.join(source, 'app/javascript/dashboard/components-next/sidebar/ConversationCredits.vue');

(async () => {
    const bundle = await build({
        absWorkingDir: source, bundle: true, write: false, format: 'iife', platform: 'browser',
        define: {'process.env.NODE_ENV': '"production"', __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false'},
        stdin: {resolveDir: source, contents: `
            import {createApp, h, reactive} from 'vue';
            import {createI18n} from 'vue-i18n';
            import Component from ${JSON.stringify(component)};
            import en from './app/javascript/dashboard/components-next/records/enrichment-i18n/en.json';
            import pt from './app/javascript/dashboard/components-next/records/enrichment-i18n/pt_BR.json';
            import {route} from 'vue-router';
            const props = reactive({conversationId: 10});
            window.context = (accountId, conversationId) => {route.params.accountId = accountId; props.conversationId = conversationId;};
            createApp({render: () => h(Component, props)}).use(createI18n({legacy: false, locale: window.locale, messages: {en, pt}})).mount('#app');
        `},
        plugins: [{name: 'component-fixture', setup(builder) {
            builder.onResolve({filter: /^vue-router$/}, () => ({path: 'route', namespace: 'fixture'}));
            builder.onResolve({filter: /^(dashboard\/api\/crm\/opportunity|\.\/opportunity)$/}, () => ({path: 'crm', namespace: 'fixture'}));
            builder.onLoad({filter: /.*/, namespace: 'fixture'}, ({path: name}) => ({
                resolveDir: source,
                contents: name === 'route' ? `import {reactive} from 'vue'; export const route = reactive({params: {accountId: '6'}}); export const useRoute = () => route;` :
                    `export default {}; export const crmClient = {get: async (url, {params}) => {window.calls.push({url, params}); return window.reply(params);}};`,
            }));
            builder.onResolve({filter: /^(dashboard|shared)\//}, ({path: name}) => ({path: path.join(source, 'app/javascript', name + '.js')}));
            builder.onLoad({filter: /\.vue$/}, ({path: file}) => {
                const {descriptor} = parse(fs.readFileSync(file, 'utf8'), {filename: file});
                return {contents: compileScript(descriptor, {id: 'credit-feedback', inlineTemplate: true}).content, loader: 'js', resolveDir: path.dirname(file)};
            });
        }}],
    });
    const browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined, args: ['--no-sandbox']});
    try {
        for (const locale of ['en', 'pt']) {
            const page = await browser.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.setContent('<div id="app"></div>');
            await page.evaluate(locale => {
                window.locale = locale;
                window.calls = [];
                window.receipt = (params, state = 'pending', amount = null) => ({data: {...params, tenantId: 'tenant', list: [{id: 'usage', state, settledCredits: amount, admittedAt: '2026-10-13 12:00:00'}]}});
                window.reply = params => Promise.resolve(window.receipt(params));
            }, locale);
            await page.addScriptTag({content: bundle.outputFiles[0].text});
            const pending = locale === 'en' ? 'final credits unknown' : 'créditos finais desconhecidos';
            await page.getByText(pending, {exact: false}).waitFor();
            assert(!(await page.locator('#app').innerText()).includes('0.00'));
            await page.evaluate(() => { window.reply = params => Promise.resolve(window.receipt(params, 'settled', '1.4924')); });
            await page.getByRole('button').click();
            await page.getByText('1.49', {exact: false}).waitFor();
            // Start a refresh, switch account+conversation, then deliver the stale result.
            await page.evaluate(() => { window.reply = params => new Promise(resolve => { window.finish = () => resolve(window.receipt(params, 'settled', '99.0000')); }); });
            await page.getByRole('button').click();
            await page.evaluate(() => {
                window.reply = params => Promise.resolve(window.receipt(params, 'settled', '2.0000'));
                window.context('7', 11);
            });
            await page.getByText('2.00', {exact: false}).waitFor();
            await page.evaluate(() => window.finish());
            assert(!(await page.locator('#app').innerText()).includes('99.00'));
            await page.evaluate(() => {window.reply = () => Promise.reject({response: {status: 401}});});
            await page.getByRole('button').click();
            await page.getByText(locale === 'en' ? 'CRM session expired. Sign in again.' : 'Sessão do CRM expirada. Entre novamente.').waitFor();
            assert(!(await page.locator('#app').innerText()).includes('2.00'));
            assert.deepEqual(errors, []);
            console.log(`${locale}: native Vue pending/settled, account+conversation switch, stale response and expired session passed`);
            await page.close();
        }
        console.log(`Chromium ${browser.version()}`);
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
