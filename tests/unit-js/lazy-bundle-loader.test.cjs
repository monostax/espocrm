const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const {Bundler} = require('espo-frontend-build-tools');
const bundleOpportunityTable = require('../../js/bundle-opportunity-table');

const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const libsConfig = JSON.parse(read('application/Espo/Resources/metadata/app/jsLibs.json'));
const gridstackLib = JSON.parse(read('frontend/libs.json')).find(lib => lib.amdId === 'gridstack');

function loaderContext(developerMode = false) {
    const scripts = [];
    const warnings = [];
    const params = {
        cacheTimestamp: 'test',
        basePath: '',
        internalModuleList: [],
        transpiledModuleList: [],
        libsConfig,
        aliasMap: Object.fromEntries(Object.keys(libsConfig).map(id => [id, `lib!${id}`])),
    };
    const context = vm.createContext({
        URL,
        console: {...console, warn: message => warnings.push(message)},
        location: {origin: 'https://crm.example.test', pathname: '/'},
        document: {
            currentScript: null,
            querySelector: () => ({textContent: JSON.stringify(params)}),
            createElement(tag) {
                assert.equal(tag, 'script');
                return {
                    events: {},
                    addEventListener(name, callback) { this.events[name] = callback; },
                };
            },
            head: {appendChild(script) { scripts.push(script); }},
        },
    });
    context.window = context;
    context.self = context;
    vm.runInContext(read('client/src/loader.js'), context);
    context.Espo.loader.setIsDeveloperMode(developerMode);
    return {context, scripts, warnings};
}

for (const developerMode of [false, true]) {
    test(`lazy GridStack resolves its AMD export (developer mode: ${developerMode})`, async () => {
        const {context, scripts, warnings} = loaderContext(developerMode);
        const {loader} = context.Espo;
        // jQuery UI and touch-punch are already present in the core bundle.
        const jquery = {ui: {}};
        loader.define('jquery', [], () => jquery);

        const first = loader.requirePromise('gridstack');
        const concurrent = loader.requirePromise('gridstack');
        assert.equal(scripts.length, 1);
        const script = scripts[0];
        const expectedPath = developerMode ? libsConfig.gridstack.devPath : libsConfig.gridstack.path;
        assert.equal(new URL(script.src).pathname, `/${expectedPath}`);

        context.document.currentScript = script;
        // Execute the installed UMD library, not a mocked GridStack export.
        vm.runInContext(read(gridstackLib.src), context, {filename: expectedPath});
        context.document.currentScript = null;
        script.events.load();

        const GridStack = await first;
        assert.equal(typeof GridStack, 'function');
        assert.equal(await concurrent, GridStack);
        assert.equal(await loader.requirePromise('gridstack'), GridStack);
        assert.equal(scripts.length, 1);
        assert.deepEqual(warnings, []);
        assert.equal(typeof GridStack.init, 'function');
        assert.deepEqual(GridStack.Utils.sort([
            {id: 'bottom', x: 0, y: 1},
            {id: 'right', x: 1, y: 0},
            {id: 'left', x: 0, y: 0},
        ]).map(item => item.id), ['left', 'right', 'bottom']);
    });
}

let bundles;
function buildBundles() {
    if (bundles) return bundles;
    const cwd = process.cwd();
    try {
        // Uses the transpiled sources prepared by `grunt transpile`.
        process.chdir(root);
        bundles = new Bundler(
            JSON.parse(read('frontend/bundle-config.json')),
            JSON.parse(read('frontend/libs.json')),
        ).bundle();
        return bundles;
    } finally {
        process.chdir(cwd);
    }
}

test('every lazy bundle preserves the running namespace and loader', () => {
    for (const [name, source] of Object.entries(buildBundles())) {
        if (name === 'main') continue;
        const {context, scripts} = loaderContext();
        const namespace = context.Espo;
        const {loader} = namespace;
        // Only register modules here; their factories need their own UI dependencies.
        const define = () => {};
        context.define = define;
        vm.runInContext(source, context, {filename: `espo-${name}.js`});
        assert.equal(context.Espo, namespace, `${name} replaced the running namespace`);
        assert.equal(context.Espo.loader, loader, `${name} replaced the running loader`);
        assert.equal(context.define, define, `${name} replaced the AMD entry point`);
        assert.equal(scripts.length, 0, `${name} fetched dependencies while registering modules`);
    }
});

