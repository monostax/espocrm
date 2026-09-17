const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const Handlebars = require('handlebars');

const root = path.join(__dirname, '../../client/custom/modules/global');
const read = file => readFileSync(path.join(root, file), 'utf8');
function load(file, dependencies = {}, globals = {}) {
    let result;
    vm.runInNewContext(read('src/' + file), {
        define(name, names, factory) { result = factory(...names.map(name => dependencies[name])); },
        ...globals,
    });
    return result;
}
const Time = load('helpers/stage-time.js');
const Base = {extend: props => props, prototype: {data: () => ({})}};
const dependencies = {'views/fields/base': Base, 'global:helpers/stage-time': Time};

test('current timing crosses its deadline without a save and stops on closure', () => {
    const attrs = {status: 'Open', currentStageVisitId: 'visit', stageEnteredAt: '2026-09-17 10:00:00', stageTargetTimeSeconds: 3600};
    const label = value => value;
    assert.equal(Time.describe(attrs, label, Date.parse('2026-09-17T10:30:00Z')).text, '30m / 1h · Remaining 30m');
    assert.equal(Time.describe(attrs, label, Date.parse('2026-09-17T11:00:00Z')).overdue, false);
    assert.equal(Time.describe(attrs, label, Date.parse('2026-09-17T11:00:01Z')).overdue, true);
    assert.equal(Time.describe({...attrs, status: 'Won'}, label).text, 'Timing stopped');
    assert.equal(Time.describe({...attrs, status: 'Lost'}, label).active, false);
    assert.equal(Time.describe({...attrs, currentStageVisitId: null}, label).text, 'Timing unavailable');
    assert.match(Time.describe({...attrs, stageTargetTimeSeconds: null, stageTimingIsPartial: true}, label).text, /Partial history/);
});

test('target editor converts days/hours/minutes and allows clearing but rejects invalid targets', () => {
    const view = load('views/fields/time-budget.js', dependencies);
    const inputs = {days: '2', hours: '4', minutes: '30'};
    Object.assign(view, {
        name: 'targetTimeSeconds',
        $el: {find: selector => ({get: () => ({validity: {badInput: false}}),
            val: () => inputs[selector.match(/data-unit="(\w+)"/)[1]]})},
        translate: value => value,
        showValidationMessage: () => {},
    });
    assert.equal(view.fetch().targetTimeSeconds, 189000);
    assert.equal(view.validateBudget(), false);
    for (const values of [
        {days: '-1', hours: '', minutes: ''}, {days: '0', hours: '0', minutes: '0'},
        {days: '1.5', hours: '', minutes: ''}, {days: '999999', hours: '', minutes: ''},
    ]) {
        Object.assign(inputs, values);
        assert.equal(view.validateBudget(), true);
    }
    Object.assign(inputs, {days: '', hours: '', minutes: ''});
    assert.equal(view.fetch().targetTimeSeconds, null);
    assert.equal(view.validateBudget(), false);
});

test('history escapes stage labels and distinguishes partial results from complete results', () => {
    const view = load('views/opportunity/fields/stage-history.js', dependencies);
    Object.assign(view, {
        translate: label => label,
        getDateTime: () => ({toDisplay: value => value}),
        getLanguage: () => ({translateOption: value => value}),
        history: {
            total: 1, isPartial: true, trackingStartedAt: '2026-09-17 10:00:00', summary: [],
            list: [{kind: 'Visit', stageName: '<img src=x onerror=alert(1)>', isPartial: true,
                elapsedSeconds: 1800, targetTimeSeconds: 3600, overdueSeconds: 0}],
        },
    });
    Handlebars.registerHelper('translate', value => value);
    const html = Handlebars.compile(read('res/templates/opportunity/fields/stage-history.tpl'))(view.data());
    assert.doesNotMatch(html, /<img/);
    assert.match(html, /&lt;img/);
    assert.match(html, /Observed within target/);
    assert.match(html, /Partial history explanation/);
});

test('late history responses cannot overwrite a newer stage and failed reads clear stale rows', async () => {
    const responses = [];
    const view = load('views/opportunity/fields/stage-history.js', dependencies, {
        Espo: {Ajax: {getRequest: () => new Promise((resolve, reject) => responses.push({resolve, reject}))}},
    });
    Object.assign(view, {model: {id: 'opportunity'}, requestVersion: 0, isRendered: () => false});
    const oldRequest = view.loadHistory();
    const newRequest = view.loadHistory();
    responses[1].resolve({list: [{stageName: 'New'}]});
    await newRequest;
    responses[0].resolve({list: [{stageName: 'Old'}]});
    await oldRequest;
    assert.equal(view.history.list[0].stageName, 'New');
    const denied = view.loadHistory();
    responses[2].reject(new Error('Forbidden'));
    await denied;
    assert.equal(view.failed, true);
    assert.equal(view.history.list.length, 0);
});

test('new and modified templates compile', () => {
    for (const file of ['fields/time-budget/edit', 'fields/time-budget/detail', 'opportunity/fields/stage-timing',
        'opportunity/fields/stage-history', 'opportunity/record/kanban-item']) {
        Handlebars.precompile(read(`res/templates/${file}.tpl`));
    }
});
