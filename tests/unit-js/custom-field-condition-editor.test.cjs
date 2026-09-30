const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '../../client/custom/modules/global/src');
function load(file, dependencies, globals = {}) {
    let exported;
    vm.runInNewContext(readFileSync(path.join(root, file), 'utf8'), {
        define: (name, deps, factory) => { exported = factory(...dependencies); },
        ...globals,
    });
    return exported;
}
const Conditions = load('helpers/custom-field-conditions.js', []);
const Base = {extend: definition => definition};

test('condition editor loads metadata capabilities and only fetches names of selected links', async () => {
    const requests = [];
    const attributes = {
        source: {field: 'source', type: 'enum', operators: ['in', 'equals'], options: ['Campaign']},
        accountId: {field: 'account', type: 'link', entity: 'Account', tenantScoped: true, operators: ['in', 'equals', 'isEmpty']},
        tags: {field: 'tags', type: 'multiEnum', operators: ['containsAny', 'containsAll'], options: [], allowCustomOptions: true},
    };
    const editor = load('views/custom-field-def/fields/conditions.js', [Base, Conditions], {
        Espo: {Ajax: {getRequest: async (url, params) => {
            requests.push({url, params});
            if (url === 'CustomField/action/conditionAttributes') return {attributes};
            if (url === 'Account/account-a') return {id: 'account-a', name: 'Acme'};
            throw new Error('Unexpected unbounded link request: ' + url);
        }}},
    });
    const f = Object.assign({}, editor, {
        model: {get: key => key === 'entityType' ? 'Contact' : null},
        linkNames: {}, condition: {all: [
            {attribute: 'accountId', operator: 'equals', value: 'account-a'},
            {attribute: 'accountId', operator: 'in', value: ['account-a']},
        ]},
    });
    await f.loadChoices();
    assert.equal(requests.length, 2);
    assert.equal(requests[0].params.entityType, 'Contact');
    assert.equal(f.choices.tags.allowCustomOptions, true);
    assert.equal(f.linkNames['Account/account-a'], 'Acme');
    assert.equal(f.newLeaf('tags').operator, 'containsAny');
    assert.equal('value' in f.newLeaf('accountId', 'isEmpty'), false);
});

test('record selector scopes tenant links and persists IDs, not labels', async () => {
    const editor = load('views/custom-field-def/fields/conditions.js', [Base, Conditions]);
    let pickerOptions;
    let selected;
    let result;
    let closed = false;
    const view = {render() {}, close() { closed = true; }};
    const f = Object.assign({}, editor, {
        model: {get: key => ({tenantId: 'tenant-a', tenantName: 'Workspace A'})[key]},
        linkNames: {}, changed() {},
        createView: async (name, type, options) => { pickerOptions = options; return view; },
        listenToOnce: (target, event, callback) => { selected = callback; },
    });
    await f.selectRecords({entity: 'Account', tenantScoped: true}, {operator: 'in'}, ['existing'], values => { result = values; });
    assert.equal(pickerOptions.scope, 'Account');
    assert.equal(pickerOptions.filters.tenant.value, 'tenant-a');
    assert.equal(pickerOptions.multiple, true);
    selected([{id: 'account-a', attributes: {id: 'account-a', name: 'Acme'}}]);
    assert.deepEqual(Array.from(result), ['existing', 'account-a']);
    assert.equal(f.linkNames['Account/account-a'], 'Acme');
    assert.equal(closed, true);
});