test('phone and foreign-phone resolve from one lazy bundle without fetching source files', async () => {
    const bundles = buildBundles();
    const {context, scripts, warnings} = loaderContext();
    const namespace = context.Espo;
    const {loader} = namespace;
    // Apply the generated bundle mappings without executing main's UI factories.
    vm.runInNewContext(bundles.main, {define() {}, Espo: {loader}});
    class VarcharFieldView {}
    loader.define('views/fields/varchar', [], () => VarcharFieldView);
    loader.define('ui/select', [], () => ({}));
    context.intlTelInput = () => {};
    context.intlTelInputGlobals = {};

    const phonePromise = loader.requirePromise('views/fields/phone');
    const foreignPromise = loader.requirePromise('views/fields/foreign-phone');
    assert.equal(scripts.length, 1);
    assert.equal(new URL(scripts[0].src).pathname, '/client/lib/intl-tel-input-utils.js');
    vm.runInContext(read('node_modules/intl-tel-input/build/js/utils.js'), context);
    scripts[0].events.load();
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(scripts.length, 2);
    assert.equal(scripts[1].src, 'client/lib/espo-phone.js?r=test');
    vm.runInContext(bundles.phone, context, {filename: 'espo-phone.js'});
    assert.equal(context.Espo, namespace);
    assert.equal(context.Espo.loader, loader);
    scripts[1].events.load();

    const [Phone, ForeignPhone] = await Promise.all([phonePromise, foreignPromise]);
    assert.equal(Object.getPrototypeOf(Phone), VarcharFieldView);
    assert.equal(Object.getPrototypeOf(ForeignPhone), Phone);
    assert.equal(await loader.requirePromise('views/fields/phone'), Phone);
    const field = new Phone();
    field.useInternational = true;
    assert.equal(field.formatNumber('+14155552671'), '+1 415-555-2671');
    assert.equal(scripts.length, 2);
    assert.deepEqual(warnings, []);
});

test('Opportunity stage and table bridge resolve with their bundled helpers without source requests', async () => {
    const cwd = process.cwd();
    let bundle, init;
    try {
        process.chdir(root);
        ({bundle, init} = bundleOpportunityTable());
    } finally {
        process.chdir(cwd);
    }
    const {context, scripts, warnings} = loaderContext();
    const {loader} = context.Espo;
    class BaseView {
        static extend(properties) {
            class Extended extends this {}
            Object.assign(Extended.prototype, properties);
            return Extended;
        }
        setup() {}
    }
    for (const id of ['controllers/record', 'views/list', 'view', 'helpers/record-icon',
        'crm:views/opportunity/record/list', 'views/fields/link']) {
        loader.define(id, [], () => BaseView);
    }
    loader.define('handlebars', [], () => require('handlebars'));
    context.location.hash = '#Opportunity';
    vm.runInContext(init, context);
    const stagePromise = loader.requirePromise('global:views/opportunity/fields/opportunity-stage');
    const bridgePromise = loader.requirePromise('chatwoot:views/opportunity/table-bridge');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(scripts.length, 1);
    assert.equal(scripts[0].src, 'client/lib/espo-opportunity-table.js?r=test');
    vm.runInContext(bundle, context, {filename: 'espo-opportunity-table.js'});
    scripts[0].events.load();

    const [Stage, Bridge] = await Promise.all([stagePromise, bridgePromise]);
    assert.equal(Object.getPrototypeOf(Bridge), BaseView);
    const stage = new Stage();
    stage.model = {save() {}};
    stage.listenTo = () => {};
    stage.setup();
    assert.equal(stage.model.stageRequirementsInstalled, true);
    assert.equal(typeof (await loader.requirePromise('global:crm-tags')).invalidateTags, 'function');
    assert.equal(scripts.length, 1);
    assert.equal(typeof context.Espo.preCompiledTemplates['global:opportunity/fields/opportunity-stage/list'], 'function');
    assert.deepEqual(warnings, []);
});
