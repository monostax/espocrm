const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const libsConfig = JSON.parse(read('application/Espo/Resources/metadata/app/jsLibs.json'));
const gridstackLib = JSON.parse(read('frontend/libs.json')).find(lib => lib.amdId === 'gridstack');

for (const developerMode of [false, true]) {
    test(`lazy GridStack resolves its AMD export (developer mode: ${developerMode})`, async () => {
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

        const {loader} = context.Espo;
        loader.setIsDeveloperMode(developerMode);
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
