const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function fixture(values = {}, previousJourney = null, edit = true) {
    let definition;
    let changeJourney;
    let selected = false;
    let warned = false;
    const Dep = {
        prototype: {setup() {}, actionSelect() { selected = true; }},
        extend(value) { return value; },
    };
    vm.runInNewContext(readFileSync(path.join(__dirname,
        '../../client/custom/modules/feature-simple-journey/src/views/fields/stage.js'), 'utf8'), {
        define(name, deps, factory) { definition = factory(Dep); },
        Espo: {Ui: {warning() { warned = true; }}},
    });
    const view = Object.assign({}, definition, {
        model: {
            get(key) { return values[key]; },
            set(attributes) { Object.assign(values, attributes); },
            previous() { return previousJourney; },
        },
        isEditMode() { return edit; },
        listenTo(model, event, callback) { changeJourney = callback; },
        translate(key) { return key; },
    });
    view.setup();
    return {view, values, changeJourney, selected: () => selected, warned: () => warned};
}

test('autocomplete and modal filters constrain stages to the selected journey', () => {
    const {view} = fixture({journeyId: 'journey-1', journeyName: 'Onboarding'});
    const filter = view.getSelectFilters().journey;
    assert.equal(filter.attribute, 'journeyId');
    assert.equal(filter.value, 'journey-1');
    assert.equal(view.selectPrimaryFilterName, 'active');
});

test('without a journey autocomplete returns no stages and selection is blocked', () => {
    const f = fixture();
    assert.equal(f.view.getSelectFilters().noJourney.type, 'isNull');
    f.view.actionSelect();
    assert.equal(f.warned(), true);
    assert.equal(f.selected(), false);
});

test('selection is allowed when a journey is present', () => {
    const f = fixture({journeyId: 'journey-1'});
    f.view.actionSelect();
    assert.equal(f.selected(), true);
});

test('changing a journey while editing clears the old stage', () => {
    const f = fixture({journeyId: 'journey-2', stageId: 'old-stage'}, 'journey-1');
    f.changeJourney();
    assert.equal(f.values.stageId, null);
});

test('initial fetch and detail refresh do not clear the stage', () => {
    for (const [previous, edit] of [[null, true], ['journey-1', false]]) {
        const f = fixture({journeyId: 'journey-1', stageId: 'stage-1'}, previous, edit);
        f.changeJourney();
        assert.equal(f.values.stageId, 'stage-1');
    }
});
