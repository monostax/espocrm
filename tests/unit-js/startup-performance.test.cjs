const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.join(__dirname, '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');

function loaderContext() {
    const context = vm.createContext({
        Espo: {}, URL, console,
        location: {origin: 'https://crm.example.test', pathname: '/'},
        document: {
            querySelector: () => null,
            createElement() { assert.fail('A lazy customization must not download a module.'); },
        },
    });
    context.window = context;
    vm.runInContext(read('client/src/loader.js'), context);
    return context;
}

test('lazy module customizations run before consumers, normalize IDs, and run once', () => {
    const context = loaderContext();
    const {loader} = context.Espo;
    let initialized = 0;
    loader.onDefine('advanced:example', Class => {
        Class.prototype.customized = true;
        initialized++;
    });
    loader.define('modules/advanced/example', [], () => class {});
    loader.define('consumer', ['advanced:example'], Base => {
        assert.equal(Base.prototype.customized, true);
        return class extends Base {};
    });
    loader.define('advanced:example', [], () => assert.fail('Already defined'));
    loader.onDefine('modules/advanced/example', Class => {
        assert.equal(Class.prototype.customized, true);
    });
    assert.equal(initialized, 1);
});

test('report customizations stay lazy and preserve the editor and result formatting', () => {
    const context = loaderContext();
    vm.runInContext(read('client/custom/modules/global/lib/report-column-field-type-patch.js'), context);
    const {loader} = context.Espo;
    class Model {
        set(attributes) { this.attributes = attributes; }
    }
    loader.define('model', [], () => Model);
    class ColumnItem {}
    loader.define('advanced:views/report/fields/columns/item', ['model'], () => ColumnItem);
    const item = new ColumnItem();
    item.options = {fieldType: 'duration', onChange() {}};
    item.listenTo = () => {};
    item.translate = x => x;
    const fields = {};
    item.createView = (name, _view, options) => { fields[name] = options; };
    item.setup();
    assert.equal(fields.fieldType.model.attributes.fieldType, 'duration');
    assert.ok(fields.fieldType.params.options.includes('currencyConverted'));

    class Columns {}
    loader.define('advanced:views/report/fields/columns', [], () => Columns);
    assert.equal(typeof Columns.prototype.actionEditColumns, 'function');
    class EditColumns { setup() {} }
    loader.define('advanced:views/report/modals/edit-columns', [], () => EditColumns);
    const modal = new EditColumns();
    modal.options = {fieldTypes: ['duration']};
    context.Espo.Utils = {clone: value => [...value]};
    modal.setup();
    assert.deepEqual(modal.fieldTypes, ['duration']);

    class ReportHelper {
        isColumnNumeric() { return true; }
        getGroupFieldData() { return {}; }
        formatNumber(value, currency) { return currency ? `R$ ${value}` : String(value); }
    }
    loader.define('advanced:report-helper', [], () => ReportHelper);
    const helper = new ReportHelper();
    assert.equal(helper.formatCellValue(73200, 'SUM:duration', {
        columnTypeMap: {'SUM:duration': 'duration'},
    }), '73.2 s');
    assert.equal(helper.formatCellValue(440, 'SUM:IF:(...)', {
        columnTypeMap: {'SUM:IF:(...)': 'currencyConverted'},
    }), 'R$ 440');

    class Grid {}
    loader.define('advanced:views/report/reports/tables/grid2', [], () => Grid);
    const grid = new Grid();
    grid.options = {reportHelper: helper};
    grid.reportHelper = helper;
    grid.result = {columnTypeMap: {duration: 'duration'}};
    assert.equal(grid.formatCellValue(1234, 'duration', true), '1.234 s');
});

test('initial startup happens at DOM readiness, while pageshow only resumes bfcache', () => {
    const html = read('html/main.html');
    const script = html.match(/<script nonce="\{\{nonce\}\}">([\s\S]*?)<\/script>/)[1];
    const values = {
        runScript: 'app.start();', appClientClassName: 'app', applicationId: 'espocrm',
        useCache: 'true', cacheTimestamp: '1', appTimestamp: '1', assetVersionJson: '"version"',
        basePath: '', apiUrl: 'api/v1', ajaxTimeout: '1000', internalModuleList: '[]',
        bundledModuleList: '[]', theme: 'null', embeddedTable: 'false',
    };
    const events = {};
    let created = 0;
    let started = 0;
    const context = {
        document: {readyState: 'loading', addEventListener(name, callback) { events[name] = callback; }},
        window: {addEventListener(name, callback) { events[name] = callback; }},
        require(name, callback) {
            assert.equal(name, 'app');
            callback(class {
                constructor(_options, done) { created++; done(this); }
                start() { started++; }
            });
        },
    };
    vm.runInNewContext(script.replace(/\{\{(\w+)\}\}/g, (_match, key) => {
        assert.ok(key in values, key);
        return values[key];
    }), context);
    assert.equal(created, 0);
    events.DOMContentLoaded();
    assert.equal(created, 1);
    assert.equal(started, 1);
    events.pageshow({persisted: false});
    assert.equal(started, 1);
    events.pageshow({persisted: true});
    assert.equal(created, 1);
    assert.equal(started, 2);
});

