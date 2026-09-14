const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const feature = 'client/custom/modules/feature-document-pages/src/';
const shared = 'client/custom/modules/feature-knowledge-base-editor/src/';
const resource = file => JSON.parse(read('custom/Espo/Modules/FeatureDocumentPages/Resources/' + file));

function load(file, imports = {}, globals = {}) {
    const exports = {};
    const context = {
        exports, console,
        require(name) {
            assert.ok(Object.hasOwn(imports, name), `Missing test dependency ${name}`);
            return imports[name];
        },
        define(name, dependencies, factory) {
            exports.default = factory(...dependencies.map(name => imports[name].default));
        },
        ...globals,
    };
    const {outputText} = ts.transpileModule(read(file), {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS,
            experimentalDecorators: true, esModuleInterop: false,
        },
    });
    vm.runInNewContext(outputText, context, {filename: file});
    return exports.default;
}

function extend(props) {
    class Extended extends this {}
    Object.assign(Extended.prototype, props);
    Extended.extend = extend;
    return Extended;
}

const DynamicLogic = load('client/src/dynamic-logic.ts', {
    di: {inject: () => () => {}}, 'field-manager': {default: class {}},
});

test('native form conditions require uploads for files and expose editable page content', () => {
    const defs = resource('metadata/clientDefs/Document.json').dynamicLogic;
    for (const [attributes, fileRequired, fileVisible, bodyVisible] of [
        [{contentType: 'File'}, true, true, false],
        [{contentType: null}, true, true, false],
        [{contentType: 'Page'}, false, false, true],
        [{contentType: 'Page', fileId: 'retained-file'}, false, true, true],
        [{contentType: 'File', body: 'Retained content'}, true, true, true],
    ]) {
        const logic = new DynamicLogic(defs, {model: {
            has: key => Object.hasOwn(attributes, key), get: key => attributes[key],
        }});
        assert.equal(logic.checkConditionGroup(defs.fields.file.required.conditionGroup), fileRequired);
        assert.equal(logic.checkConditionGroup(defs.fields.file.visible.conditionGroup), fileVisible);
        assert.equal(logic.checkConditionGroup(defs.fields.body.visible.conditionGroup), bodyVisible);
    }
});

test('native create routing and drag-and-drop keep page/file types and the selected folder', async () => {
    const captures = [];
    const uploads = [];
    class MainView {}
    MainView.extend = extend;
    const NativeList = load('client/src/views/list.ts', {
        'views/main': {default: MainView},
        'helpers/record-modal': {default: class {
            async showCreate(view, options) {
                captures.push(options);
                return {getRecordView: () => ({getFieldView: field => {
                    assert.equal(field, 'file');
                    return {isRendered: () => true, uploadFile: file => uploads.push(file)};
                }})};
            }
        }},
        'search-manager': {default: class {}}, utils: {default: {}},
        'helpers/misc/pipelines': {default: class {}}, collection: {default: class {}}, ui: {default: {}},
        'views/modals/edit': {default: class {}},
    });
    const CategoryList = load('client/src/views/list-with-categories.ts', {
        'views/list': {default: NativeList}, 'collections/tree': {default: class {}},
        model: {default: class {}}, ui: {default: {}},
    });
    CategoryList.extend = extend;
    const NativeDocumentList = load('client/modules/crm/src/views/document/list.js', {
        'views/list-with-categories': {default: CategoryList},
    });
    const DocumentList = load(feature + 'views/document/list.js', {
        'crm:views/document/list': {default: NativeDocumentList},
    });
    const view = new DocumentList();
    Object.assign(view, {
        scope: 'Document', categoryField: 'folder', currentCategoryId: 'folder-1', currentCategoryName: 'Operations',
        getRouter: () => ({getCurrentUrl: () => '#Document', navigate() {}, dispatch(scope, action, options) {
            captures.push(options);
        }}),
    });
    view.actionCreate();
    assert.equal(captures[0].attributes.contentType, 'Page');
    assert.equal(captures[0].attributes.folderId, 'folder-1');
    assert.equal(captures[0].returnDispatchParams.options.categoryId, 'folder-1');

    await view.actionQuickCreate();
    assert.equal(captures[1].attributes.contentType, 'File');

    const NativeDropHandler = load('client/modules/crm/src/view-setup-handlers/document/record-list-drag-n-drop.js', {
        underscore: {default: require('underscore')}, bullbone: {Events: {}},
    });
    const file = {name: 'contract.pdf'};
    new NativeDropHandler(view).create(file);
    await new Promise(setImmediate);
    assert.equal(captures[2].attributes.contentType, 'File');
    assert.equal(captures[2].attributes.folderId, 'folder-1');
    assert.equal(uploads[0], file);
});

