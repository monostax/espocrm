const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function fixture(values = {}, previousType = null, edit = true) {
    let definition;
    let changeType;
    let selected = false;
    let warned = false;
    const Dep = {
        prototype: {setup() {}, actionSelect() { selected = true; }},
        extend(value) { return value; },
    };
    vm.runInNewContext(readFileSync(path.join(__dirname,
        '../../client/custom/modules/feature-initiative/src/views/fields/stage.js'), 'utf8'), {
        define(name, deps, factory) { definition = factory(Dep); },
        Espo: {Ui: {warning() { warned = true; }}},
    });
    const view = Object.assign({}, definition, {
        model: {
            get(key) { return values[key]; },
            set(attributes) { Object.assign(values, attributes); },
            previous() { return previousType; },
        },
        isEditMode() { return edit; },
        listenTo(model, event, callback) { changeType = callback; },
        translate(key) { return key; },
    });
    view.setup();
    return {view, values, changeType, selected: () => selected, warned: () => warned};
}

test('autocomplete and modal filters constrain stages to the selected initiative type', () => {
    const {view} = fixture({initiativeTypeId: 'type-1', initiativeTypeName: 'Onboarding'});
    const filter = view.getSelectFilters().initiativeType;
    assert.equal(filter.attribute, 'initiativeTypeId');
    assert.equal(filter.value, 'type-1');
    assert.equal(view.selectPrimaryFilterName, 'active');
});

test('without an initiative type autocomplete returns no stages and selection is blocked', () => {
    const f = fixture();
    assert.equal(f.view.getSelectFilters().noInitiativeType.type, 'isNull');
    f.view.actionSelect();
    assert.equal(f.warned(), true);
    assert.equal(f.selected(), false);
});

test('selection is allowed when an initiative type is present', () => {
    const f = fixture({initiativeTypeId: 'type-1'});
    f.view.actionSelect();
    assert.equal(f.selected(), true);
});

test('changing an initiative type while editing clears the old stage', () => {
    const f = fixture({initiativeTypeId: 'type-2', stageId: 'old-stage'}, 'type-1');
    f.changeType();
    assert.equal(f.values.stageId, null);
});

test('initial fetch and detail refresh do not clear the stage', () => {
    for (const [previous, edit] of [[null, true], ['type-1', false]]) {
        const f = fixture({initiativeTypeId: 'type-1', stageId: 'stage-1'}, previous, edit);
        f.changeType();
        assert.equal(f.values.stageId, 'stage-1');
    }
});