function loadClass(file, dependencies = {}, globals = {}) {
    const exports = {};
    const {outputText} = ts.transpileModule(read(file), {
        compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS},
    });
    vm.runInNewContext(outputText, {
        exports, ...globals,
        require(name) {
            const dependency = dependencies[name] || {};
            return {__esModule: true, default: dependency, ...dependency};
        },
    }, {filename: file});
    return exports.default;
}

const appWindow = {};
const apiCalls = [];
const App = loadClass('client/src/app.js', {
    bullbone: {Events: {}, View: class {}},
    'js-base64': {decode: value => Buffer.from(value, 'base64').toString()},
    ajax: {getRequest(...args) { apiCalls.push(args); return Promise.resolve({}); }},
}, {window: appWindow});

function startupApp(auth = 'saved-token') {
    const app = Object.create(App.prototype);
    app.auth = null;
    app.anotherUser = null;
    appWindow.espoEarlyBootstrap = null;
    const stored = new Map([['auth', auth], ['anotherUser', 'other-user']]);
    const calls = [];
    app.storage = {
        get: (_namespace, key) => stored.get(key),
        clear: (_namespace, key) => stored.delete(key),
    };
    app.settings = {
        setMultiple(data) { calls.push(['settings', data]); },
        async load() { calls.push(['public-settings']); },
        clear() {},
    };
    app.unsetCookieAuth = () => calls.push(['clear-cookie']);
    return {app, calls, stored};
}

test('authenticated startup reuses App/user settings and user data, with no extra auth request', async () => {
    const {app, calls} = startupApp(Buffer.from('user:token').toString('base64'));
    const data = {user: {id: 'user'}, preferences: {}, settings: {language: 'pt_BR'}, acl: {}, appParams: {}, language: 'pt_BR'};
    let requests = 0;
    app.requestUserData = async options => {
        requests++;
        if (options) {
            assert.equal(options.headers['X-Another-User'], 'other-user');
            assert.equal(options.headers['Espo-Authorization-By-Token'], 'true');
            assert.equal(app.auth, null, 'Public initialization must not inherit stored credentials');
        }
        return data;
    };
    await app.loadInitialSettings();
    assert.deepEqual(calls, [['settings', data.settings]]);
    app.baseController = {on() {}};
    app.initAuth();
    app.language = {async load() {}};
    app.dateTime = {setLanguage() {}};
    app.user = {setMultiple(value) { assert.equal(value, data.user); }};
    app.preferences = {setMultiple() {}};
    app.acl = {set() {}};
    app.appParams = {setAll() {}};
    app.setCookieAuth = (user, token) => calls.push(['cookie', user, token]);
    app.redirectLegacyCrmRoute = () => false;
    let ready = 0;
    await app.initUserData(null, () => { ready++; });
    assert.equal(requests, 1);
    assert.equal(ready, 1);
    assert.equal(app.initialUserData, null);
    assert.deepEqual(calls.at(-1), ['cookie', 'user', 'token']);
    // A later start (e.g. bfcache restore) must revalidate the session.
    await app.initUserData(null, () => { ready++; });
    assert.equal(requests, 2);
});

test('expired startup sessions clear auth and load login settings before the router exists', async () => {
    const {app, calls, stored} = startupApp();
    const unauthorized = {status: 401};
    app.requestUserData = async () => { throw unauthorized; };
    await app.loadInitialSettings();
    assert.equal(unauthorized.errorIsHandled, true);
    assert.equal(app.auth, null);
    assert.equal(app.anotherUser, null);
    assert.equal(stored.has('auth'), false);
    assert.equal(stored.has('anotherUser'), false);
    assert.deepEqual(calls, [['clear-cookie'], ['public-settings']]);
});

test('logged-out startup uses public settings and server failures do not clear credentials', async () => {
    const anonymous = startupApp(null);
    anonymous.app.requestUserData = () => assert.fail('No stored session');
    await anonymous.app.loadInitialSettings();
    assert.deepEqual(anonymous.calls, [['public-settings']]);
    const failed = startupApp();
    const serverError = {status: 503};
    failed.app.requestUserData = async () => { throw serverError; };
    await assert.rejects(failed.app.loadInitialSettings(), error => error === serverError);
    assert.equal(failed.stored.get('auth'), 'saved-token');
    assert.deepEqual(failed.calls, []);
});

