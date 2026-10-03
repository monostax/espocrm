const {test, before, after} = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const ts = require('typescript');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const compile = file => ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), {
    compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, esModuleInterop: false},
}).outputText;
let browser;
before(async () => { browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH}); });
after(async () => { await browser?.close(); });

async function fixture(t, viewport) {
    const page = await browser.newPage({viewport});
    t.after(() => page.close());
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    t.after(() => assert.deepEqual(errors, []));
    await page.setContent('<style>body{margin:10px}.dialog{max-width:800px;width:100%}.form-control{box-sizing:border-box;width:100%}</style><div class="dialog"></div>');
    await page.addScriptTag({path: path.join(root, 'node_modules/handlebars/dist/handlebars.js')});
    await page.addScriptTag({path: path.join(root, 'node_modules/dompurify/dist/purify.js')});
    await page.evaluate(({content, modal}) => {
        Handlebars.registerHelper('translate', key => key);
        window.source = '\n\n---\ntitle: Test\n---\n<!-- retain -->\n| A | B |\n| - | - |\n| x | y |\n\n[Project](#crm-reference/v1/record/CustomProject/project1)  \n\n';
        window.writes = [];
        window.parentModel = {entityType: 'CustomProject', id: 'project1',
            set() { throw new Error('Parent model mutated.'); }, save() { throw new Error('Parent model saved.'); }};
        class Modal {
            constructor(options) { this.options = options; this.cid = 'test'; this.el = document.querySelector('.dialog'); }
            setup() {}
            afterRender() {}
            data() { return {}; }
            addHandler(type, selector, handler) { this.el.addEventListener(type, event => {
                const target = event.target.closest(selector); if (target) handler(event, target);
            }); }
            translate(key) { return key; }
            getHelper() { return {sanitizeHtml: html => DOMPurify.sanitize(html)}; }
            isRemoved() { return this.closed || false; }
            disableButton() { document.querySelector('[data-name="save"]').disabled = true; }
            enableButton() { document.querySelector('[data-name="save"]').disabled = false; }
            trigger(event) { this.saved = event === 'saved'; }
            close() { this.closed = true; }
            render() {
                this.el.innerHTML = Handlebars.compile(this.templateContent)(this.data()) + '<button data-name="save">Save</button>';
                this.el.querySelector('[data-name="save"]').addEventListener('click', () => this.actionSave());
                this.afterRender();
            }
        }
        window.TestModal = Modal;
        window.Espo = {Ajax: {
            async putRequest(url, data, options) {
                writes.push({url, data, options});
                if (window.conflict) throw {status: 409};
                return {};
            },
            async postRequest(url, data) {
                if (url === 'EditorReference/resolve') return {list: data.references.map(ref => ({...ref, available: true, label: 'Resolved project'}))};
                return {html: '<h1>Preview</h1><a href="#crm-reference/v1/record/CustomProject/project1">Old name</a><img src="x" onerror="window.injected=true"><script>window.injected=true</script>'};
            },
        }};
        const modules = {'views/modal': {default: Modal}};
        const exports = {};
        new Function('require', 'exports', content)(name => modules[name], exports);
        modules['feature-record-knowledge:content'] = exports;
        const modalExports = {};
        new Function('require', 'exports', modal)(name => modules[name], modalExports);
        window.editor = new modalExports.default({parentModel, document: {name: 'Overview', body: source, versionNumber: 7}});
        editor.setup(); editor.render();
    }, {content: compile('client/custom/modules/feature-record-knowledge/src/content.js'),
        modal: compile('client/custom/modules/feature-record-knowledge/src/views/modals/markdown.js')});
    return page;
}

for (const viewport of [{width: 1280, height: 900}, {width: 375, height: 812}]) {
    test(`source-first overview preserves Markdown and uses an independent versioned save at ${viewport.width}px`, async t => {
        const page = await fixture(t, viewport);
        assert.equal(await page.locator('textarea').inputValue(), await page.evaluate(() => source));
        assert.ok(await page.evaluate(() => document.querySelector('textarea').getBoundingClientRect().right <= innerWidth));
        await page.locator('[data-action="preview"]').click();
        await page.getByRole('link', {name: 'Resolved project'}).waitFor();
        assert.equal(await page.evaluate(() => window.injected || false), false);
        await page.locator('[data-name="save"]').click();
        await page.waitForFunction(() => editor.saved);
        const saved = await page.evaluate(() => ({write: writes[0], source}));
        assert.equal(saved.write.data.body, saved.source);
        assert.equal(saved.write.options.headers['X-Version-Number'], 7);
        assert.match(saved.write.url, /^RecordKnowledge\/overview\?recordType=CustomProject&recordId=project1$/);
    });
}

