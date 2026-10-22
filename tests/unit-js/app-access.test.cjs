const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');

function loadClass(file, dependencies, globals = {}) {
    const source = readFileSync(path.join(__dirname, '../../client/src', file), 'utf8');
    const {outputText} = ts.transpileModule(source, {
        compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS},
    });
    const exports = {};
    vm.runInNewContext(outputText, {
        exports, ...globals,
        require(name) {
            const dependency = dependencies[name] || {};
            return {__esModule: true, default: dependency, ...dependency};
        },
    }, {filename: file});
    return exports.default;
}

class Model {
    attributes = {};
    get(key) { return this.attributes[key]; }
    setMultiple(data) { Object.assign(this.attributes, data); }
}

const User = loadClass('models/user.js', {model: Model});
const settle = () => new Promise(resolve => setImmediate(resolve));
const chatUrl = 'https://chat.app.monostax.ai';

function fixture({
    type = 'regular', hash = '', search = '', embedded = false, authenticated = true,
    appParams = {chatwootAccountId: '42', chatwootFrontendUrl: chatUrl},
    sso = {ssoUrl: `${chatUrl}/app/login?token=fresh`}, chatRedirect = null, expired = false,
    cookieAuth = false, protocol = 'https:',
} = {}) {
    const redirects = [];
    const requests = [];
    const cookies = [];
    const handlers = {};
    const stored = new Map(authenticated ? [['auth', Buffer.from('user:token').toString('base64')]] : []);
    const local = new Map(chatRedirect ? [['chat_redirect', chatRedirect]] : []);
    const window = {
        location: {
            hostname: 'app.monostax.ai', protocol, hash, search,
            replace(url) { redirects.push({url, replace: true}); },
            set href(url) { redirects.push({url, replace: false}); },
        },
    };
    window.self = window;
    window.top = embedded ? {} : window;
    const data = {
        user: {id: 'user', type}, preferences: {}, settings: {}, acl: {}, appParams,
        language: 'en_US', auth: {userName: 'user', token: 'token'},
    };
    const App = loadClass('app.js', {
        bullbone: {Events: {}, View: class {}},
        'js-base64': {
            encode: value => Buffer.from(value).toString('base64'),
            decode: value => Buffer.from(value, 'base64').toString(),
        },
        ajax: {
            async getRequest(url) {
                requests.push(url);
                if (url === 'App/user') {
                    if (expired) throw {status: 401};
                    return data;
                }
                assert.equal(url, 'ChatwootSso/freshUrl');
                if (sso instanceof Error) throw sso;
                return sso;
            },
        },
    }, {
        window, URL,
        document: {set cookie(value) { cookies.push(value); }},
        localStorage: {getItem: key => local.get(key), removeItem: key => local.delete(key)},
    });
    const app = Object.create(App.prototype);
    const state = {login: 0, ready: 0, publicSettings: 0};
    app.auth = null;
    app.storage = {
        get: (_namespace, key) => stored.get(key),
        set: (_namespace, key, value) => stored.set(key, value),
        clear: (_namespace, key) => stored.delete(key),
    };
    app.baseController = {
        on(name, callback) { handlers[name] = callback; },
        login() { state.login++; },
    };
    app.user = new User();
    app.language = {async load() {}, clearCache() {}};
    app.metadata = {clearCache() {}};
    app.dateTime = {setLanguage() {}};
    app.preferences = {setMultiple() {}};
    app.settings = {setMultiple() {}, clear() {}, async load() { state.publicSettings++; }};
    app.acl = {set() {}};
    let params = {};
    app.appParams = {setAll(value) { params = value; }, get: key => params[key]};
    if (!cookieAuth) {
        app.setCookieAuth = (name, token) => cookies.push([name, token]);
        app.unsetCookieAuth = () => cookies.push(['cleared']);
    }
    app.onAuth = () => { state.ready++; };
    return {app, state, data, handlers, requests, redirects, cookies, local, stored};
}

async function restore(context) {
    await context.app.loadInitialSettings();
    context.app.start();
    await settle();
}

test('anonymous visitors can reach login and recovery without a chat redirect', async () => {
    for (const hash of ['', '#Login', '#PasswordRecovery']) {
        const context = fixture({authenticated: false, hash, chatRedirect: '/app'});
        await restore(context);
        assert.equal(context.state.login, 1);
        assert.equal(context.state.publicSettings, 1);
        assert.deepEqual(context.requests, []);
        assert.deepEqual(context.redirects, []);
    }
});

test('expired credentials return to login instead of being treated as an authenticated user', async () => {
    const context = fixture({expired: true});
    await restore(context);
    assert.equal(context.state.login, 1);
    assert.equal(context.stored.has('auth'), false);
    assert.deepEqual(context.cookies, [['cleared']]);
    assert.deepEqual(context.redirects, []);
});

test('a restored regular session redirects from the homepage before starting the CRM', async () => {
    const context = fixture();
    await restore(context);
    assert.equal(context.state.ready, 0);
    assert.deepEqual(context.requests, ['App/user', 'ChatwootSso/freshUrl']);
    assert.deepEqual(context.cookies, [['user', 'token']]);
    assert.equal(context.redirects.length, 1);
    assert.equal(context.redirects[0].replace, true);
    const url = new URL(context.redirects[0].url);
    assert.equal(url.origin, chatUrl);
    assert.equal(url.searchParams.get('redirect_to'), '/app');
});

