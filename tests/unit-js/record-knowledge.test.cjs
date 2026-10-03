const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');
const root = path.resolve(__dirname, '../..');
function load(file, imports, globals = {}) {
    const exports = {};
    const code = ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), {
        compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, esModuleInterop: false},
    }).outputText;
    vm.runInNewContext(code, {exports, require: name => imports[name], ...globals});
    return exports.default;
}

test('Document Markdown source fetch does not trim or serialize an editor snapshot', () => {
    class Lexical { fetch() { throw new Error('Lossy editor path used.'); } }
    const Field = load('client/custom/modules/feature-document-pages/src/views/document/fields/body.js', {
        'feature-knowledge-base-editor:views/fields/lexical-body': {default: Lexical}, 'views/fields/base': {default: class {}},
        'feature-record-knowledge:content': {sourceValue: (textarea, original) => textarea.value === original.replace(/\r\n?/g, '\n') ? original : textarea.value},
    });
    const view = new Field();
    const source = '\n---\ntitle: source\n---\n<!-- comment -->\n| A | B |\n| - | - |\n  \n';
    Object.assign(view, {name: 'body', model: {get: name => ({bodyAuthoringMode: 'Markdown', body: source})[name]},
        isEditMode: () => true, isRendered: () => true, el: {querySelector: () => ({value: source})}});
    assert.equal(view.fetch().body, source);
    assert.equal(view.fetch().bodyEditorState, null);
    assert.equal(view.fetch().bodyFormat, 'Markdown');
});

test('shared setup uses metadata scopes for custom quick-edit containers and terminal pages', () => {
    const Handler = load('client/custom/modules/feature-record-knowledge/src/handlers/record-detail.js', {});
    const hidden = [];
    const view = {model: {id: 'project1', entityType: 'CustomProject', get: () => null}, bottomView: null,
        getMetadata: () => ({get: () => ['CustomProject', 'Document']}), hidePanel: name => hidden.push(name), listenTo() {}};
    new Handler(view).process();
    assert.equal(view.bottomView, 'views/record/edit-bottom');
    view.model.entityType = 'Document';
    view.model.get = () => 'CustomProject';
    new Handler(view).process();
    assert.deepEqual(hidden, ['overview']);
});

test('knowledge tabs initialize before parent attachment and wait for their panels in detail and edit modes', async () => {
    const bull = {};
    vm.runInNewContext(fs.readFileSync(require.resolve('bullbone/dist/bullbone.umd.js'), 'utf8'), {
        exports: bull, module: {exports: bull}, window: {}, setTimeout, clearTimeout,
        require: name => name === 'jquery' ? () => [] : require(name),
    });
    class BaseField extends bull.View {
        init() {
            this.mode = this.options.mode;
            this.recordHelper = this.options.recordHelper;
            this.readOnly = this.options.readOnly;
        }
        setup() { assert.equal(this.getParentView(), null); }
    }
    const Field = load('client/custom/modules/feature-record-knowledge/src/views/fields/knowledge-panel.js', {
        'views/fields/base': {default: BaseField},
    });
    const Handler = load('client/custom/modules/feature-record-knowledge/src/handlers/record-detail.js', {});
    const Converter = load('client/src/helpers/record/detail/layout-converter.js', {
        di: {inject: () => () => {}}, language: {default: class {}},
        'view-helper': {default: class {}}, 'field-manager': {default: class {}},
    });
    const panels = ['overview', 'relations'].map(name => ({name, label: name, view: `test:${name}`}));
    const model = {id: 'project1', entityType: 'CustomProject', get: () => null,
        getFieldType: () => null, getFieldParam: () => null};
    const recordHelper = {getFieldStateParam: () => null, hasFieldOptionList: () => false};
    const converter = new Converter();
    converter.language = {translate: key => key};

    for (const mode of ['detail', 'edit']) {
        const record = {model, bottomView: null,
            getMetadata: () => ({get: key => key === 'app.recordKnowledge.panels' ? panels : ['CustomProject']}),
            translate: key => key, hidePanel() {}, listenTo() {}, on() {}, selectTab() {},
            convertDetailLayout: layout => converter.convert(layout, {
                selector: '#record', entityType: model.entityType, model, recordHelper, fieldsMode: mode,
                readOnly: true, panelFieldListMap: {}, middlePanelDefs: {}, middlePanelDefsList: [],
                underShowMoreDetailPanelList: [], validateField: () => false,
            }),
        };
        new Handler(record).process();
        const layout = record.convertDetailLayout([
            {name: 'main', rows: []}, {name: 'extra', rows: [], tabBreak: true},
        ]);
        assert.deepEqual(Array.from(layout, panel => panel.name), ['main', 'overview', 'relations', 'extra']);
        assert.deepEqual(Array.from(layout, panel => panel.tabNumber), [0, 1, 1, 2]);
        let release;
        const pending = new Promise(resolve => {release = resolve;});
        let initialized = 0;
        class Panel extends bull.View {
            setup() {
                assert.equal(this.options.recordViewObject, record);
                assert.equal(this.options.model, model);
                assert.equal(this.options.recordHelper, recordHelper);
                assert.equal(this.options.mode, mode);
                assert.equal(this.options.readOnly, true);
                assert.equal(this.options.inlineEditDisabled, true);
                assert.equal(this.options.panelName, this.options.defs.name);
                initialized++;
                this.wait(pending);
            }
        }
        const factory = new bull.Factory({viewLoader: (name, callback) => callback(
            name.startsWith('test:') ? Panel : Field
        )});
        let ready = false;
        let resolveReady;
        const middleReady = new Promise(resolve => {resolveReady = resolve;});
        const middle = new bull.View({model, layoutDefs: {layout}});
        factory.prepare(middle, () => {ready = true; resolveReady();});
        assert.equal(initialized, 2);
        assert.equal(ready, false);
        release();
        await middleReady;
        for (const panel of panels) {
            const field = middle.getView(`${panel.name}KnowledgePanelField`);
            assert.equal(field.getParentView(), middle);
            assert.equal(field.getView('panel').getParentView(), field);
            assert.equal(Object.keys(field.fetch()).length, 0);
            assert.equal(field.getAttributeList().length, 0);
            assert.equal(field.validate(), false);
        }
    }
});

