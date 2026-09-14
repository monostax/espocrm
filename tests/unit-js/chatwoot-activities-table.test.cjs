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
const utils = {
    cloneDeep: value => value === undefined ? value : plain(value),
    clone: value => [...value],
    upperCaseFirst: value => value[0].toUpperCase() + value.slice(1),
};
const ui = {notifyWait() {}, notify() {}, success() {}, warning() {}, error() {}};

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
                assert.equal(event, 'after:save after:delete after:mass-update after:mass-remove');
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

// Exercise native selection, mass-action setup and deletion; stub unrelated rendering.
const ListBase = load('client/src/views/record/list-base.ts', {
    ...Object.fromEntries([
        'view', 'helpers/mass-action', 'helpers/export', 'helpers/record-modal',
        'helpers/list/select-provider', 'views/record/list/settings', 'helpers/list/settings',
        'helpers/list/misc/sticky-bar', 'helpers/record/list/column-resize',
        'helpers/record/list/column-width-control',
    ].map(name => [name, class {}])),
    underscore: require('underscore'),
    utils,
    ui,
    ajax: {},
});
const List = load('client/src/views/record/list.ts', {
    'views/record/list-base': class extends ListBase {
        setup() {
            this.checkedList = [];
            this.rowList = this.collection.models.map(model => model.id);
            this.scope = this.entityType = null;
            this.editDisabled = !!this.options.editDisabled;
            this.removeDisabled = !!this.options.removeDisabled;
            this.setupMassActions();
            if (!this.massActionList.length) this.checkboxes = false;
        }
        prepareInternalLayout() {}
        data() {
            return {
                checkboxes: this.checkboxes,
                checkboxColumnWidth: '40px',
                collectionLength: this.collection.models.length,
                topBar: this.checkboxes && this.collection.models.length > 0,
                massActionDataList: this.getMassActionDataList(),
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
    ui,
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
    scopeAllowed = () => true,
    permission = 'yes',
    post = async (_url, payload) => ({ids: payload.params.ids, count: payload.params.ids.length}),
    clientDefs = {},
    options = {},
} = {}) {
    const requests = [];
    const warnings = [];
    const waits = [];
    const events = {};
    const posts = [];
    const notifications = [];
    const triggered = [];
    const Table = load(client + 'src/views/activities/table.js', {
        'views/record/list': List,
        ui: Object.fromEntries(Object.keys(ui).map(key => [key, message => notifications.push([key, message])])),
    }, {
        Espo: {Ajax: {
            getRequest: async (scope, params) => {
                requests.push({scope, params: plain(params)});
                return request(scope, params);
            },
            postRequest: async (url, payload) => {
                posts.push({url, payload: plain(payload)});
                return post(url, payload);
            },
        }},
        console: {warn: (...args) => warnings.push(args)},
    });
    const view = new Table();
    view.options = options;
    view.collection = {
        seeds: Object.fromEntries(['Meeting', 'Call', 'Task', 'Appointment', 'Email'].map(scope => [scope, model(scope)])),
        models,
        total: models.length,
        get length() { return this.models.length; },
        get: id => view.collection.models.find(item => item.id === id),
        indexOf: record => view.collection.models.indexOf(record),
        remove: record => {
            const id = typeof record === 'string' ? record : record.id;
            const index = view.collection.models.findIndex(item => item.id === id);
            if (index !== -1) view.collection.models.splice(index, 1);
        },
        add: (record, {at}) => view.collection.models.splice(at, 0, record),
        trigger: (event, ...args) => triggered.push([event, ...args]),
    };
    view.getAcl = () => ({
        checkScope: scopeAllowed, checkField: allowed, checkModel: editable,
        getPermissionLevel: () => permission,
    });
    view.getMetadata = () => ({get: keys => (Array.isArray(keys) ? keys : keys.split('.'))
        .slice(1).reduce((value, key) => value?.[key], clientDefs)});
    view.getConfig = () => ({get: () => undefined});
    view.getUser = () => ({isAdmin: () => false});
    view.getThemeManager = () => ({getFontSizeFactor: () => 1});
    view.getFieldManager = () => ({getViewName: type => 'views/fields/' + type});
    view.rowActionsView = 'crm:views/record/row-actions/activities';
    view.wait = promise => waits.push(promise);
    view.on = (event, callback) => { events[event] = callback; };
    view.listenTo = (_collection, event, callback) => { events[event] = callback; };
    view.trigger = (event, ...args) => {
        triggered.push([event, ...args]);
        for (const [names, callback] of Object.entries(events)) {
            if (names.split(' ').includes(event)) callback(...args);
        }
    };
    const dom = new Map();
    view.$el = {find: selector => {
        if (!dom.has(selector)) {
            const element = {
                prop(key, value) { if (arguments.length === 1) return this[key]; this[key] = value; return this; },
                attr(key, value) { this[key] = value; return this; },
                text(value) { this.content = value; return this; },
                parent() { return this; },
                addClass() { return this; }, removeClass() { return this; },
                remove() { return this; },
                toggleClass(key, value) { this[key] = value; return this; },
            };
            dom.set(selector, element);
        }
        return dom.get(selector);
    }};
    view.element = {querySelector: () => null};
    view.clearView = () => {};
    view.reRender = () => {};
    view.translate = (key, category = 'labels', scope) => JSON.parse(read(scope
        ? `custom/Espo/Modules/${scope === 'ChatwootActivities' ? 'Chatwoot' : 'Global'}/Resources/i18n/pt_BR/${scope}.json`
        : 'application/Espo/Resources/i18n/en_US/Global.json'
    ))[category]?.[key] || key;
    view.init();
    view.setup();
    return {view, requests, warnings, events, posts, notifications, triggered, dom, ready: Promise.all(waits)};
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
        assert.equal(layout.length, 8); // Checkbox, six fields and row actions.
        assert.equal(layout.shift().template, 'record/list-checkbox');
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
    assert.equal((empty.match(/<th\s+scope="col"/g) || []).length, 8);
    assert.match(empty, /style="width: 25%;"/);
    assert.match(empty, /style="width: 25px;"/);
    assert.match(empty, /colspan="8"/);
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

test('embedded init overrides compact panel defaults and exposes only mixed-safe actions', async () => {
    const {view, ready} = fixture([], {options: {checkboxes: false, rowActionsView: 'compact'}});
    await ready;
    assert.equal(view.checkboxes, true);
    assert.equal(view.rowActionsView, 'chatwoot:views/activities/row-actions');
    assert.deepEqual(plain(view.massActionList), ['massUpdate', 'remove']);
    assert.equal(view.checkAllResultDisabled, true);
    assert.deepEqual(plain(view.checkAllResultMassActionList), []);

    for (const overrides of [
        {scopeAllowed: () => false},
        {options: {massActionsDisabled: true}},
        {options: {editDisabled: true, removeDisabled: true}},
    ]) {
        const disabled = fixture([], overrides);
        await disabled.ready;
        assert.equal(disabled.view.checkboxes, false);
        assert.deepEqual(plain(disabled.view.massActionList), []);
    }
});

test('native selection handles individual rows, select-all, deselection and Show more', async () => {
    const {view, dom, ready} = fixture([model('Task', 't1'), model('Email', 'e1')]);
    await ready;
    view.checkRecord('t1');
    assert.deepEqual(plain(view.getCheckedIds()), ['t1']);
    assert.equal(dom.get('.select-all').indeterminate, true);
    assert.equal(dom.get('.selected-count').content, '1 selecionado(s)');

    view.selectAllHandler(true);
    assert.deepEqual(plain(view.getCheckedIds()), ['t1', 'e1']);
    assert.equal(dom.get('.select-all').checked, true);
    assert.equal(dom.get('.select-all').indeterminate, false);

    view.collection.models.push(model('Task', 't2'));
    view.trigger('after:show-more');
    assert.equal(dom.get('.select-all').checked, false);
    assert.equal(dom.get('.select-all').indeterminate, true);
    assert.deepEqual(plain(view.getCheckedIds()), ['t1', 'e1']);
    view.selectAllHandler(true);
    assert.deepEqual(plain(view.getCheckedIds()), ['t1', 'e1', 't2']);
    view.uncheckRecord('e1');
    assert.deepEqual(plain(view.getCheckedIds()), ['t1', 't2']);
    view.selectAllHandler(false);
    assert.equal(view.getCheckedIds().length, 0);
    assert.equal(dom.get('.selected-count').content, '');
});

test('bulk controls render with the native selectors and no unbounded select-all action', async () => {
    const {view, ready} = fixture([model('Task', 't1')]);
    await ready;
    const handlebars = Handlebars.create();
    handlebars.registerHelper('translate', key => key);
    handlebars.registerHelper('var', () => '<td>Task</td>');
    const html = handlebars.compile(read(client + 'res/templates/activities/table.tpl'))(view.data());
    assert.match(html, /actions-button hidden/);
    assert.match(html, /data-action="massUpdate" class="mass-action"/);
    assert.match(html, /data-action="remove" class="mass-action"/);
    assert.match(html, /class="select-all form-checkbox form-checkbox-small"/);
    assert.doesNotMatch(html, /selectAllResult/);
});

test('bulk actions honor mixed record ACL, scope flags and mass-update permission', async () => {
    const {view, dom, posts, ready} = fixture([model('Task', 't1'), model('Email', 'e1')], {
        editable: (record, action) => record.id !== 'e1' || action !== 'delete',
        permission: 'no',
    });
    await ready;
    assert.deepEqual(plain(view.massActionList), ['remove']);
    view.selectAllHandler(true);
    assert.equal(dom.get('.mass-action[data-action="remove"]').disabled, true);
    await view.massActionRemove();
    await view.massActionMassUpdate();
    assert.equal(posts.length, 0);
    view.uncheckRecord('e1');
    assert.equal(dom.get('.mass-action[data-action="remove"]').disabled, false);

    for (const [action, flag] of [['remove', 'removeDisabled'], ['remove', 'massRemoveDisabled'],
        ['massUpdate', 'massUpdateDisabled'], ['massUpdate', 'editDisabled']]) {
        const restricted = fixture([model('Task', 't1')], {clientDefs: {Task: {[flag]: true}}});
        await restricted.ready;
        restricted.view.selectAllHandler(true);
        assert.equal(restricted.view.getSelectedGroups(action), null);
    }
});

test('bulk remove confirms once and sends only selected IDs to their own entity endpoints', async () => {
    const f = fixture([model('Task', 't1'), model('Email', 'e1'), model('Task', 't2'), model('Task', 't3')]);
    await f.ready;
    ['t1', 'e1', 't2'].forEach(id => f.view.checkRecord(id));
    let confirm;
    f.view.confirm = options => {
        assert.equal(options.confirmText, 'Remove');
        return new Promise(resolve => { confirm = resolve; });
    };
    const pending = f.view.massActionRemove();
    assert.equal(f.posts.length, 0);
    await f.view.massActionRemove(); // Repeated clicks must not send duplicate requests.
    confirm();
    await pending;
    assert.deepEqual(f.posts, [
        {url: 'MassAction', payload: {entityType: 'Task', action: 'delete', params: {ids: ['t1', 't2']}, idle: false}},
        {url: 'MassAction', payload: {entityType: 'Email', action: 'delete', params: {ids: ['e1']}, idle: false}},
    ]);
    assert.deepEqual(f.view.collection.models.map(record => record.id), ['t3']);
    assert.equal(f.view.getCheckedIds().length, 0);
    assert.equal(f.view.bulkActionBusy, false);
    assert.ok(f.triggered.some(([event]) => event === 'after:mass-remove'));
    assert.equal(f.notifications.at(-1)[0], 'success');
});

test('canceling removal sends no request and preserves the selection', async () => {
    const f = fixture([model('Task', 't1')]);
    await f.ready;
    f.view.checkRecord('t1');
    f.view.confirm = async () => { throw new Error('cancel'); };
    await assert.rejects(f.view.massActionRemove(), /cancel/);
    assert.equal(f.posts.length, 0);
    assert.deepEqual(plain(f.view.getCheckedIds()), ['t1']);
    assert.equal(f.view.bulkActionBusy, false);
});

test('partial bulk removal keeps failed and server-denied rows and reports the actual count', async () => {
    const f = fixture([model('Task', 't1'), model('Task', 't2'), model('Email', 'e1')], {
        post: async (_url, payload) => {
            if (payload.entityType === 'Email') throw new Error('offline');
            return {count: 1, ids: ['t1', 'unrequested-id']};
        },
    });
    await f.ready;
    f.view.selectAllHandler(true);
    f.view.confirm = async () => {};
    await f.view.massActionRemove();
    assert.deepEqual(f.view.collection.models.map(record => record.id), ['t2', 'e1']);
    assert.deepEqual(plain(f.view.getCheckedIds()), ['t2', 'e1']);
    assert.equal(f.notifications.at(-1)[0], 'warning');
    assert.match(f.notifications.at(-1)[1], /1 de 3/);
    assert.equal(f.view.bulkActionBusy, false);
});

function installBulkEditors(view, responses) {
    const editors = [];
    view.listenToOnce = (modal, event, callback) => { modal.events[event] = callback; };
    view.createView = async (key, name, options) => {
        const response = responses[editors.length];
        const modal = {
            events: {},
            close() { this.events.close(); },
            async render() {
                if (response) this.events['after:update'](response);
                else this.close();
            },
        };
        editors.push({key, name, options: plain(options)});
        return modal;
    };
    return editors;
}

test('bulk update opens type-specific native editors with explicit IDs and refreshes both panels', async () => {
    const f = fixture([model('Task', 't1'), model('Email', 'e1'), model('Task', 't2')], {
        clientDefs: {Email: {modalViews: {massUpdate: 'custom:email-bulk'}}},
    });
    await f.ready;
    f.view.selectAllHandler(true);
    const editors = installBulkEditors(f.view, [{count: 2}, {count: 1}]);
    await f.view.massActionMassUpdate();
    assert.deepEqual(editors, [
        {key: 'massUpdate', name: 'views/modals/mass-update', options: {
            scope: 'Task', entityType: 'Task', ids: ['t1', 't2'], byWhere: false, totalCount: 2,
        }},
        {key: 'massUpdate', name: 'custom:email-bulk', options: {
            scope: 'Email', entityType: 'Email', ids: ['e1'], byWhere: false, totalCount: 1,
        }},
    ]);
    assert.equal(f.triggered.filter(([event]) => event === 'after:mass-update').length, 1);
    assert.match(f.notifications.at(-1)[1], /3/);
    assert.equal(f.view.bulkActionBusy, false);
});

test('canceling a bulk editor stops subsequent types and still refreshes earlier updates', async () => {
    for (const responses of [[null], [{count: 1}, null]]) {
        const f = fixture([model('Task', 't1'), model('Email', 'e1'), model('Call', 'c1')]);
        await f.ready;
        f.view.selectAllHandler(true);
        const editors = installBulkEditors(f.view, responses);
        await f.view.massActionMassUpdate();
        assert.equal(editors.length, responses.length);
        assert.equal(f.triggered.some(([event]) => event === 'after:mass-update'), responses.length > 1);
        assert.equal(f.view.bulkActionBusy, false);
    }
});

const DefaultRowActions = load('client/src/views/record/row-actions/default.js', {view: class {}});
const RelationshipRowActions = load('client/src/views/record/row-actions/relationship.js', {
    'views/record/row-actions/default': DefaultRowActions,
});
const ActivityRowActions = load('client/modules/crm/src/views/record/row-actions/activities.js', {
    'views/record/row-actions/relationship': RelationshipRowActions,
});
const EmbeddedRowActions = load(client + 'src/views/activities/row-actions.js', {
    'crm:views/record/row-actions/activities': ActivityRowActions,
});

test('row Remove is embedded-only, retains native actions and respects delete ACL/disable flags', () => {
    for (const [Type, options, disabled, expected] of [
        [EmbeddedRowActions, {acl: {edit: true, delete: true}}, false, true],
        [EmbeddedRowActions, {acl: {edit: true, delete: false}}, false, false],
        [EmbeddedRowActions, {acl: {edit: true, delete: true}}, true, false],
        [EmbeddedRowActions, {acl: {edit: true, delete: true}, removeDisabled: true}, false, false],
        [ActivityRowActions, {acl: {edit: true, delete: true}}, false, false],
    ]) {
        const row = new Type({...options, unlinkDisabled: true});
        row.model = model('Task', 't1');
        row.getAdditionalActionList = () => [];
        row.getMetadata = () => ({get: () => disabled});
        const actions = row.getActionList();
        assert.equal(actions.some(item => item.action === 'quickRemove'), expected);
        assert.deepEqual(plain(actions.slice(0, 2).map(item => item.action)), ['quickView', 'quickEdit']);
        assert.equal(actions.some(item => item.action === 'unlinkRelated'), false);
    }
});

test('row removal uses native confirmation, delete events and rollback on failure', async () => {
    for (const outcome of ['success', 'failure', 'denied']) {
        const record = model('Task', 't1');
        const f = fixture([record, model('Email', 'e1')], {
            editable: (_record, action) => outcome !== 'denied' || action !== 'delete',
        });
        await f.ready;
        f.view.checkRecord('t1');
        let confirms = 0;
        let destroys = 0;
        f.view.confirm = async () => { confirms++; };
        record.destroy = async options => {
            assert.deepEqual(plain(options), {wait: true, fromList: true});
            destroys++;
            if (outcome === 'failure') throw new Error('offline');
        };
        await f.view.actionQuickRemove({id: 't1'});
        assert.equal(confirms, outcome === 'denied' ? 0 : 1);
        assert.equal(destroys, outcome === 'denied' ? 0 : 1);
        assert.equal(!!f.view.collection.get('t1'), outcome !== 'success');
        assert.equal(f.triggered.some(([event]) => event === 'after:delete'), outcome === 'success');
        assert.equal(f.view.getCheckedIds().includes('t1'), outcome !== 'success');
    }
});
