const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');
const Handlebars = require('handlebars');

const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const client = 'client/custom/modules/chatwoot/';
const plain = value => JSON.parse(JSON.stringify(value));
const utils = {cloneDeep: plain};

function load(file, dependencies = {}, globals = {}) {
    const exports = {};
    const {outputText} = ts.transpileModule(read(file), {
        compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS},
    });
    vm.runInNewContext(outputText, {
        exports,
        require(name) {
            assert.ok(name in dependencies, `Unexpected dependency: ${name}`);
            return {__esModule: true, default: dependencies[name]};
        },
        Espo: {Utils: utils},
        ...globals,
    }, {filename: file});
    return exports.default;
}

const Panels = load(client + 'src/views/activities/panels.js', {
    'views/record/detail-bottom': class {},
});
const Panel = load('client/modules/crm/src/views/record/panels/activities.js', {
    'views/record/panels/relationship': class {},
    'multi-collection': class {},
    'helpers/record-modal': class {},
});

test('only embedded activities/history opt in, preserving scope-specific panel behavior', () => {
    for (const scope of ['ChatwootConversation', 'Opportunity']) {
        const panels = new Panels();
        panels.scope = scope;
        panels.getMetadata = () => ({get(key) {
            if (key[0] === 'app') return {view: 'crm:views/record/panels/' + key.at(-1)};
            return [{name: 'activities', view: 'crm:views/opportunity/record/panels/activities'}];
        }});
        for (const name of ['activities', 'history']) {
            assert.equal(panels.getActivityPanelDefs(name).recordListView, 'chatwoot:views/activities/table');
        }
        assert.equal(panels.getActivityPanelDefs('opportunities').recordListView, undefined);
        assert.equal(panels.getActivityPanelDefs('activities').view, scope === 'Opportunity'
            ? 'crm:views/opportunity/record/panels/activities'
            : 'chatwoot:views/activities/activities-panel');
    }
});

test('core panels retain the compact default and preserve lazy loading and save refresh', async () => {
    for (const recordListView of [undefined, 'chatwoot:views/activities/table']) {
        for (const disabled of [false, true]) {
            const panel = new Panel();
            let fetches = 0;
            let rendered = false;
            let show;
            let saved;
            let update;
            panel.defs = {recordListView};
            panel.disabled = disabled;
            panel.collection = {fetch: async () => { fetches++; }};
            panel.model = {trigger: event => { update = event; }};
            panel.once = (event, callback) => { assert.equal(event, 'show'); show = callback; };
            panel.listenTo = (_view, event, callback) => {
                assert.equal(event, 'after:save');
                saved = callback;
            };
            panel.createView = (key, view, options, callback) => {
                assert.equal(key, 'list');
                assert.equal(view, recordListView || 'views/record/list-expanded');
                assert.equal(options.collection, panel.collection);
                assert.equal(options.rowActionsView, panel.rowActionsView);
                callback({render() { rendered = true; }});
            };
            panel.afterRender();
            if (disabled) {
                assert.equal(fetches, 0);
                show();
            }
            await Promise.resolve();
            assert.equal(fetches, 1);
            assert.equal(rendered, true);
            saved();
            assert.equal(update, 'update-related:activities');
        }
    }
});

// Use the real table layout converter, with the unrelated list lifecycle stubbed.
const List = load('client/src/views/record/list.ts', {
    'views/record/list-base': class {
        setup() {}
        prepareInternalLayout() {}
        data() {
            return {
                headerDefs: [
                    ...this.listLayout.map(column => ({
                        name: column.name,
                        label: column.customLabel,
                        width: column.width ? column.width + '%' : undefined,
                    })),
                    {className: 'action-cell', width: '25px'},
                ],
                rowDataList: this.collection.models.map(model => ({id: model.id})),
            };
        }
        getRowActionsDefs() { return {columnName: 'buttons'}; }
    },
    'ui': {notifyWait() {}, notify() {}},
});

