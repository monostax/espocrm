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
    await page.addStyleTag({path: path.join(root, 'client/custom/modules/feature-knowledge-base-editor/css/kb-lexical.css')});
    await page.addScriptTag({path: path.join(root, 'client/custom/modules/feature-knowledge-base-editor/lib/lexical-kb-bundle.js')});
    await page.addScriptTag({path: path.join(root, 'node_modules/jquery/dist/jquery.js')});
    await page.addScriptTag({path: path.join(root, 'node_modules/handlebars/dist/handlebars.js')});
    await page.addScriptTag({path: path.join(root, 'node_modules/dompurify/dist/purify.js')});
    await page.evaluate(async ({content, modal, sharedBody, documentBody}) => {
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
            wait(promise) { this.pending = promise; }
            getModelFactory() { return {create: async entityType => {
                const attributes = {};
                return {entityType, get: key => attributes[key], set: values => Object.assign(attributes, values)};
            }}; }
            async createView(name, viewName, options) {
                const field = new modules[viewName].default();
                Object.assign(field, {model: options.model, name: options.defs.name, params: options.defs.params, cid: 'field', handlers: []});
                field.setup(); await field.pending;
                this.field = field;
            }
            getView() { return this.field; }
            addHandler(type, selector, handler) { this.el.addEventListener(type, event => {
                const target = event.target.closest(selector); if (target) handler(event, target);
            }); }
            translate(key) { return key; }
            getHelper() { return {sanitizeHtml: html => DOMPurify.sanitize(html)}; }
            isRemoved() { return this.closed || false; }
            disableButton() { document.querySelector('[data-name="save"]').disabled = true; }
            enableButton() { document.querySelector('[data-name="save"]').disabled = false; }
            trigger(event) { this.saved = event === 'saved'; }
            close() { this.field?.onRemove(); this.closed = true; }
            render() {
                this.el.innerHTML = Handlebars.compile(this.templateContent)(this.data()) + '<button data-name="save">Save</button>';
                this.el.querySelector('[data-name="save"]').addEventListener('click', () => this.actionSave());
                if (this.field) {
                    const field = this.field;
                    field.el = this.el.querySelector('.field'); field.$el = $(field.el);
                    field.prepareRender();
                    field.el.innerHTML = Handlebars.compile(field.templateContent)(field.data());
                    for (const [type, selector, handler] of field.handlers) field.el.addEventListener(type, event => {
                        const target = event.target.closest(selector); if (target) handler(event, target);
                    });
                    field.afterRender();
                }
                this.afterRender();
            }
        }
        class BaseField {
            setup() {} prepareRender() {} afterRender() {} onRemove() {} on() {} listenTo() {} trigger() {}
            wait(promise) { this.pending = promise; }
            addHandler(...handler) { this.handlers.push(handler); }
            setTemplateContent(value) { this.templateContent = value; }
            isEditMode() { return true; } isDetailMode() { return false; } isListMode() { return false; }
            isRendered() { return !!this.el; }
            data() { return {name: this.name}; }
            translate(key) { return key; }
            getHelper() { return {sanitizeHtml: html => DOMPurify.sanitize(html)}; }
            getLanguage() { return {name: 'en-US', translateOption: key => key}; }
        }
        window.TestModal = Modal;
        window.Espo = {loader: {requirePromise: async () => {}}, Ajax: {
            async putRequest(url, data, options) {
                writes.push({url, data, options});
                if (window.conflict) throw {status: 409};
                return {};
            },
            async postRequest(url, data) {
                if (url === 'EditorReference/resolve') return {list: data.references.map(ref => ({...ref, available: true, label: 'Resolved project'}))};
                return {html: '<h1>Preview</h1><a href="#crm-reference/v1/record/CustomProject/project1">Old name</a><img src="x" onerror="window.injected=true"><script>window.injected=true</script>'};
            },
            async getRequest() { return {list: [{kind: 'record', entityType: 'CustomProject', recordId: 'project1', label: 'Resolved project'}]}; },
        }};
        const modules = {'views/modal': {default: Modal}, 'views/fields/base': {default: BaseField}};
        const exports = {};
        new Function('require', 'exports', content)(name => modules[name], exports);
        modules['feature-record-knowledge:content'] = exports;
        for (const [name, code] of [
            ['feature-knowledge-base-editor:views/fields/lexical-body', sharedBody],
            ['feature-document-pages:views/document/fields/body', documentBody],
        ]) {
            const exports = {};
            new Function('require', 'exports', code)(name => modules[name], exports);
            modules[name] = exports;
        }
        const modalExports = {};
        new Function('require', 'exports', modal)(name => modules[name], modalExports);
        window.editor = new modalExports.default({parentModel, document: {documentId: 'document1', name: 'Overview', body: source, versionNumber: 7}});
        editor.setup(); await editor.pending; editor.render();
    }, {content: compile('client/custom/modules/feature-record-knowledge/src/content.js'),
        modal: compile('client/custom/modules/feature-record-knowledge/src/views/modals/editor.js'),
        sharedBody: compile('client/custom/modules/feature-knowledge-base-editor/src/views/fields/lexical-body.js'),
        documentBody: compile('client/custom/modules/feature-document-pages/src/views/document/fields/body.js')});
    return page;
}