test('a quick-detail field switches to source authoring after its partial model is fetched', () => {
    class Lexical {
        editTemplateContent = '<div class="lexical-editor"></div>';
        setup() {} prepareRender() {}
    }
    const Field = load('client/custom/modules/feature-document-pages/src/views/document/fields/body.js', {
        'feature-knowledge-base-editor:views/fields/lexical-body': {default: Lexical}, 'views/fields/base': {default: class {}},
        'feature-record-knowledge:content': {},
    });
    const view = new Field();
    const attrs = {};
    let template;
    Object.assign(view, {model: {get: key => attrs[key]}, isEditMode: () => true, addHandler() {}, setTemplateContent: value => {template = value;}});
    view.setup();
    attrs.bodyAuthoringMode = 'Markdown';
    view.prepareRender();
    assert.match(template, /data-name="markdownSource"/);
    attrs.bodyAuthoringMode = 'Lexical';
    view.prepareRender();
    assert.match(template, /lexical-editor/);
});

test('late registry responses cannot replace the currently selected tenant schema', async () => {
    const pending = [];
    const Panel = load('client/custom/modules/feature-record-knowledge/src/views/record/panels/relations.js', {
        'views/record/panels/bottom': {default: class {}},
        'feature-record-knowledge:content': {identity: model => ({recordType: model.entityType, recordId: model.id})},
    }, {Espo: {Ajax: {getRequest: (url, parameters) => new Promise(resolve => pending.push({parameters, resolve}))}}});
    const view = new Panel();
    Object.assign(view, {model: {entityType: 'CustomProject', id: 'project'}, authorAllowed: true, tenantId: 'tenantA',
        isRendered: () => false, isRemoved: () => false});
    const first = view.loadSchema();
    view.tenantId = 'tenantB';
    const second = view.loadSchema();
    pending[1].resolve({tenantId: 'tenantB', predicates: {'tenant:tenantB:advises': {label: 'B'}}});
    await second;
    pending[0].resolve({tenantId: 'tenantA', predicates: {'tenant:tenantA:advises': {label: 'A'}}});
    await first;
    assert.equal(view.tenantId, 'tenantB');
    assert.deepEqual(Object.keys(view.schema), ['tenant:tenantB:advises']);
});