test('a fresh regular login redirects and preserves the session for embedded CRM access', async () => {
    const context = fixture({authenticated: false});
    context.app.start();
    context.handlers.login(context.data);
    await settle();
    assert.equal(context.state.ready, 0);
    assert.ok(context.stored.get('auth'));
    assert.equal(context.redirects.length, 1);
    assert.equal(new URL(context.redirects[0].url).origin, chatUrl);
});

test('regular-user deep links are carried into the account-scoped chat shell', async () => {
    const context = fixture({hash: '#Contact/view/contact-123'});
    await restore(context);
    assert.equal(context.state.ready, 0);
    assert.equal(new URL(context.redirects[0].url).searchParams.get('redirect_to'),
        '/app/accounts/42/crm/Contact?navbar=contact#Contact/view/contact-123');
});

test('EspoCRM admins and super-admins retain direct homepage and deep-link access', async () => {
    for (const type of ['admin', 'super-admin']) {
        for (const hash of ['', '#Admin', '#Contact/view/contact-123']) {
            const context = fixture({type, hash});
            await restore(context);
            assert.equal(context.state.ready, 1);
            assert.deepEqual(context.redirects, []);
            assert.deepEqual(context.requests, ['App/user']);
        }
    }
});

test('regular users can restore sessions and sign in inside actual iframes, even with a pending chat redirect', async () => {
    for (const authenticated of [true, false]) {
        const context = fixture({embedded: true, authenticated, hash: '#Contact', chatRedirect: '/app'});
        await restore(context);
        if (!authenticated) {
            context.handlers.login(context.data);
            await settle();
        }
        assert.equal(context.state.ready, 1);
        assert.deepEqual(context.redirects, []);
        assert.equal(context.requests.includes('ChatwootSso/freshUrl'), false);
        assert.equal(context.local.get('chat_redirect'), '/app');
    }
});

test('an embed query parameter alone does not allow standalone access', async () => {
    const context = fixture({search: '?embed=chatwoot-opportunity&navbar=none', hash: '#OpportunityTableBridge'});
    await restore(context);
    assert.equal(context.state.ready, 0);
    assert.equal(context.redirects.length, 1);
});

test('embedded login and restored startup use the server cookie policy for avatar authentication', async () => {
    for (const authenticated of [true, false]) {
        const context = fixture({embedded: true, authenticated, cookieAuth: true, appParams: {authCookiePartitioned: true}});
        await restore(context);
        if (!authenticated) {
            context.handlers.login(context.data);
            await settle();
        }
        assert.equal(context.state.ready, 1);
        const cookies = context.cookies.filter(cookie => cookie.startsWith('auth-token=token;'));
        assert.equal(cookies.length, authenticated ? 1 : 2);
        for (const cookie of cookies) {
            assert.match(cookie, /SameSite=None; Secure; Partitioned/);
            assert.match(cookie, /path=\/; domain=\.monostax\.ai$/);
        }
    }
});

test('default and insecure deployments keep the first-party avatar cookie policy', async () => {
    for (const [partitioned, protocol] of [[false, 'https:'], [true, 'http:']]) {
        const context = fixture({embedded: true, cookieAuth: true, protocol, appParams: {authCookiePartitioned: partitioned}});
        await restore(context);
        assert.equal(context.cookies.length, 1);
        assert.match(context.cookies[0], /auth-token=token; SameSite=Lax;/);
        assert.doesNotMatch(context.cookies[0], /Partitioned/);
    }
});

test('expired bootstrap clears both avatar cookie variants before app parameters are available', async () => {
    const context = fixture({expired: true, cookieAuth: true});
    await restore(context);
    assert.equal(context.state.login, 1);
    assert.equal(context.cookies.length, 2);
    for (const cookie of context.cookies) {
        assert.match(cookie, /^auth-token=;/);
        assert.ok(Date.parse(cookie.match(/expires=([^;]+)/)[1]) < Date.now());
        assert.match(cookie, /path=\/; domain=\.monostax\.ai$/);
    }
    assert.match(context.cookies[0], /SameSite=Lax/);
    assert.match(context.cookies[1], /SameSite=None; Secure; Partitioned/);
});

test('missing account mappings, unavailable SSO, and invalid SSO URLs still redirect to chat', async () => {
    for (const sso of [{}, {ssoUrl: null}, {ssoUrl: 'invalid'}, new Error('SSO unavailable')]) {
        const context = fixture({appParams: {}, sso, hash: '#Contact'});
        await restore(context);
        assert.equal(context.state.ready, 0);
        assert.deepEqual(context.redirects, [{url: chatUrl, replace: true}]);
    }
});

test('SSO failure respects the configured chat frontend for other deployments', async () => {
    const frontendUrl = 'https://chat.dev.example.test';
    const context = fixture({appParams: {chatwootFrontendUrl: frontendUrl}, sso: {}});
    await restore(context);
    assert.equal(context.state.ready, 0);
    assert.deepEqual(context.redirects, [{url: frontendUrl, replace: true}]);
});

test('failed explicit chat login redirects cannot fall through to standalone CRM access', async () => {
    for (const authenticated of [true, false]) {
        const context = fixture({authenticated, chatRedirect: '/app', sso: {}});
        await restore(context);
        if (!authenticated) {
            context.handlers.login(context.data);
            await settle();
        }
        assert.equal(context.state.ready, 0);
        assert.deepEqual(context.redirects, [{url: chatUrl, replace: true}]);
    }
});