for (const viewport of [{width: 1280, height: 900}, {width: 375, height: 812}]) {
    test(`Notion overview preserves unchanged Markdown and uses an independent versioned save at ${viewport.width}px`, async t => {
        const page = await fixture(t, viewport);
        await page.locator('.kb-notion-plugins').waitFor({state: 'attached'});
        assert.ok(await page.evaluate(() => document.querySelector('.kb-lexical-editor').getBoundingClientRect().right <= innerWidth));
        assert.equal(await page.locator('[data-name="bodyFormatInline"]').count(), 0);
        await page.locator('.kb-mention').filter({hasText: 'Resolved project'}).waitFor();
        assert.equal(await page.evaluate(() => window.injected || false), false);
        await page.locator('[data-name="save"]').click();
        await page.waitForFunction(() => editor.saved);
        const saved = await page.evaluate(() => ({write: writes[0], source}));
        assert.equal(saved.write.data.body, saved.source);
        assert.equal(saved.write.data.bodyEditorState, null);
        assert.equal(saved.write.options.headers['X-Version-Number'], 7);
        assert.match(saved.write.url, /^RecordKnowledge\/overview\?recordType=CustomProject&recordId=project1$/);
    });
}

test('a conflict preserves unsaved rich content and leaves the editor open', async t => {
    const page = await fixture(t, {width: 800, height: 700});
    await page.evaluate(() => { editor.field.kbEditor.setMarkdown('## Unsaved edits'); window.conflict = true; });
    await page.locator('[data-name="save"]').click();
    await page.locator('[role="alert"]').filter({hasText: 'knowledgeConflict'}).waitFor();
    assert.equal(await page.locator('.kb-lexical-editor h2').textContent(), 'Unsaved edits');
    assert.equal(await page.evaluate(() => editor.closed || false), false);
    assert.equal(await page.locator('[data-name="save"]').isEnabled(), true);
});

test('unchanged saves retain original CRLF Markdown bytes', async t => {
    const page = await fixture(t, {width: 800, height: 700});
    const original = '\r\n---\r\ntitle: CRLF\r\n---\r\n\r\nSource  \r\n';
    await page.evaluate(original => {
        editor.field.model.set({body: original});
        editor.field.loadContentIntoEditor();
    }, original);
    await page.locator('[data-name="save"]').click();
    await page.waitForFunction(() => editor.saved);
    assert.equal(await page.evaluate(() => writes[0].data.body), original);
});

test('overview slash blocks and references save Markdown plus canonical JSON and reopen without flattening', async t => {
    const page = await fixture(t, {width: 375, height: 812});
    await page.evaluate(() => editor.field.kbEditor.setMarkdown(''));
    await page.locator('.kb-lexical-editor').click();
    await page.keyboard.type('/heading 2');
    await page.getByRole('option').first().waitFor();
    await page.keyboard.press('Enter');
    await page.keyboard.type('Rich overview');
    await page.keyboard.press('Enter');
    await page.keyboard.type('@Project');
    await page.getByRole('option').filter({hasText: 'Resolved project'}).waitFor();
    await page.keyboard.press('Enter');
    await page.locator('[data-name="save"]').click();
    await page.waitForFunction(() => editor.saved);
    const write = await page.evaluate(() => writes[0]);
    assert.match(write.data.body, /## Rich overview/);
    assert.match(write.data.body, /#crm-reference\/v1\/record\/CustomProject\/project1/);
    const state = JSON.parse(write.data.bodyEditorState);
    assert.equal(state.root.children[0].type, 'heading');
    assert.equal(state.root.children[1].children[0].type, 'crm-mention');
    await page.evaluate(async () => {
        editor.document = {...editor.document, ...writes[0].data, bodyAuthoringMode: 'Lexical'};
        await editor.loadEditor(); editor.render();
    });
    assert.equal(await page.locator('.kb-lexical-editor h2').textContent(), 'Rich overview');
    await page.locator('.kb-mention').filter({hasText: 'Resolved project'}).waitFor();
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
