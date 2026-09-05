const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const resource = file => JSON.parse(read('custom/Espo/Modules/FeatureSimpleJourney/Resources/' + file));

function extend(props) {
    class Extended extends this {}
    Object.assign(Extended.prototype, props);
    Extended.extend = extend;
    return Extended;
}

// Execute Espo's actual client ACL rather than reproducing its ownership logic.
const core = {BullView: {extend}};
vm.runInNewContext(read('client/src/acl.js')
    .replace(/^import .*;$/gm, '')
    .replace('export default Acl;', 'globalThis.CoreAcl = Acl;'), core);

function loadFeatureAcl() {
    let Implementation;
    vm.runInNewContext(read('client/custom/modules/feature-simple-journey/src/acl.js'), {
        define(name, dependencies, factory) { Implementation = factory(core.CoreAcl); },
    });
    return Implementation;
}

const FeatureAcl = loadFeatureAcl();
const permissions = {read: 'team', edit: 'team', delete: 'team', stream: 'team'};
const user = id => ({id, isAdmin: () => false, getTeamIdList: () => ['journey-team']});
const model = (type, attributes = {}) => ({
    id: 'link-or-record-id',
    entityType: type,
    isNew: () => false,
    has: key => Object.hasOwn(attributes, key),
    hasField: key => ['createdBy', ...(type === 'SimpleJourneyRecord' ? ['assignedUser'] : [])].includes(key),
    get: key => attributes[key],
});

test('colleagues on the same journey team can edit stages, records and parent links', () => {
    for (const type of ['SimpleJourneyStage', 'SimpleJourneyRecord', 'SimpleJourneyRecordParent']) {
        for (const id of ['team-user-1', 'team-user-2']) {
            for (const assignedUserId of [null, 'another-user']) {
                const record = model(type, {
                    createdById: 'another-user', assignedUserId,
                    simpleJourneyAccess: {read: true, edit: true, delete: true, stream: true},
                });
                // This is the actual integration regression: no local teams on these models.
                assert.equal(new core.CoreAcl(user(id), type, {}).checkModel(record, permissions, 'edit'), false);
                const acl = new FeatureAcl(user(id), type, {});
                assert.equal(acl.checkModel(record, permissions, 'read'), true);
                assert.equal(acl.checkModel(record, permissions, 'edit'), true);
                assert.equal(acl.checkModelDelete(record, permissions), true);
            }
        }
    }
});

test('server denials are not overridden by all-level roles or the creator deletion fallback', () => {
    const acl = new FeatureAcl(user('creator'), 'SimpleJourneyRecordParent', {aclAllowDeleteCreated: true});
    const record = model('SimpleJourneyRecordParent', {
        createdById: 'creator', simpleJourneyAccess: {read: true, edit: false, delete: false},
    });
    assert.equal(acl.checkModel(record, {edit: 'all'}, 'edit'), false);
    assert.equal(acl.checkModelDelete(record, {read: 'all', delete: 'no'}), false);
});

test('missing flags fail closed; partial models can request a precise unknown result', () => {
    const acl = new FeatureAcl(user('colleague'), 'SimpleJourneyStage', {});
    assert.equal(acl.checkModel(model('SimpleJourneyStage'), permissions, 'edit'), false);
    assert.equal(acl.checkModel(model('SimpleJourneyStage'), permissions, 'edit', true), null);
    assert.equal(acl.checkModel(model('SimpleJourneyStage', {simpleJourneyAccess: {edit: true}}), false, 'edit'), false);
});

test('new models continue using scope create permissions', () => {
    const acl = new FeatureAcl(user('colleague'), 'SimpleJourneyRecord', {});
    const fresh = {...model('SimpleJourneyRecord'), isNew: () => true};
    assert.equal(acl.checkModel(fresh, {create: 'yes'}, 'create'), true);
    assert.equal(acl.checkModel(fresh, {create: 'no'}, 'create'), false);
});

function rowActions(record, options = {}) {
    class DefaultActions {
        static ICON_CLASS_VIEW = 'view';
        static ICON_CLASS_EDIT = 'edit';
        getAdditionalActionList() { return []; }
    }
    const context = {DefaultRowActionsView: DefaultActions};
    vm.runInNewContext(read('client/src/views/record/row-actions/relationship.js')
        .replace(/^import .*;$/gm, '')
        .replace('export default RelationshipRowActionsView;', 'globalThis.Relationship = RelationshipRowActionsView;'), context);
    context.Relationship.extend = extend;
    let View;
    vm.runInNewContext(read('client/custom/modules/feature-simple-journey/src/views/record-parent/row-actions.js'), {
        define(name, dependencies, factory) { View = factory(context.Relationship); },
    });
    const view = new View();
    const acl = new FeatureAcl(user('colleague'), record.entityType, {});
    Object.assign(view, {
        model: record,
        options: {acl: {edit: true}, unlinkDisabled: true, ...options},
        getAcl: () => ({checkModel: (m, action) => acl.checkModel(m, permissions, action)}),
        translate: () => 'Remover Vínculo',
    });
    return {custom: view.getActionList(), standard: context.Relationship.prototype.getActionList.call(view)};
}

