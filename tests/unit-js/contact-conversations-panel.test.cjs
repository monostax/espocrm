const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const Backbone = require('backbone');

const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const opportunityDefs = JSON.parse(read(
    'custom/Espo/Modules/Global/Resources/metadata/clientDefs/Opportunity.json'
)).sidePanels.detail.find(panel => panel.name === 'contactConversations');
const opportunityUrl = 'Opportunity/opportunity/chatwootConversations';
const contactUrl = 'Contact/primary/chatwootConversations';
const tick = () => new Promise(resolve => setImmediate(resolve));

function fixture({
    entityType = 'Opportunity',
    attributes = {id: 'opportunity', contactId: 'primary'},
    defs = opportunityDefs,
    canRead = () => true,
} = {}) {
    const conversations = [
        {id: 'first', contactId: 'primary'},
        {id: 'second', contactId: 'other'},
        {id: 'unrelated', contactId: 'primary'},
    ];
    const relations = {
        [opportunityUrl]: ['first', 'second'],
        [contactUrl]: ['first', 'unrelated'],
    };
    const requests = [];
    const posts = [];
    const events = [];
    const model = new Backbone.Model(attributes);
    model.entityType = entityType;
    model.on('all', event => events.push(event));
    const collection = new Backbone.Collection();
    collection.setOrder = function (orderBy, order) {
        Object.assign(this, {orderBy, order});
    };
    const ui = {notifyWait() {}, notify() {}, success() {}, warning() {}};
    const ajax = {
        async getRequest(url, params) {
            requests.push({url, params});
            const list = (relations[url] || []).map(id => conversations.find(row => row.id === id));
            return {list, total: list.length};
        },
        async postRequest(url, payload) {
            posts.push({url, ids: [...payload.ids]});
            relations[url] = [...new Set([...(relations[url] || []), ...payload.ids])];
        },
    };
    let definition;
    vm.runInNewContext(read('client/custom/modules/chatwoot/src/views/contact/panels/conversations.js'), {
        define(_name, _dependencies, factory) {
            definition = factory({extend: props => props, prototype: {setup() {}}});
        },
        Espo: {Ajax: ajax, Ui: ui},
    });
    const view = Object.assign(Object.create(definition), Backbone.Events, {
        model,
        options: {defs},
        getAcl: () => ({check: scope => canRead(scope)}),
        getHelper: () => ({getScopeColorIconHtml: () => ''}),
        getCollectionFactory: () => ({create: (_scope, callback) => callback(collection)}),
        translate: label => label,
        wait() {},
        isRendered: () => false,
        createView(_name, _type, _options, callback) {
            this.dialog = Object.assign({render() {}}, Backbone.Events);
            callback(this.dialog);
        },
    });
    view.setup();
    return {view, model, collection, relations, requests, posts, events, ajax};
}

test('Opportunity lists all direct links across contacts and excludes unlinked contact conversations', async () => {
    const {view, collection, requests} = fixture();
    await tick();

    assert.equal(view.hasData, true);
    assert.deepEqual(collection.pluck('id'), ['first', 'second']);
    assert.equal(collection.total, 2);
    assert.equal(requests[0].url, opportunityUrl);
    // Native list sorting and Show More fetch this collection, rather than the panel's Ajax request.
    assert.equal(collection.url, opportunityUrl);
    assert.equal(collection.urlRoot, opportunityUrl);
    assert.equal(collection.orderBy, 'lastActivityAt');
    assert.equal(collection.order, 'desc');
});

test('direct links do not depend on a primary contact or Contact read access', async () => {
    const {view, collection} = fixture({
        attributes: {id: 'opportunity'},
        canRead: scope => scope !== 'Contact',
    });
    await tick();

    assert.equal(view.hasData, true);
    assert.deepEqual(collection.pluck('id'), ['first', 'second']);

    const unsaved = fixture({attributes: {contactId: 'primary'}});
    const denied = fixture({canRead: scope => scope !== 'ChatwootConversation'});
    await tick();
    assert.equal(unsaved.view.hasData, false);
    assert.equal(unsaved.requests.length, 0);
    assert.equal(denied.view.hasData, false);
    assert.equal(denied.requests.length, 0);
});

test('Assign links to the opportunity and refreshes its relationship panels', async () => {
    const {view, collection, relations, posts, events} = fixture();
    await tick();
    view.actionSelectConversation();
    view.dialog.trigger('select', [new Backbone.Model({id: 'unrelated'})]);
    await tick();

    assert.deepEqual(posts, [{url: opportunityUrl, ids: ['unrelated']}]);
    assert.deepEqual(relations[contactUrl], ['first', 'unrelated']);
    assert.deepEqual(collection.pluck('id'), ['first', 'second', 'unrelated']);
    for (const event of ['update-related:chatwootConversations', 'after:relate', 'after:relate:chatwootConversations']) {
        assert.ok(events.includes(event), `Missing ${event}`);
    }
});

test('refresh buttons and relationship updates reload direct links with the selected order', async () => {
    const {view, model, collection, relations, requests} = fixture();
    await tick();
    collection.setOrder('name', 'asc');
    relations[opportunityUrl] = ['second'];
    await view.actionRefresh();
    await view.actionRefreshConversations();
    model.trigger('update-related:chatwootConversations');
    await tick();

    assert.deepEqual(collection.pluck('id'), ['second']);
    assert.equal(requests.length, 4);
    for (const request of requests.slice(1)) {
        assert.equal(request.url, opportunityUrl);
        assert.equal(request.params.orderBy, 'name');
        assert.equal(request.params.order, 'asc');
    }
});

test('Contact panels still list and assign the contact relationship', async () => {
    const defs = JSON.parse(read(
        'custom/Espo/Modules/Global/Resources/metadata/clientDefs/Contact.json'
    )).sidePanels.detail.find(panel => panel.name === 'contactConversations');
    const {view, collection, posts, relations} = fixture({entityType: 'Contact', attributes: {id: 'primary'}, defs});
    await tick();
    assert.deepEqual(collection.pluck('id'), ['first', 'unrelated']);
    view.actionSelectConversation();
    view.dialog.trigger('select', new Backbone.Model({id: 'second'}));
    await tick();

    assert.deepEqual(posts, [{url: contactUrl, ids: ['second']}]);
    assert.deepEqual(relations[opportunityUrl], ['first', 'second']);
    assert.deepEqual(collection.pluck('id'), ['first', 'unrelated', 'second']);
});

test('indirect panels still wait for a contact and enforce polymorphic contact types', async () => {
    const {view, model, requests, collection} = fixture({
        entityType: 'Call',
        attributes: {id: 'call', parentType: 'Account'},
        defs: {contactIdAttribute: 'parentId', contactTypeAttribute: 'parentType', requiredContactType: 'Contact'},
    });
    assert.equal(view.hasData, false);
    model.set('parentId', 'primary');
    await tick();
    assert.equal(requests.length, 0);
    model.set('parentType', 'Contact');
    await tick();
    assert.equal(view.hasData, true);
    assert.deepEqual(collection.pluck('id'), ['first', 'unrelated']);
    assert.equal(requests[0].url, contactUrl);
    model.unset('parentId');
    await view.actionRefreshConversations();
    assert.equal(view.hasData, false);
    assert.equal(requests.length, 1);
});