test('the embedded opportunity table skips navbar construction; normal CRM pages retain it', () => {
    const window = {location: {search: '?embed=chatwoot-opportunity&navbar=none', hash: '#OpportunityTableBridge'}};
    window.self = window;
    window.top = {};
    const Header = loadClass('client/src/views/site/header.js', {view: class {}}, {window, URLSearchParams});
    const header = new Header();
    header.getMetadata = () => ({get: () => 'global:views/site/navbar'});
    let navbars = 0;
    header.createView = () => { navbars++; };
    header.setup();
    assert.equal(navbars, 0);
    window.top = window;
    header.setup();
    assert.equal(navbars, 1);
    window.top = {};
    window.location.hash = '#Opportunity';
    header.setup();
    assert.equal(navbars, 2);
});

function startEarly({auth = 'saved-token', anotherUser = 'other-user', topLevel = false, hash = '#OpportunityTableBridge'} = {}) {
    const requests = [];
    const window = {location: {hash}};
    window.self = window;
    window.top = topLevel ? window : {};
    const context = {
        window,
        localStorage: {getItem(key) { return key.endsWith('-auth') ? auth : anotherUser; }},
        document: {querySelector() { return {textContent: JSON.stringify({apiUrl: 'api/v1', ajaxTimeout: 60000})}; }},
        XMLHttpRequest: class {
            headers = {};
            open(method, url) { this.method = method; this.url = url; }
            setRequestHeader(key, value) { this.headers[key] = value; }
            send() { requests.push(this); }
        },
    };
    vm.runInNewContext(read('html/embedded-bootstrap.js'), context);
    return {window, requests};
}

test('early bootstrap starts without the core runtime and transfers one authenticated response to App', async () => {
    const {app, calls} = startupApp();
    app.embeddedTable = true;
    const early = startEarly();
    assert.equal(early.requests.length, 1);
    const xhr = early.requests[0];
    assert.equal(xhr.url, 'api/v1/App/user?bootstrap=opportunity-table');
    assert.equal(xhr.headers.Authorization, 'Basic saved-token');
    assert.equal(xhr.headers['X-Another-User'], 'other-user');
    const data = {user: {id: 'user'}, settings: {language: 'pt_BR'}};
    xhr.status = 200;
    xhr.responseText = JSON.stringify(data);
    xhr.onload();
    appWindow.espoEarlyBootstrap = early.window.espoEarlyBootstrap;
    app.requestUserData = () => assert.fail('Bootstrap must not be requested twice');
    await app.loadInitialSettings();
    assert.deepEqual(JSON.parse(JSON.stringify(calls)), [['settings', data.settings]]);
    assert.equal(appWindow.espoEarlyBootstrap, null);
});

test('early bootstrap expiration falls back to login without a second authentication request', async () => {
    const {app, calls} = startupApp();
    app.embeddedTable = true;
    const early = startEarly();
    const xhr = early.requests[0];
    xhr.status = 401;
    xhr.onload();
    appWindow.espoEarlyBootstrap = early.window.espoEarlyBootstrap;
    app.requestUserData = () => assert.fail('Expired bootstrap should go straight to login');
    await app.loadInitialSettings();
    assert.equal(xhr.errorIsHandled, true);
    assert.deepEqual(calls, [['clear-cookie'], ['public-settings']]);
});

test('bootstrap timeout and malformed responses resolve for the later error handler', async () => {
    for (const failure of ['ontimeout', 'onerror', 'onload']) {
        const early = startEarly();
        const xhr = early.requests[0];
        xhr.status = failure === 'onload' ? 200 : 0;
        xhr.responseText = '<html>Bad response</html>';
        xhr[failure]();
        assert.equal((await early.window.espoEarlyBootstrap.promise).error, xhr);
    }
});

test('early bootstrap is scoped to the authenticated table iframe', () => {
    for (const options of [{auth: null}, {topLevel: true}, {hash: '#OpportunityBridge'}]) {
        const early = startEarly(options);
        assert.equal(early.requests.length, 0);
        assert.equal(early.window.espoEarlyBootstrap, undefined);
    }
});

test('a different user or impersonation cannot consume a previous early response', async () => {
    for (const changed of [{auth: 'other-token'}, {anotherUser: 'different-user'}]) {
        const {app} = startupApp();
        app.embeddedTable = true;
        appWindow.espoEarlyBootstrap = {
            auth: 'saved-token', anotherUser: 'other-user', ...changed,
            promise: Promise.resolve({data: {user: {id: 'wrong-user'}}}),
        };
        let fresh = 0;
        app.requestUserData = async () => { fresh++; return {settings: {}}; };
        await app.loadInitialSettings();
        assert.equal(fresh, 1);
    }
});

test('later table refreshes retain the lightweight profile and skip global template warming', async () => {
    const {app} = startupApp();
    app.embeddedTable = true;
    await app.requestUserData();
    assert.equal(apiCalls.at(-1)[1].bootstrap, 'opportunity-table');
    app.responseCache = {};
    app.cache = {get() { assert.fail('The table must not warm every CRM template'); }};
    await app.initTemplateBundles();
    app.embeddedTable = false;
    await app.requestUserData();
    assert.equal(Object.keys(apiCalls.at(-1)[1]).length, 0);
});
