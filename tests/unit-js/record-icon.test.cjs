const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const Handlebars = require('handlebars');

function fixture(request = async () => ({list: []})) {
    const source = readFileSync(path.join(__dirname, '../../client/src/helpers/record-icon.js'), 'utf8')
        .replace(/^import .*;$/gm, '')
        .replace('export default RecordIcon;', 'module.exports = RecordIcon;');
    const context = {module: {exports: {}}, Ajax: {getRequest: request}, Handlebars, setTimeout, Date};
    vm.runInNewContext(source, context);
    const helper = {};
    const metadata = {
        get(key, fallback) {
            return {
                'clientDefs.Funnel.recordIconAttribute': 'icon',
                'clientDefs.Funnel.iconClass': 'fas fa-filter',
                'app.clientIcons.classList': ['ti ti-rocket'],
                'app.recordIcons.fontAwesomeClassList': ['fas fa-rocket', 'far fa-star'],
            }[key] ?? fallback;
        },
    };
    const removals = [];
    const view = {
        getHelper: () => helper,
        getMetadata: () => metadata,
        getAcl: () => ({checkScope: () => true}),
        once: (event, callback) => removals.push(callback),
    };
    return {icons: context.module.exports, view, metadata, removals};
}
const tick = () => new Promise(resolve => setTimeout(resolve, 10));

test('renders both registered families, escapes emoji text, and falls back for unknown icons', () => {
    const {icons, metadata} = fixture();
    assert.match(icons.html({type: 'icon', value: 'ti ti-rocket'}, metadata), /ti ti-rocket/);
    assert.match(icons.html({type: 'icon', value: 'far fa-star'}, metadata), /far fa-star/);
    assert.match(icons.html({type: 'emoji', value: '👩🏽‍💻'}, metadata), /👩🏽‍💻/);
    assert.doesNotMatch(icons.html({type: 'emoji', value: '<img onerror=x>🚀'}, metadata), /<img/);
    assert.match(icons.html({type: 'icon', value: 'ti ti-missing'}, metadata, 'fas fa-filter'), /fas fa-filter/);
    assert.equal(icons.html(null, metadata, 'x" onload="x'), '');
});

test('coalesces linked fields and deduplicates concurrent requests for the same record', async () => {
    const requests = [];
    const {icons, view} = fixture(async (scope, query) => {
        requests.push({scope, query});
        return {list: query.where[0].value.map(id => ({id, name: id, icon: {type: 'emoji', value: '🚀'}}))};
    });
    const one = icons.load(view, 'Funnel', 'a');
    assert.equal(one, icons.load(view, 'Funnel', 'a'));
    const two = icons.load(view, 'Funnel', 'b');
    assert.equal((await one).icon.value, '🚀');
    assert.equal((await two).name, 'b');
    assert.equal(requests.length, 1);
    assert.equal(requests[0].query.select, 'id,name,icon');
    assert.deepEqual(Array.from(requests[0].query.where[0].value), ['a', 'b']);
    await icons.load(view, 'Funnel', 'a');
    assert.equal(requests.length, 1);
});

test('large multi-links use bounded batches and unknown/inaccessible records resolve without icons', async () => {
    const sizes = [];
    const {icons, view} = fixture(async (scope, query) => {
        sizes.push(query.maxSize);
        return {list: []};
    });
    const results = await Promise.all(Array.from({length: 205}, (_, i) => icons.load(view, 'Funnel', String(i))));
    assert.deepEqual(sizes, [100, 100, 5]);
    assert.ok(results.every(value => value === null));
});

test('a saved removal wins over an in-flight response carrying an old icon', async () => {
    let respond;
    const {icons, view} = fixture(() => new Promise(resolve => { respond = resolve; }));
    const request = icons.load(view, 'Funnel', 'a');
    await tick();
    icons.remember(view, 'Funnel', {id: 'a', name: 'Renamed', icon: null}, true);
    respond({list: [{id: 'a', name: 'Old', icon: {type: 'emoji', value: '🚀'}}]});
    const result = await request;
    assert.equal(result.icon, null);
    assert.equal(result.name, 'Renamed');
});

test('failed requests can be retried; scope permissions prevent requests', async () => {
    let count = 0;
    const {icons, view} = fixture(async () => {
        count++;
        throw new Error('unavailable');
    });
    assert.equal(await icons.load(view, 'Funnel', 'a'), null);
    await tick();
    assert.equal(await icons.load(view, 'Funnel', 'a'), null);
    assert.equal(count, 2);
    view.getAcl = () => ({checkScope: () => false});
    assert.equal(await icons.load(view, 'Funnel', 'b'), null);
    assert.equal(count, 2);
});

test('save notifications are scoped to the UI session and released with the view', () => {
    const {icons, view, removals} = fixture();
    let calls = 0;
    icons.listen(view, () => { calls++; });
    icons.remember(view, 'Funnel', {id: 'a', icon: null}, true);
    assert.equal(calls, 1);
    removals.forEach(remove => { remove(); });
    icons.remember(view, 'Funnel', {id: 'a', icon: null}, true);
    assert.equal(calls, 1);
});
