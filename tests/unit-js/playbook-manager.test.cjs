const assert = require('node:assert/strict');
const {readFileSync, mkdtempSync, readdirSync, rmSync} = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const test = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');
const handlebars = require('handlebars');
const {Transpiler} = require('espo-frontend-build-tools');

const root = path.resolve(__dirname, '../..');
const client = 'client/custom/modules/feature-playbook/';
const resources = 'custom/Espo/Modules/FeaturePlaybook/Resources/';
const read = file => readFileSync(path.join(root, file), 'utf8');
const json = file => JSON.parse(read(file));
const labels = json(resources + 'i18n/en_US/Playbook.json').labels;

function load(file, imports, globals = {}) {
    return loadSource(read(client + file), imports, globals);
}

function loadSource(source, imports, globals = {}) {
    const exports = {};
    const {outputText} = ts.transpileModule(source, {
        compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS},
    });
    vm.runInNewContext(outputText, {exports, require: key => imports[key], URLSearchParams, ...globals});
    return exports.default;
}

test('both configuration links resolve to a registered custom controller with opportunity access', () => {
    const items = json(resources + 'metadata/app/adminForUserPanel.json').automation.sections.playbooks.itemList;
    assert.deepEqual(items.map(item => item.url), ['#PlaybookManager/index/view=templates', '#PlaybookManager/index/view=runs']);
    assert.equal(json(resources + 'metadata/scopes/PlaybookManager.json').entity, false);
    assert.equal(json(resources + 'metadata/clientDefs/PlaybookManager.json').controller, 'feature-playbook:controllers/manager');
    const Controller = load('src/controllers/manager.js', {controller: class {}});
    const controller = new Controller();
    controller.getAcl = () => ({check: (scope, action) => scope === 'Opportunity' && action === 'read'});
    assert.equal(controller.checkAccessGlobal(), true);
    controller.getAcl = () => ({check: () => false});
    assert.equal(controller.checkAccessGlobal(), false);
    controller.main = (view, options) => {
        assert.equal(view, 'feature-playbook:views/manager');
        assert.equal(options.params.view, 'runs');
    };
    controller.actionIndex({view: 'runs'});
});

test('switching opportunities ignores a previous slow template/run response', async () => {
    let resolveOld;
    const oldRequest = new Promise(resolve => { resolveOld = resolve; });
    const requested = [];
    const Manager = load('src/views/manager.js', {'views/main': class {}}, {
        Espo: {Ajax: {getRequest: url => {
            requested.push(url);
            return url.includes('/old/') ? oldRequest : Promise.resolve({templates: [{id: 'new-template'}], runs: []});
        }}},
    });
    const manager = new Manager();
    manager.version = 0;
    manager.opportunityId = 'old';
    manager.clearView = () => {};
    manager.reRender = async () => {};
    manager.getCollectionFactory = () => ({create: async () => ({reset() {}, length: 1})});
    manager.listenTo = () => {};
    manager.getModelFactory = () => ({create: async () => ({fetch: async () => {}, get: () => 'Opportunity'})});
    const first = manager.load(false);
    await new Promise(resolve => setImmediate(resolve));
    manager.opportunityId = 'new';
    await manager.load(false);
    resolveOld({templates: [{id: 'old-template'}], runs: []});
    await first;
    assert.equal(manager.snapshot.templates[0].id, 'new-template');
    assert.equal(manager.opportunity.id, 'new');
    assert.equal(manager.loading, false);
    assert.deepEqual(requested, ['Opportunity/old/playbooks', 'Opportunity/new/playbooks']);
});

test('management and editor templates escape user input and respect capabilities', () => {
    handlebars.registerHelper('translate', key => labels[key] || key);
    const page = handlebars.compile(read(client + 'res/templates/manager.tpl'));
    const context = {hasContext: true, tabTemplates: true, opportunityId: 'deal',
        opportunityName: '<script>bad()</script>'};
    const html = page(context);
    assert.ok(!html.includes('<script>'));
    assert.match(html, /&lt;script&gt;/);
    assert.ok(!html.includes('data-manager-action="new"'));
    assert.ok(!html.includes('data-manager-template='));
    const editable = page({...context, canCreate: true});
    assert.match(editable, /data-manager-action="new"/);
    assert.match(editable, /data-manager-templates/);
    const editor = handlebars.compile(read(client + 'res/templates/template-editor.tpl'));
    assert.ok(!editor({name: '<script>bad()</script>', steps: [{name: '" autofocus', instructions: '</textarea><script>bad()</script>', references: []}]}).includes('<script>'));
    assert.deepEqual(Object.keys(labels).sort(), Object.keys(json(resources + 'i18n/pt_BR/Playbook.json').labels).sort());
});