function model(scope, id, attributes = {}) {
    const fields = {
        name: {type: 'varchar'},
        status: {type: 'enum'},
        assignedUser: {type: 'link'},
        dateEnd: {type: 'datetimeOptional', view: `crm:views/${scope.toLowerCase()}/fields/date-end`},
        ...(scope === 'Meeting' || scope === 'Call' ? {users: {type: 'linkMultiple'}} : {}),
        ...(scope === 'Appointment' ? {assignedUsers: {type: 'linkMultiple'}} : {}),
    };
    if (scope === 'Email') delete fields.dateEnd;
    return {
        entityType: scope,
        id,
        attributes: {...attributes},
        getFieldType: name => fields[name]?.type,
        getFieldParam: (name, param) => fields[name]?.[param],
        set(values) { Object.assign(this.attributes, values); },
    };
}

function fixture(models = [], {
    request = async () => ({list: []}),
    allowed = () => true,
    editable = () => true,
    clientDefs = {},
    options = {},
} = {}) {
    const requests = [];
    const warnings = [];
    const waits = [];
    const events = {};
    const Table = load(client + 'src/views/activities/table.js', {'views/record/list': List}, {
        Espo: {Ajax: {getRequest: async (scope, params) => {
            requests.push({scope, params: plain(params)});
            return request(scope, params);
        }}},
        console: {warn: (...args) => warnings.push(args)},
    });
    const view = new Table();
    view.options = options;
    view.collection = {
        seeds: Object.fromEntries(['Meeting', 'Call', 'Task', 'Appointment', 'Email'].map(scope => [scope, model(scope)])),
        models,
        get: id => view.collection.models.find(item => item.id === id),
    };
    view.getAcl = () => ({checkScope: () => true, checkField: allowed, checkModel: editable});
    view.getMetadata = () => ({get: keys => keys.slice(1).reduce((value, key) => value?.[key], clientDefs)});
    view.getFieldManager = () => ({getViewName: type => 'views/fields/' + type});
    view.rowActionsView = 'crm:views/record/row-actions/activities';
    view.wait = promise => waits.push(promise);
    view.on = (event, callback) => { events[event] = callback; };
    view.listenTo = (_collection, event, callback) => { events[event] = callback; };
    view.translate = (key, _category, scope) => JSON.parse(read(
        `custom/Espo/Modules/${scope === 'ChatwootActivities' ? 'Chatwoot' : 'Global'}/Resources/i18n/pt_BR/${scope}.json`,
    )).fields[key];
    view.setup();
    return {view, requests, warnings, events, ready: Promise.all(waits)};
}

test('six columns use the requested labels and native per-entity field views', async () => {
    const {view, ready} = fixture();
    await ready;
    assert.deepEqual(plain(view.listLayout.map(column => column.customLabel)), [
        'Nome', 'Tipo Entidade', 'Status', 'Data de Fim', 'Usuário Designado', 'Participantes',
    ]);
    assert.ok(view.listLayout.every(column => column.notSortable));
    for (const [scope, seed] of Object.entries(view.collection.seeds)) {
        const layout = view._convertLayout(view.multiListLayout[scope], seed);
        assert.equal(layout.length, 7); // Six fields plus the existing row actions.
        assert.equal(layout[0].options.mode, 'listLink');
        assert.equal(layout[1].view, 'global:views/activities/fields/entity-type');
        assert.equal(layout[2].view, 'views/fields/enum');
        assert.equal(layout[3].view, scope === 'Email'
            ? 'views/fields/base'
            : `crm:views/${scope.toLowerCase()}/fields/date-end`);
        assert.equal(layout[5].options.defs.name, scope === 'Appointment' ? 'assignedUsers' : 'users');
        assert.equal(layout[5].view, ['Task', 'Email'].includes(scope)
            ? 'views/fields/base' : 'global:views/fields/link-multiple-with-icons');
        assert.equal(layout[6].columnName, 'buttons');
    }
});