test('parent-link removal is scoped to link models and respects per-record delete ACL', () => {
    const link = model('SimpleJourneyRecordParent', {parentId: 'do-not-delete', simpleJourneyAccess: {delete: true}});
    const actions = rowActions(link);
    assert.equal(actions.standard.some(item => item.action === 'quickRemove'), false);
    const removal = actions.custom.find(item => item.action === 'quickRemove');
    assert.equal(removal.data.id, link.id);
    assert.equal(removal.text, 'Remover Vínculo');
    for (const [type, allowed, disabled] of [
        ['Contact', true, false], ['SimpleJourneyRecordParent', false, false], ['SimpleJourneyRecordParent', true, true],
    ]) {
        const record = model(type, {simpleJourneyAccess: {delete: allowed}});
        assert.equal(rowActions(record, {removeDisabled: disabled}).custom.some(item => item.action === 'quickRemove'), false);
    }
});

test('native quickRemove deletes only the association model and refreshes its relationship list', async () => {
    // This method body contains plain JS; keep the production flow, not a copied implementation.
    const native = read('client/src/views/record/list-base.ts').match(
        /protected async actionQuickRemove\(data\?: \{id\?: string\}\): Promise<void> \{([\s\S]*?)\n    \}/,
    );
    assert.ok(native, 'Native quickRemove method must be located');
    const context = {Ui: {notifyWait() {}, success() {}, error() { throw new Error('Unexpected denial'); }}};
    vm.runInNewContext(`globalThis.remove = async function(data) {${native[1]}\n}`, context);
    const destroyed = [];
    const refreshed = [];
    const association = {
        id: 'association-1', entityType: 'SimpleJourneyRecordParent',
        parentId: 'contact-1', parentType: 'Contact',
        async destroy() { destroyed.push([this.entityType, this.id]); },
    };
    const list = {
        scope: 'SimpleJourneyRecordParent',
        collection: {get: () => association, indexOf: () => 0, trigger() {}, remove() {}},
        getAcl: () => ({checkModel: () => true}),
        translate: key => key,
        async confirm(options) { assert.equal(options.message, 'removeRecordConfirmation'); },
        trigger: (event, record) => refreshed.push([event, record.id]),
        removeRecordFromList: id => refreshed.push(['remove-row', id]),
    };
    await context.remove.call(list, {id: 'association-1'});
    assert.deepEqual(destroyed, [['SimpleJourneyRecordParent', 'association-1']]);
    assert.deepEqual(refreshed, [['after:delete', 'association-1'], ['remove-row', 'association-1']]);
});

test('native create-related helper maps source attributes to the correct child foreign keys', async () => {
    let captured;
    const context = {
        RecordModal: class {async showCreate(view, options) { captured = options; return options; }},
    };
    vm.runInNewContext(read('client/src/helpers/record/create-related.js')
        .replace(/^import .*;$/gm, '')
        .replace(/^\s*@inject\(Metadata\)\s*$/gm, '')
        .replace('export default CreateRelatedHelper;', 'globalThis.Helper = CreateRelatedHelper;'), context);
    for (const [type, link, expectedId] of [
        ['SimpleJourney', 'stages', 'journeyId'], ['SimpleJourney', 'records', 'journeyId'],
        ['SimpleJourneyStage', 'records', 'stageId'], ['SimpleJourneyRecord', 'parents', 'recordId'],
    ]) {
        const helper = new context.Helper({});
        const defs = resource(`metadata/entityDefs/${type}.json`);
        const clientDefs = resource(`metadata/clientDefs/${type}.json`);
        const values = {id: 'source-id', name: 'Source name', tenantId: 'tenant-1', tenantName: 'Workspace', journeyId: 'journey-1', journeyName: 'Onboarding'};
        helper.metadata = {get: () => clientDefs.relationshipPanels[link]};
        await helper.process({entityType: type, defs, get: key => values[key]}, link);
        assert.equal(captured.attributes[expectedId], 'source-id');
        assert.equal(captured.attributes[expectedId.replace(/Id$/, 'Name')], 'Source name');
        assert.equal(captured.relate.link, defs.links[link].foreign);
        assert.equal(captured.attributes.id, undefined, 'Never copy the source ID as the new child ID');
    }
});
