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
    const exports = {};
    const {outputText} = ts.transpileModule(read(client + file), {
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
        opportunityName: '<script>bad()</script>', templates: [{id: 'template', name: 'Qualification', revision: 2, statusLabel: 'Published', canEdit: false}]};
    const html = page(context);
    assert.ok(!html.includes('<script>'));
    assert.match(html, /&lt;script&gt;/);
    assert.ok(!html.includes('data-manager-action="new"'));
    assert.ok(!html.includes('data-manager-template='));
    const editable = page({...context, canCreate: true, templates: [{...context.templates[0], canEdit: true}]});
    assert.match(editable, /data-manager-template="template"/);
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

test('workspace routes open tables without requiring an opportunity', () => {
    const Controller = load('src/controllers/manager.js', {controller: class {}});
    const controller = new Controller();
    controller.main = (view, options) => {
        assert.equal(view, 'feature-playbook:views/workspace');
        assert.equal(options.params.accountId, '6');
        assert.equal(options.params.view, 'runs');
    };
    controller.actionIndex({accountId: '6', view: 'runs'});
});

test('workspace ignores a stale response after switching filters and persists URL state', async () => {
    let resolveOld;
    const old = new Promise(resolve => { resolveOld = resolve; });
    const requested = [];
    const Workspace = load('src/views/workspace.js', {'views/main': class {}}, {
        Espo: {Ajax: {getRequest: (url, params) => {
            requested.push({url, params});
            return params.status === 'Active' ? old : Promise.resolve({items: [{id: 'complete'}], cursor: null});
        }}},
    });
    const view = new Workspace();
    Object.assign(view, {accountId: '6', tab: 'runs', status: 'Active', search: 'First & next', opportunityId: 'deal', cursor: '', version: 0});
    view.reRender = async () => {};
    const first = view.load(false);
    view.status = 'Completed';
    await view.load(false);
    resolveOld({items: [{id: 'stale'}]});
    await first;
    assert.equal(view.snapshot.items[0].id, 'complete');
    assert.equal(view.loading, false);
    assert.equal(requested[0].url, 'PlaybookWorkspace/6');
    assert.equal(requested[1].params.opportunityId, 'deal');
    let url;
    view.getRouter = () => ({navigate: value => { url = value; }});
    view.navigate();
    const params = new URLSearchParams(url.slice('PlaybookManager/index/'.length));
    assert.equal(params.get('accountId'), '6');
    assert.equal(params.get('status'), 'Completed');
    assert.equal(decodeURIComponent(params.get('search')), 'First & next');
    const espoOptions = Object.fromEntries(decodeURIComponent(url.slice('PlaybookManager/index/'.length))
        .split('&').map(item => { const [key, value] = item.split('='); return [key, decodeURIComponent(value)]; }));
    assert.equal(espoOptions.search, 'First & next');
    assert.equal(params.get('opportunityId'), 'deal');
});

test('workspace renders both tables, escapes values, and hides unauthorized edit actions', () => {
    handlebars.registerHelper('translate', key => labels[key] || key);
    const page = handlebars.compile(read(client + 'res/templates/workspace.tpl'));
    const row = {id: 'template', name: '<script>bad()</script>', statusLabel: 'Published', total: 3, canEdit: false};
    const html = page({hasSnapshot: true, rows: [row], title: 'Playbooks'});
    assert.match(html, /<table/);
    assert.ok(!html.includes('<script>'));
    assert.ok(!html.includes('data-workspace-template='));
    assert.ok(!html.includes('data-workspace-action="new"'));
    const editable = page({hasSnapshot: true, canCreate: true, rows: [{...row, canEdit: true}]});
    assert.match(editable, /data-workspace-template="template"/);
    const runs = page({hasSnapshot: true, runs: true, rows: [{...row, opportunityUrl: '#Opportunity/view/deal', completed: 1, progressMax: 3}]});
    assert.match(runs, /href="#Opportunity\/view\/deal"/);
    assert.match(runs, /<progress value="1" max="3"/);
});
