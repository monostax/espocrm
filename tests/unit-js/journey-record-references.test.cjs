const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const client = '../../client/custom/modules/feature-journey/src/';
function load(file, deps, extra = {}) {
    let result;
    vm.runInNewContext(readFileSync(path.join(__dirname, client, file), 'utf8'), {
        define(name, names, factory) { result = factory(...deps); },
        _: {extend: Object.assign},
        ...extra,
    });
    return result;
}

function contextFixture(reference) {
    const values = {stageId: 'D2', targetReference: reference};
    const requests = [];
    const Helper = load('helpers/custom-fields.js', [], {
        Espo: {Ajax: {async getRequest(url) {
            requests.push(url);
            if (url === 'JourneyStage/D2') return {journeyId: 'journey-1'};
            if (url === 'Journey/journey-1') return {targetEntityType: 'Account', tenantId: 'tenant-1'};
            return {list: [{key: 'deal', entityType: 'Opportunity'}]};
        }}},
    });
    const helper = new Helper({model: {entityType: 'JourneyStageAction', get: (key) => values[key]}});
    return {helper, values, requests};
}

test('conditions and expressions use the saved Opportunity entity type', async () => {
    const {helper} = contextFixture('deal');
    const context = await helper.resolveContext();
    assert.equal(context.entityType, 'Opportunity');
    assert.equal(context.tenantId, 'tenant-1');
    assert.equal(context.journeyId, 'journey-1');
});

test('returning to the enrolled record restores Account fields', async () => {
    const {helper, values} = contextFixture('deal');
    await helper.resolveContext();
    values.targetReference = '';
    assert.equal((await helper.resolveContext()).entityType, 'Account');
});

test('an unknown reference does not offer misleading Account fields', async () => {
    const {helper} = contextFixture('missing');
    assert.equal((await helper.resolveContext()).entityType, null);
});

test('the target selector offers saved records, excludes its own output, and retains unresolved selections', async () => {
    const Dep = {prototype: {setup() {}}, extend: (value) => value};
    const Helper = function () {};
    Helper.prototype.resolveJourneyContext = async () => ({journeyId: 'journey-1'});
    const definition = load('views/journey-stage-action/fields/target-reference.js', [Dep, Helper], {
        Espo: {Ajax: {async getRequest() {
            return {list: [
                {key: 'deal', entityType: 'Opportunity', actionId: 'create-deal', actionName: 'Create deal'},
                {key: 'ownTask', entityType: 'Task', actionId: 'current-action', actionName: 'Create task'},
            ]};
        }}},
    });
    let ready;
    const view = Object.assign({}, definition, {
        name: 'targetReference', params: {}, isReady: false,
        model: {id: 'current-action', get: () => 'missing'},
        translate: (key) => key,
        listenTo() {},
        wait(promise) { ready = promise; },
        setOptionList() { assert.fail('Must not re-render while setup is awaiting references'); },
    });
    view.setup();
    await ready;
    assert.deepEqual(Array.from(view.params.options), ['', 'deal', 'missing']);
    assert.match(view.translatedOptions.deal, /Opportunity/);
});

test('Create Record field choices describe the new record, not the enrolled Account', async () => {
    const Dep = {extend: (value) => value};
    const definition = load('views/journey-stage-action/fields/params.js', [Dep, {}, {}, {}]);
    const view = Object.assign({}, definition, {
        model: {get: () => 'createRecord'},
        _helperModel: {get: () => 'Opportunity'},
        cfHelper: {
            resolveContext: async () => ({entityType: 'Account', tenantId: 'tenant-1'}),
            isEntityEnabled: () => false,
        },
        getMetadata: () => ({get(keys) {
            if (keys.includes('fieldsByEntityType')) {
                assert.equal(keys.at(-1), 'Opportunity');
                return ['name', 'stage', 'amount', 'accountId'];
            }
            return true;
        }}),
    });
    const options = await view.loadFieldMapOptions();
    assert.deepEqual(Array.from(options, (item) => item.value), ['name', 'stage', 'amount', 'accountId']);
});

test('related-record fields follow the currently typed link', async () => {
    const definition = load('views/journey-stage-action/fields/params.js', [{extend: (value) => value}, {}, {}, {}]);
    const view = Object.assign({}, definition, {
        model: {get: () => 'createRelatedRecord'},
        _helperModel: {get: () => 'oldLink'},
        _expressionInputs: {link: {getState: () => ({mode: 'fixed', fixed: 'opportunities'})}},
        cfHelper: {
            resolveContext: async () => ({entityType: 'Account', tenantId: 'tenant-1'}),
            isEntityEnabled: () => false,
        },
        getMetadata: () => ({get(keys) {
            if (keys[0] === 'entityDefs') {
                assert.equal(keys[3], 'opportunities');
                return 'Opportunity';
            }
            if (keys.includes('fieldsByEntityType')) return ['amount'];
            return true;
        }}),
    });
    assert.deepEqual(Array.from(await view.loadFieldMapOptions(), (item) => item.value), ['amount']);
});

test('a stale target lookup cannot overwrite snippets for the latest selection', async () => {
    const definition = load('views/journey-stage-action/fields/params.js', [
        {extend: (value) => value}, {}, {}, {defaultSnippets: (view, type) => [type]},
    ]);
    const pending = [];
    const view = Object.assign({}, definition, {
        _renderGeneration: 1,
        cfHelper: {resolveContext: () => new Promise((resolve) => pending.push(resolve))},
    });
    const oldLoad = view.resolveSnippets();
    view._renderGeneration = 2;
    const newLoad = view.resolveSnippets();
    pending[1]({entityType: 'Opportunity'});
    await newLoad;
    pending[0]({entityType: 'Account'});
    await oldLoad;
    assert.deepEqual(Array.from(view._snippets), ['Opportunity']);
});