test('production transpilation emits resolvable manager, editor and execution panel modules', () => {
    const dir = mkdtempSync(path.join(os.tmpdir(), 'playbook-amd-'));
    try {
        new Transpiler({path: path.resolve(root, client), destDir: dir}).process();
        const definitions = new Map();
        const modules = new Map([
            ['views/main', class {}], ['views/modal', class {}], ['controller', class {}],
            ['views/fields/varchar', class {}], ['views/fields/base', class {}],
            ['views/list', class {}], ['collection', class {}],
            ['views/record/panels/side', {extend: definition => definition}],
        ]);
        for (const file of readdirSync(dir, {recursive: true}).filter(file => file.endsWith('.js'))) {
            vm.runInNewContext(readFileSync(path.join(dir, file), 'utf8'), {
                define(id, deps, factory) {
                    definitions.set(id.startsWith('feature-playbook:') ? id : 'feature-playbook:' + id, {deps, factory});
                },
            });
        }
        const resolve = id => {
            if (modules.has(id)) return modules.get(id);
            const definition = definitions.get(id);
            assert.ok(definition, `Missing built module: ${id}; available: ${[...definitions.keys()].join(', ')}`);
            const exports = {};
            const result = definition.factory(...definition.deps.map(dependency => dependency === 'exports' ? exports : resolve(dependency)));
            const value = result || exports.default || exports;
            modules.set(id, value);
            return value;
        };
        for (const id of definitions.keys()) resolve(id);
        assert.equal(typeof resolve('feature-playbook:controllers/manager').prototype.actionIndex, 'function');
        assert.equal(typeof resolve('feature-playbook:views/template-editor').prototype.actionSave, 'function');
        assert.equal(typeof resolve('feature-playbook:views/opportunity/panels/playbooks').mutate, 'function');
    } finally {
        rmSync(dir, {recursive: true, force: true});
    }
});

test('template editor submits scoped revision and ordered definitions using the native DOM element', async () => {
    let submitted;
    const Editor = load('src/views/template-editor.js', {'views/modal': class {}}, {
        Espo: {Ajax: {postRequest: async (url, body) => { submitted = {url, body}; }}},
    });
    const editor = new Editor();
    editor.options = {opportunityId: 'selected-deal'};
    editor.draft = {id: 'template', expectedRevision: 3};
    const values = {name: 'Qualification', status: 'Published', stepName: 'Call owner', kind: 'Task', instructions: 'Ask about timing', references: 'https://example.com/guide\n\n'};
    const form = {
        reportValidity: () => true,
        elements: {namedItem: key => ({value: values[key]})},
        querySelectorAll: () => [{querySelector: selector => ({value: values[selector.match(/name="(.*?)"/)[1]]})}],
    };
    editor.element = {querySelector: () => form};
    editor.$el = {find: () => ({prop: () => {}})};
    editor.disableButton = editor.enableButton = () => {};
    const events = [];
    editor.trigger = event => events.push(event);
    editor.close = () => events.push('close');
    await editor.actionSave();
    assert.equal(submitted.url, 'Opportunity/selected-deal/playbooks/templates');
    assert.equal(submitted.body.expectedRevision, 3);
    assert.equal(submitted.body.steps[0].kind, 'Task');
    assert.equal(submitted.body.steps[0].references.join(','), 'https://example.com/guide');
    assert.deepEqual(events, ['saved', 'close']);
    editor.options = {accountId: '6'};
    await editor.actionSave();
    assert.equal(submitted.url, 'PlaybookWorkspace/6/templates');
});

test('workspace routes create native collections with the account and optional opportunity scope', async () => {
    const Controller = load('src/controllers/manager.js', {controller: class {}});
    const controller = new Controller();
    controller.collectionFactory = {create: async scope => ({entityType: scope})};
    controller.main = (view, options) => {
        assert.equal(view, 'feature-playbook:views/workspace');
        assert.equal(options.params.accountId, '6');
        assert.equal(options.params.view, 'runs');
        assert.equal(options.scope, 'PlaybookRun');
        assert.equal(options.collection.url, 'PlaybookWorkspace/6');
        assert.equal(options.collection.data.view, 'runs');
        assert.equal(options.collection.data.opportunityId, 'deal');
    };
    await controller.actionIndex({accountId: '6', view: 'runs', opportunityId: 'deal'});
});