test('row-action dropdowns escape the horizontal scroll container', async () => {
    const {view, events, ready} = fixture();
    await ready;
    let fixed = false;
    view.$el = {find(selector) {
        assert.equal(selector, '.list-row-buttons');
        return {parent: () => ({addClass(name) { fixed = name === 'fix-position'; }})};
    }};
    events['after:render after:show-more']();
    assert.equal(fixed, true);
});

test('empty and populated templates retain the table, headers and Show more control', async () => {
    const {view, ready} = fixture();
    await ready;
    const handlebars = Handlebars.create();
    handlebars.registerHelper('translate', key => key === 'No Data' ? 'Sem dados' : 'Mostrar mais');
    handlebars.registerHelper('var', (key, context) => context[key]);
    const render = handlebars.compile(read(client + 'res/templates/activities/table.tpl'));
    const empty = render(view.data());
    assert.match(empty, /<table class="table">/);
    assert.equal((empty.match(/<th\s+scope="col"/g) || []).length, 7);
    assert.match(empty, /style="width: 25%;"/);
    assert.match(empty, /style="width: 25px;"/);
    assert.match(empty, /colspan="7"/);
    assert.match(empty, /Sem dados/);
    assert.doesNotMatch(empty, /data-action="showMore"/);

    const full = render({
        ...view.data(),
        rowDataList: [{id: 'meeting-1'}],
        'meeting-1': '<td>Reunião</td>',
        showMoreEnabled: true,
        showMoreActive: true,
        viewObject: {cid: 'table-1'},
    });
    assert.match(full, /<tr data-id="meeting-1" class="list-row"><td>Reunião<\/td><\/tr>/);
    assert.doesNotMatch(full, /Sem dados/);
    assert.match(full, /data-action="showMore"/);
    assert.match(full, /data-owner-cid="table-1"/);
});

test('atomic editing uses native list callbacks only for real fields on each activity type', async () => {
    const {view, ready} = fixture();
    await ready;
    for (const [scope, seed] of Object.entries(view.collection.seeds)) {
        const layout = view._convertLayout(view.multiListLayout[scope], seed);
        view.prepareInternalLayout(layout, seed);
        for (const item of layout) {
            const field = item.options?.defs?.name;
            if (!field) continue;
            const editable = field !== '_scope' && !!seed.getFieldType(field);
            assert.equal(!!item.options.inlineEditEnabled, editable, `${scope}.${field}`);
            if (!editable) {
                assert.equal(item.options.onInlineEdit, undefined);
                continue;
            }
            let edited;
            view.editField = (record, name) => { edited = {record, name}; };
            item.options.onInlineEdit();
            assert.equal(edited.record, seed);
            assert.equal(edited.name, field);
        }
    }
});

test('atomic editing respects record/field ACL and list/entity disable flags', async () => {
    for (const overrides of [
        {editable: (_model, action) => action !== 'edit'},
        {allowed: (_scope, _field, action) => action !== 'edit'},
        {options: {inlineEditDisabled: true}},
        {clientDefs: {Meeting: {inlineEditDisabled: true}}},
        {clientDefs: {Meeting: {listInlineEditDisabled: true}}},
    ]) {
        const {view, ready} = fixture([], overrides);
        await ready;
        assert.equal(view.isInlineEditEnabledForField(model('Meeting', 'm1'), 'status'), false);
        if (overrides.clientDefs) {
            assert.equal(view.isInlineEditEnabledForField(model('Task', 't1'), 'status'), true);
        }
    }
});