test('a conflict preserves unsaved Markdown and leaves the editor open', async t => {
    const page = await fixture(t, {width: 800, height: 700});
    await page.locator('textarea').fill('\nUnsaved edits  \n\n');
    await page.evaluate(() => { window.conflict = true; });
    await page.locator('[data-name="save"]').click();
    await page.locator('[role="alert"]').filter({hasText: 'knowledgeConflict'}).waitFor();
    assert.equal(await page.locator('textarea').inputValue(), '\nUnsaved edits  \n\n');
    assert.equal(await page.evaluate(() => editor.closed || false), false);
    assert.equal(await page.locator('[data-name="save"]').isEnabled(), true);
});

test('unchanged saves retain original CRLF Markdown bytes', async t => {
    const page = await fixture(t, {width: 800, height: 700});
    const original = '\r\n---\r\ntitle: CRLF\r\n---\r\n\r\nSource  \r\n';
    await page.evaluate(original => {
        editor.source = original;
        editor.document.body = original;
        editor.el.querySelector('textarea').value = original;
    }, original);
    await page.locator('[data-name="save"]').click();
    await page.waitForFunction(() => editor.saved);
    assert.equal(await page.evaluate(() => writes[0].data.body), original);
});

test('referenced predicate editor locks identity and semantics while versioning label/alias updates', async t => {
    const page = await fixture(t, {width: 375, height: 812});
    await page.evaluate(code => {
        const exports = {};
        new Function('require', 'exports', code)(name => ({default: TestModal}), exports);
        window.predicateEditor = new exports.default({tenantId: 'tenantA', scopes: ['CustomProject', 'Account'], predicate: {
            id: 'predicate1', code: 'advises', label: 'Advises', inverse: 'Advised by', aliases: ['guides'], active: true,
            subjects: ['CustomProject'], objects: ['Account'], referenced: true, versionNumber: 4,
            qualifierSchema: {type: 'object', properties: {}, required: [], additionalProperties: false},
        }});
        predicateEditor.setup(); predicateEditor.render();
    }, compile('client/custom/modules/feature-record-knowledge/src/views/modals/predicate.js'));
    assert.equal(await page.locator('[data-name="code"]').isDisabled(), true);
    assert.equal(await page.locator('[data-name="subjects"]').isDisabled(), true);
    assert.equal(await page.locator('[data-name="objects"]').isDisabled(), true);
    assert.equal(await page.locator('[data-name="schema"]').getAttribute('readonly'), '');
    await page.locator('[data-name="name"]').fill('Advises customer');
    await page.locator('[data-name="aliases"]').fill('guides, consults');
    await page.locator('[data-name="save"]').click();
    await page.waitForFunction(() => predicateEditor.saved);
    const write = await page.evaluate(() => writes[0]);
    assert.equal(write.url, 'RecordPredicate/predicate1');
    assert.equal(write.options.headers['X-Version-Number'], 4);
    assert.equal(write.data.name, 'Advises customer');
    assert.deepEqual(write.data.aliases, ['guides', 'consults']);
    for (const key of ['code', 'tenantId', 'subjectTypes', 'objectTypes', 'qualifierSchema']) assert.equal(Object.hasOwn(write.data, key), false);
});

test('predicate management hides write actions for ordinary tenant users and renders built-ins read-only', async t => {
    const page = await fixture(t, {width: 900, height: 800});
    await page.evaluate(async code => {
        class Main {
            constructor() { this.el = document.querySelector('.dialog'); }
            setup() {} data() { return {}; } afterRender() {} addHandler() {}
            wait(promise) { this.pending = promise; }
            getAcl() { return {checkScope: () => false}; }
            isRendered() { return false; }
            render() { this.el.innerHTML = Handlebars.compile(this.templateContent)(this.data()); this.afterRender(); }
        }
        Espo.Ajax.getRequest = async url => {
            if (url === 'RecordPredicate/contexts') return {list: [{id: 'tenantA', name: 'Tenant A', editable: false}]};
            if (url === 'RecordKnowledge/schema') return {supportedScopes: ['CustomProject', 'Account']};
            return {predicates: {
                'builtin:part_of': {key: 'builtin:part_of', label: 'Part of', inverse: 'Contains', subjects: '*', objects: '*', builtin: true, qualifierSchema: {}},
                'tenant:tenantA:advises': {id: 'predicate1', key: 'tenant:tenantA:advises', label: 'Advises', inverse: 'Advised by', subjects: ['CustomProject'], objects: ['Account'], builtin: false, active: true, qualifierSchema: {}},
            }};
        };
        const exports = {};
        new Function('require', 'exports', code)(name => ({default: Main}), exports);
        const view = new exports.default(); view.setup(); await view.pending; view.render();
    }, compile('client/custom/modules/feature-record-knowledge/src/views/predicates.js'));
    assert.equal(await page.locator('[data-action="create"], [data-action="edit"], [data-action="delete"]').count(), 0);
    await page.getByText('Platform · read-only').waitFor();
    await page.getByText('tenant:tenantA:advises', {exact: true}).waitFor();
});