test('switching list routes ignores stale collection creation', async () => {
    let resolveOld;
    const old = new Promise(resolve => { resolveOld = resolve; });
    const Controller = load('src/controllers/manager.js', {controller: class {}});
    const controller = new Controller();
    controller.collectionFactory = {create: scope => scope === 'Playbook' ? old : Promise.resolve({entityType: scope})};
    const opened = [];
    controller.main = (view, options) => opened.push(options.scope);
    const first = controller.actionIndex({accountId: '6', view: 'templates'});
    await controller.actionIndex({accountId: '6', view: 'runs'});
    resolveOld({entityType: 'Playbook'});
    await first;
    assert.deepEqual(opened, ['PlaybookRun']);
});

test('native list name fields escape values and respect capabilities and opportunity links', () => {
    handlebars.registerHelper('translate', key => labels[key] || key);
    const name = handlebars.compile(read(client + 'res/templates/fields/name.tpl'));
    const row = {value: '<script>bad()</script>', canEdit: false};
    const html = name(row);
    assert.ok(!html.includes('<script>'));
    assert.match(html, /&lt;script&gt;/);
    assert.ok(!html.includes('data-edit-template'));
    assert.match(name({...row, canEdit: true}), /data-edit-template/);
    const runs = name({...row, opportunityUrl: '#Opportunity/view/deal'});
    assert.match(runs, /href="#Opportunity\/view\/deal"/);
    assert.ok(!runs.includes('data-edit-template'));
});

test('workspace inherits the full native list page, header, search and record-list lifecycle', () => {
    const NativeList = loadSource(read('client/src/views/list.ts'), {'views/main': class {}});
    const Workspace = load('src/views/workspace.js', {'views/list': NativeList});
    const view = new Workspace();
    assert.equal(view.template, 'list');
    assert.equal(view.headerView, 'views/header');
    assert.equal(view.searchView, 'views/record/search');
    assert.equal(view.recordView, 'views/record/list');
    assert.equal(view.afterRender, NativeList.prototype.afterRender);
    const options = {};
    view.prepareRecordViewOptions(options);
    assert.equal(options.pagination, true);
    assert.equal(options.inlineEditDisabled, true);
    assert.equal(options.rowActionsDisabled, true);
    const page = handlebars.compile(read(`client/res/templates/${view.template}.tpl`));
    const html = page({header: 'Native header', search: 'Native search', list: 'Native list'});
    assert.match(html, /class="page-header">Native header/);
    assert.match(html, /class="search-container">Native search/);
    assert.match(html, /class="list-container">Native list/);
    assert.doesNotMatch(html, /data-workspace|form-inline|panel-default/);
});

test('workspace collection carries server create capabilities through native pagination responses', () => {
    const Collection = load('src/collections/workspace.js', {collection: class {
        prepareAttributes(response) { this.total = response.total; return response.list; }
    }});
    const collection = new Collection();
    const rows = [{id: 'one'}];
    assert.equal(collection.prepareAttributes({list: rows, total: -1, canCreate: true}), rows);
    assert.equal(collection.canCreate, true);
    assert.equal(collection.total, -1);
    collection.prepareAttributes({list: [], total: 0, canCreate: false});
    assert.equal(collection.canCreate, false);
});

test('template creation uses server capability instead of generic CRUD', async () => {
    let requests = 0;
    const Workspace = load('src/views/workspace.js', {'views/list': class {}}, {
        Espo: {Ajax: {getRequest() { requests++; }}},
    });
    const view = new Workspace();
    view.collection = {canCreate: false};
    await view.actionCreate();
    assert.equal(requests, 0);
    view.collection.canCreate = true;
    view.accountId = '6';
    view.listenToOnce = () => {};
    view.createView = async (name, type, options) => {
        assert.equal(type, 'feature-playbook:views/template-editor');
        assert.equal(options.accountId, '6');
        return {render() { requests++; }};
    };
    await view.actionCreate();
    assert.equal(requests, 1);
});

test('editing existing templates never overwrites Bullbone’s reserved template option', async () => {
    const template = {
        id: 'template-1', name: 'Follow-up', status: 'Draft', revision: 3, canEdit: true,
        steps: [{name: 'Call', kind: 'Task', instructions: '', references: []}],
    };
    const globals = {Espo: {
        Ajax: {getRequest: async () => template},
        Utils: {cloneDeep: structuredClone},
    }};
    const Editor = load('src/views/template-editor.js', {'views/modal': class {setup() {}}}, globals);
    handlebars.registerHelper('translate', key => labels[key] || key);

    for (const entry of ['workspace', 'manager']) {
        const Parent = load(`src/views/${entry}.js`, {'views/list': class {}, 'views/main': class {}}, globals);
        const parent = new Parent();
        parent.accountId = '6';
        parent.opportunityId = 'deal';
        parent.collection = {canCreate: true};
        parent.snapshot = {canCreateTemplate: true};
        parent.listenToOnce = () => {};
        let rendered = false;
        parent.createView = async (key, viewName, options) => {
            assert.equal(viewName, 'feature-playbook:views/template-editor');
            assert.equal(Object.hasOwn(options, 'template'), false);
            assert.equal(options.playbookTemplate, template);
            const editor = new Editor();
            editor.options = options;
            editor.translate = key => labels[key] || key;
            editor.setup();
            // Bullbone applies this reserved override after setup, before loading the template.
            editor.template = options.template || editor.template;
            editor.render = () => {
                assert.equal(editor.template, 'feature-playbook:template-editor');
                const html = handlebars.compile(read(client + 'res/templates/template-editor.tpl'))(editor.data());
                assert.match(html, /Follow-up/);
                assert.match(html, /Call/);
                assert.equal(editor.draft.expectedRevision, 3);
                editor.draft.steps[0].name = 'Changed';
                assert.equal(template.steps[0].name, 'Call');
                rendered = true;
            };
            return editor;
        };
        await parent.editTemplate(entry === 'workspace' ? template.id : template);
        assert.equal(rendered, true, entry);
    }
});