test('attachment picker retains file restriction through folder changes and search resets', () => {
    const CategoryModal = load('client/src/views/modals/select-records-with-categories.js', {
        'views/modals/select-records': {default: class {setupSearch() { this.collection.where = []; }}},
    });
    CategoryModal.extend = extend;
    const NativeDocumentModal = load('client/modules/crm/src/views/document/modals/select-records.js', {
        'views/modals/select-records-with-categories': {default: CategoryModal},
    });
    const SelectFiles = load(feature + 'views/document/modals/select-files.js', {
        'crm:views/document/modals/select-records': {default: NativeDocumentModal},
    });
    const view = new SelectFiles();
    Object.assign(view, {collection: {}, categoryField: 'folder', hasTextFilter: () => false});
    view.setupSearch();
    const check = () => {
        const where = view.collection.whereFunction();
        assert.ok(where.some(item => item.attribute === 'contentType' && item.value === 'File'));
        assert.ok(where.some(item => item.attribute === 'fileId' && item.type === 'isNotNull'));
        return where;
    };
    check();
    view.currentCategoryId = 'folder-2';
    view.isExpanded = false;
    view.applyCategoryToCollection();
    assert.ok(check().some(item => item.attribute === 'folderId' && item.value === 'folder-2'));
    view.collection.where = [];
    view.hasTextFilter = () => true;
    view.applyCategoryToCollection();
    check();
});

class BaseField {
    setup() {}
    data() { return {}; }
}
const SharedBody = load(shared + 'views/fields/lexical-body.js', {'views/fields/base': {default: BaseField}}, {
    window: {confirm: () => false},
    Espo: {loader: {requirePromise: async () => {}}},
});

test('page editor prefers canonical state and falls back to Markdown for body-only API updates', () => {
    const view = new SharedBody();
    const attrs = {bodyFormat: 'Markdown', body: '**Updated**', bodyEditorState: 'canonical'};
    const calls = [];
    Object.assign(view, {name: 'body', model: {get: key => attrs[key]}, kbEditor: {
        setEditorStateJSON: state => {calls.push(['state', state]); return true;},
        setMarkdown: body => calls.push(['markdown', body]),
    }});
    view.loadContentIntoEditor();
    assert.deepEqual(calls, [['state', 'canonical']]);
    attrs.bodyEditorState = null;
    view.loadContentIntoEditor();
    assert.deepEqual(calls[1], ['markdown', '**Updated**']);
});

test('saving source-mode changes imports source before persisting its editor snapshot', () => {
    const view = new SharedBody();
    let state;
    Object.assign(view, {
        name: 'body', sourceMode: true, isEditMode: () => true,
        model: {get: key => key === 'bodyFormat' ? 'Markdown' : null},
        $source: {val: () => '**Edited source**'},
        kbEditor: {
            setMarkdown: value => {state = JSON.stringify({source: value});},
            isEmpty: () => false, getEditorStateJSON: () => state,
        },
    });
    const saved = view.fetch();
    assert.equal(saved.body, '**Edited source**');
    assert.equal(JSON.parse(saved.bodyEditorState).source, saved.body);
    assert.equal(saved.bodyFormat, 'Markdown');
});

test('native PATCH generation keeps body, format and canonical state together', () => {
    const method = read('client/src/views/record/base.ts').match(
        /    protected getChangedAttributes\([\s\S]*?\n    \}/,
    );
    assert.ok(method, 'Locate the native patch dependency implementation');
    const {outputText} = ts.transpileModule(`class RecordView {${method[0]}\n}\nglobalThis.RecordView = RecordView;`, {
        compilerOptions: {target: ts.ScriptTarget.ES2022},
    });
    const context = {Utils: {areEqual: (left, right) => left === right}};
    vm.runInNewContext(outputText, context);
    const view = new context.RecordView();
    const initial = {name: 'Page', body: 'Content', bodyFormat: 'Markdown', bodyEditorState: 'original-state'};
    view.attributes = initial;
    view.forcePatchAttributeDependencyMap = resource('metadata/clientDefs/Document.json').forcePatchAttributeDependencyMap;
    for (const change of [
        {body: 'Updated'}, {bodyEditorState: 'formatting-only-state'}, {bodyFormat: 'Html'},
    ]) {
        const values = {...initial, ...change};
        view.model = {isNew: () => false, getClonedAttributes: () => values};
        const patch = view.getChangedAttributes(['body', 'bodyFormat', 'bodyEditorState']);
        assert.equal(patch.body, values.body);
        assert.equal(patch.bodyFormat, values.bodyFormat);
        assert.equal(patch.bodyEditorState, values.bodyEditorState);
        assert.equal(patch.name, undefined);
    }
    view.model = {isNew: () => false, getClonedAttributes: () => ({...initial, name: 'Renamed'})};
    assert.deepEqual(Object.keys(view.getChangedAttributes(['name'])), ['name']);
});

test('cancelling a format switch preserves unsaved visual and source edits', () => {
    for (const sourceMode of [false, true]) {
        const view = new SharedBody();
        const handlers = {};
        const attrs = {bodyFormat: 'Markdown', body: 'Saved old value'};
        Object.assign(view, {
            name: 'body', sourceMode, isEditMode: () => true, isRendered: () => true,
            model: {entityType: 'Document', get: key => attrs[key], set(key, value, options) {
                attrs[key] = value;
                handlers['change:bodyFormat'](this, value, options);
            }},
            kbEditor: {}, readProjectedBody: () => 'Unsaved edits',
            translate: key => key, wait() {}, addHandler() {},
            listenTo(model, event, callback) {handlers[event] = callback;},
            $el: {find: () => ({val: () => {}})},
            reRender: () => assert.fail('Cancelling must not rebuild the editor from saved content'),
        });
        view.setup();
        view.handleFormatSwitch('Markdown');
        assert.equal(attrs.bodyFormat, 'Html');
        assert.equal(view.readProjectedBody(), 'Unsaved edits');
    }
});