test('native single-field modal syncs the saved activity and preserves panel refresh events', async () => {
    const record = model('Appointment', 'a1', {status: 'Planned'});
    const {view, ready} = fixture([record]);
    await ready;
    const modal = {render: async () => {}};
    const events = {};
    const triggered = [];
    view.createView = async (key, name, options) => {
        assert.equal(key, 'editFieldModal');
        assert.equal(name, 'views/modals/edit-field');
        assert.equal(options.entityType, 'Appointment');
        assert.equal(options.id, 'a1');
        assert.equal(options.field, 'assignedUsers');
        assert.equal(options.model, record);
        return modal;
    };
    view.listenTo = view.listenToOnce = (target, event, callback) => {
        assert.equal(target, modal);
        events[event] = callback;
    };
    view.trigger = (event, model) => triggered.push([event, model]);
    record.setMultiple = (attributes, options) => {
        assert.deepEqual(plain(options), {sync: true});
        record.set(attributes);
    };
    await view.editField(record, 'assignedUsers');
    assert.deepEqual(record.attributes, {status: 'Planned'});
    const saved = {id: 'a1', getClonedAttributes: () => ({assignedUsersIds: ['u1']})};
    events['before:save'](saved);
    events['after:save'](saved);
    assert.deepEqual(record.attributes, {status: 'Planned', assignedUsersIds: ['u1']});
    assert.deepEqual(triggered, [['before:save', saved], ['after:save', saved]]);
    let cleared;
    view.clearView = key => { cleared = key; };
    events.remove();
    assert.equal(cleared, 'editFieldModal');
});

test('participants are batched by visible scope and refreshed on collection sync', async () => {
    const models = [model('Meeting', 'm1'), model('Meeting', 'm2'), model('Appointment', 'a1'), model('Task', 't1')];
    const f = fixture(models, {request: async (scope, params) => ({
        list: params.where[0].value.map(id => ({
            id,
            [scope === 'Appointment' ? 'assignedUsersIds' : 'usersIds']: ['u1'],
            [scope === 'Appointment' ? 'assignedUsersNames' : 'usersNames']: {u1: 'Ana'},
            name: 'must not overwrite the activity',
        })),
    })});
    await f.ready;
    assert.equal(f.requests.length, 2);
    assert.deepEqual(f.requests[0], {
        scope: 'Meeting',
        params: {
            select: 'id,usersIds,usersNames',
            where: [{type: 'in', attribute: 'id', value: ['m1', 'm2']}],
            maxSize: 2,
        },
    });
    assert.deepEqual(plain(models[0].attributes), {usersIds: ['u1'], usersNames: {u1: 'Ana'}});
    assert.deepEqual(plain(models[2].attributes), {assignedUsersIds: ['u1'], assignedUsersNames: {u1: 'Ana'}});
    assert.equal(f.view.getModelScope('a1'), 'Appointment');

    models.push(model('Meeting', 'm3')); // Show more adds models before emitting sync.
    await f.events.sync();
    assert.equal(f.requests.length, 4);
    assert.deepEqual(plain(models.at(-1).attributes.usersIds), ['u1']);
});

test('restricted or failed participant lookups do not invent empty data or hide records', async () => {
    const restricted = fixture([model('Meeting', 'm1')], {allowed: () => false});
    await restricted.ready;
    assert.equal(restricted.requests.length, 0);

    const failed = fixture([model('Meeting', 'm1')], {request: async () => { throw new Error('offline'); }});
    await failed.ready;
    assert.equal(failed.warnings.length, 1);
    assert.equal(failed.view.collection.models.length, 1);
    assert.equal(failed.view.collection.models[0].attributes.usersIds, undefined);

    const omitted = fixture([model('Meeting', 'm1')], {request: async () => ({list: [{id: 'm1'}]})});
    await omitted.ready;
    assert.equal(omitted.view.collection.models[0].attributes.usersIds, undefined);
});

test('a stale participant response cannot overwrite a newer refresh', async () => {
    const pending = [];
    const record = model('Meeting', 'm1');
    const f = fixture([record], {request: () => new Promise(resolve => pending.push(resolve))});
    const refreshed = f.events.sync();
    pending[1]({list: [{id: 'm1', usersIds: ['new'], usersNames: {new: 'New'}}]});
    await refreshed;
    pending[0]({list: [{id: 'm1', usersIds: ['old'], usersNames: {old: 'Old'}}]});
    await f.ready;
    assert.deepEqual(plain(record.attributes.usersIds), ['new']);
});