test('completion field keeps the original total as denominator and supports empty runs', () => {
    const Completion = load('src/views/fields/completion.js', {'views/fields/base': class {data() { return {}; }}});
    const field = new Completion();
    const progress = handlebars.compile(read(client + 'res/templates/fields/completion.tpl'));
    field.model = {get: key => ({completed: 1, total: 3, skipped: 2})[key]};
    assert.match(progress(field.data()), /<progress value="1" max="3"/);
    field.model = {get: () => 0};
    assert.match(progress(field.data()), /<progress value="0" max="1"/);
});

test('workspace template names link to readable detail pages even without edit permission', () => {
    const Name = load('src/views/fields/name.js', {'views/fields/varchar': class {data() { return {value: '<Template>'}; }}});
    const field = new Name();
    field.model = {entityType: 'Playbook', id: 'template-1', collection: {url: 'PlaybookWorkspace/6'}, get: () => false};
    const data = field.data();
    assert.equal(data.templateUrl, '#PlaybookManager/view/accountId=6&templateId=template-1');
    const html = handlebars.compile(read(client + 'res/templates/fields/name.tpl'))(data);
    assert.match(html, /href="#PlaybookManager\/view\//);
    assert.match(html, /&lt;Template&gt;/);
    assert.doesNotMatch(html, /data-edit-template/);
});

test('template detail loads scoped config and related runs and gates editing', async () => {
    const requested = [];
    const snapshot = {id: 't', name: '<script>bad()</script>', status: 'Published', revision: 2, canEdit: false,
        steps: [{name: '<Step>', kind: 'Task', instructions: '<img onerror=bad()>', references: ['javascript:bad()', 'https://example.com']}]};
    const Detail = load('src/views/template-detail.js', {'views/main': class {}}, {
        Espo: {Ajax: {getRequest: async url => { requested.push(url); return snapshot; }}},
    });
    const view = new Detail();
    view.accountId = '6'; view.templateId = 't'; view.version = 0;
    view.clearView = view.removeMenuItem = view.updatePageTitle = () => {};
    const menu = [];
    view.addMenuItem = (type, item) => menu.push(item.name);
    view.translate = key => labels[key] || key;
    const collection = {fetch: async () => {}, abortLastFetch() {}};
    view.getCollectionFactory = () => ({create: async () => collection});
    await view.load(false);
    assert.deepEqual(requested, ['PlaybookWorkspace/6/templates/t']);
    assert.equal(collection.url, 'PlaybookWorkspace/6');
    assert.equal(collection.data.view, 'runs');
    assert.equal(collection.data.templateId, 't');
    assert.deepEqual(menu, []);
    const html = handlebars.compile(read(client + 'res/templates/template-detail.tpl'))(view.data());
    assert.doesNotMatch(html, /<script>|<img|javascript:/);
    assert.match(html, /href="https:\/\/example.com"/);
    assert.match(html, /data-template-runs/);
    snapshot.canEdit = true;
    await view.load(false);
    assert.deepEqual(menu, ['edit']);
    snapshot.canEdit = false;
    await view.actionEdit(); // Does not attempt to create an editor.
});

test('opening a detail route prevents an older list route from replacing it', async () => {
    let resolve;
    const Controller = load('src/controllers/manager.js', {controller: class {}});
    const controller = new Controller();
    controller.collectionFactory = {create: () => new Promise(done => { resolve = done; })};
    const opened = [];
    controller.main = view => opened.push(view);
    const pending = controller.actionIndex({accountId: '6'});
    controller.actionView({accountId: '6', templateId: 't'});
    resolve({});
    await pending;
    assert.deepEqual(opened, ['feature-playbook:views/template-detail']);
});
