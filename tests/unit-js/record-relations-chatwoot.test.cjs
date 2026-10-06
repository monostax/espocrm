const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

function load(client = {}) {
    const file = path.resolve(__dirname, '../../../../chatwoot/source/app/javascript/dashboard/api/crm/recordRelations.js');
    const code = ts.transpileModule(fs.readFileSync(file, 'utf8'), {
        compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, esModuleInterop: false},
    }).outputText;
    const exports = {};
    vm.runInNewContext(code, {exports, require: name => name === 'axios' ? {default: client} : {
        crmClient: client,
        default: {getWorkspaceWhere: async () => [{value: 'tenant'}]},
    }});
    return exports;
}
const identity = {recordType: 'Initiative', recordId: 'initiative'};
const relation = (id, overrides = {}) => ({id, subjectType: 'Initiative', subjectId: 'initiative',
    objectType: 'Task', objectId: 'task', predicateLabel: 'contains', inverseLabel: 'part of',
    status: 'confirmed', origin: 'manual', canUnlink: true, ...overrides});

test('combined cards retain native associations and several directional labels without duplicate cards', async () => {
    const {mergeRelations} = load();
    const result = await mergeRelations([{id: 'task', entityType: 'Task'}], [
        relation('out'), relation('in', {subjectType: 'Task', subjectId: 'task', objectType: 'Initiative', objectId: 'initiative'}),
        relation('suggestion', {status: 'suggested'}), relation('stale', {status: 'stale'}),
        relation('native', {origin: 'native'}), relation('other-type', {objectType: 'Account'}),
    ], identity, ['Task'], () => { throw new Error('Native card must not be rehydrated.'); });
    assert.equal(result.records.length, 1);
    assert.equal(result.labels['Task:task'].length, 3);
    assert.equal(result.labels['Task:task'][0].origin, 'native');
    assert.equal(result.labels['Task:task'][1].label, 'contains');
    assert.equal(result.labels['Task:task'][2].label, 'part of');
    const unlinked = await mergeRelations(result.records, [], identity, ['Task'], () => {});
    assert.equal(unlinked.records.length, 1);
});

test('generic records hydrate once per typed identity and inaccessible targets are omitted', async () => {
    const {mergeRelations} = load();
    const hydrated = [];
    const result = await mergeRelations([], [relation('one'), relation('two'), relation('call', {objectType: 'Call'}),
        relation('hidden', {objectId: 'hidden'})], identity, ['Task', 'Call'], async (type, id) => {
        hydrated.push(`${type}:${id}`);
        if (id === 'hidden') throw {response: {status: 403}};
        return {entityType: type, id};
    });
    assert.deepEqual(hydrated.sort(), ['Call:task', 'Task:hidden', 'Task:task']);
    assert.equal(result.records.length, 2);
    assert.equal(result.labels['Task:task'].length, 2);
    assert.equal(result.labels['Task:hidden'], undefined);
});

test('conversation CRM identities merge onto the same external Chatwoot card', async () => {
    const {mergeRelations} = load();
    const result = await mergeRelations([{id: 42}], [relation('conversation', {objectType: 'ChatwootConversation', objectId: 'crm-id'})],
        identity, ['ChatwootConversation'], async () => ({id: 42}));
    assert.equal(result.records.length, 1);
    assert.equal(result.labels['ChatwootConversation:42'].length, 2);
});

test('relation pagination continues past empty ACL-filtered pages and scopes requests to the workspace', async () => {
    const requests = [];
    const api = load({get: async (url, options) => {
        requests.push({url, ...options.params});
        return {data: requests.length === 1 ? {list: [], cursor: 'r:next'} : {list: [relation('last')], cursor: null}};
    }}).default;
    const result = await api.list(identity, '1');
    assert.equal(result.length, 1);
    assert.equal(requests[1].cursor, 'r:next');
    assert.ok(requests.every(item => item.tenantId === 'tenant' && item.status === 'confirmed'));
});

test('incoming manual links reverse endpoints and unlink only the association', async () => {
    const requests = [];
    const api = load({post: async (url, data) => {requests.push({url, data}); return {data};}}).default;
    await api.author({identity, reverse: true, predicate: 'builtin:part_of', qualifiers: {}, tenantId: 'tenant', idempotencyKey: 'retry'},
        {entityType: 'Task', recordId: 'task'});
    assert.equal(requests[0].url, '/RecordKnowledge/author');
    assert.equal(requests[0].data.subjectType, 'Task');
    assert.equal(requests[0].data.objectId, 'initiative');
    assert.equal(requests[0].data.sourceRevisionId, undefined);
    await api.unlink('relation');
    assert.equal(requests[1].url, '/RecordKnowledge/unlink');
    assert.equal(requests[1].data.id, 'relation');
    assert.equal(requests[1].data.parentId, undefined);
});
