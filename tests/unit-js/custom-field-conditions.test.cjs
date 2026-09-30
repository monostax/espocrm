const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '../../client/custom/modules/global/src');
function load(file, dependencies = []) {
    let exported;
    vm.runInNewContext(readFileSync(path.join(root, file), 'utf8'), {
        define: (name, deps, factory) => { exported = factory(...dependencies); },
        Espo: {Utils: {clone: structuredClone}},
    });
    return exported;
}
const conditions = load('helpers/custom-field-conditions.js');
const Base = {extend: definition => definition, prototype: {setup() {}}};
const methods = load('views/fields/custom-fields.js', [Base, conditions]);
const scope = {attribute: 'funnelId', operator: 'in', value: ['solar']};

for (const fixture of JSON.parse(readFileSync(path.join(__dirname, '../fixtures/custom-field-conditions.json'), 'utf8'))) {
    test(`Shared operator contract: ${fixture.name}`, () => {
        assert.equal(conditions.evaluate(fixture.condition, fixture.context, fixture.partial || false), fixture.expected);
    });
}

test('nested all/any conditions use strict matching and unknown partial context', () => {
    const rule = {all: [scope, {any: [
        {attribute: 'opportunityStageId', operator: 'equals', value: 'proposal'},
        {attribute: 'status', operator: 'equals', value: 'Won'},
    ]}]};
    assert.equal(conditions.evaluate(null, {}), true);
    assert.equal(conditions.evaluate(rule, {funnelId: 'solar', opportunityStageId: 'proposal'}), true);
    assert.equal(conditions.evaluate(rule, {funnelId: 'other', status: 'Won'}), false);
    assert.equal(conditions.evaluate(rule, {funnelId: 'solar'}), false);
    assert.equal(conditions.evaluate(rule, {funnelId: 'solar'}, true), null);
    assert.equal(conditions.evaluate(rule, {funnelId: 'other'}, true), false);
});

test('requiredness and empty groups follow applicability without changing the catalog', () => {
    const field = {valueKey: 'budget', appliesWhen: scope,
        requiredWhen: {attribute: 'status', operator: 'equals', value: 'Won'}};
    const meta = {groups: [{name: 'solar', fields: [field]}]};
    assert.equal(conditions.groups(meta, {funnelId: 'other', status: 'Won'}).length, 0);
    assert.equal(conditions.groups(meta, {funnelId: 'solar', status: 'Open'})[0].fields[0].isRequired, false);
    assert.equal(conditions.groups(meta, {funnelId: 'solar', status: 'Won'})[0].fields[0].isRequired, true);
    assert.equal(field.isRequired, undefined);
    assert.equal(conditions.required({...field, isRequired: true}, {funnelId: 'other'}), false);
});

function form() {
    const attributes = {funnelId: 'solar', customFields: {budget: 10, hidden: 'keep'}};
    const views = new Map();
    const listeners = {};
    const f = Object.assign({}, methods, {
        name: 'customFields',
        model: {attributes, get: name => attributes[name], hasChanged: () => true},
        listenTo: (target, events, callback) => { listeners[events] = callback; },
        wait() {},
        loadMeta: async () => {},
        getView: key => views.get(key),
        isEditMode: () => true,
        isRendered: () => true,
        reRender() {
            this._renderedFields = this.getScopedFields();
            views.clear();
        },
    });
    f.setup();
    f.meta = {groups: [{name: 'solar', fields: [{valueKey: 'budget', appliesWhen: scope}]}]};
    f._renderedFields = f.getScopedFields();
    return {f, attributes, views, listeners};
}

test('changing scope preserves unsaved input and restores it when returning', () => {
    const {f, attributes, views, listeners} = form();
    views.set('cf-budget', {fetch: () => ({budget: 25})});
    attributes.funnelId = 'other';
    listeners.change();
    assert.equal(f.getFlatFields().length, 0);
    assert.equal(f.fetch().customFields.budget, 25);
    assert.equal(f.fetch().customFields.hidden, 'keep');
    attributes.funnelId = 'solar';
    listeners.change();
    assert.equal(f.getValues().budget, 25);
    assert.equal(f.getFlatFields().length, 1);
});

test('clearing an edited field preserves unrelated and hidden values', () => {
    const {f, views} = form();
    views.set('cf-budget', {fetch: () => ({budget: null})});
    assert.equal(f.fetch().customFields.budget, undefined);
    assert.equal(f.fetch().customFields.hidden, 'keep');
});

test('drafts survive scope changes on new records with no bag', () => {
    const {f, attributes, views, listeners} = form();
    attributes.customFields = null;
    views.set('cf-budget', {fetch: () => ({budget: 0})});
    attributes.funnelId = 'other';
    listeners.change();
    assert.equal(f.fetch().customFields.budget, 0);
});

test('completion dialog trusts destination applicability already evaluated by the server', async () => {
    const dialog = load('views/opportunity/fields/stage-required-values.js', [Base]);
    const f = Object.assign({}, methods, dialog, {
        options: {fields: [{valueKey: 'budget', appliesWhen: scope, isRequired: true}]},
        model: {attributes: {}},
    });
    await f.loadMeta();
    assert.equal(f.getFlatFields().length, 1);
    assert.equal(f.getFlatFields()[0].isRequired, true);
});

test('direct-field dependencies drive visibility and requiredness and ignore unrelated changes', () => {
    const {f, attributes, views, listeners} = form();
    f.meta = {groups: [{name: 'details', fields: [{valueKey: 'budget',
        appliesWhen: {attribute: 'type', operator: 'equals', value: 'Customer'},
        requiredWhen: {attribute: 'tags', operator: 'containsAny', value: ['vip']}}]}]};
    attributes.type = 'Customer';
    attributes.tags = [];
    f._renderedFields = f.getScopedFields();
    views.set('cf-budget', {fetch: () => ({budget: 123})});
    f.model.hasChanged = attribute => attribute === 'tags';
    attributes.tags = ['vip'];
    listeners.change();
    assert.equal(f.getFlatFields()[0].isRequired, true);
    assert.equal(f.getValues().budget, 123);
    f.model.hasChanged = attribute => attribute === 'type';
    attributes.type = 'Investor';
    listeners.change();
    assert.equal(f.getFlatFields().length, 0);
    assert.equal(f.fetch().customFields.budget, 123);
    f.model.hasChanged = attribute => attribute === 'name';
    f.reRender = () => { throw new Error('Unrelated change rerendered the form'); };
    listeners.change();
});
