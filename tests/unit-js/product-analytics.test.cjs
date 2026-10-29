const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const file = path.join(__dirname, '../../client/custom/modules/global/src/helpers/product-analytics.js');

function load() {
    const calls = [];
    const posthog = new Proxy({}, {get: (_, method) => (...args) => { calls.push([method, ...args]); }});
    let result;
    vm.runInNewContext(readFileSync(file, 'utf8'), {
        define(name, names, factory) { result = factory(...names.map(() => posthog)); },
    });
    return {ProductAnalytics: result, calls};
}

// Values made inside the vm context have its prototypes; compare them as plain data.
const plain = value => JSON.parse(JSON.stringify(value));

const params = {token: 'phc_test', host: 'https://us.i.posthog.com', distinctId: 'user-5'};
const user = {get: key => ({emailAddress: 'jane@example.com', name: 'Jane'})[key]};

test('URLs leave the browser without query strings or fragments', () => {
    const {ProductAnalytics} = load();
    const event = ProductAnalytics.beforeSend({
        event: '$pageview',
        properties: {
            $current_url: 'https://app.monostax.ai/?sso_email=a%40b.com&sso_auth_token=secret#Contact/view/abc',
            $referrer: 'https://chat.app.monostax.ai/app/accounts/1/crm/Contact?navbar=contact#Contact',
            $pathname: '/',
            crm_action: 'view',
        },
        $set_once: {$initial_current_url: 'https://app.monostax.ai/?sso_auth_token=secret'},
    });

    assert.deepEqual(plain(event.properties), {
        $current_url: 'https://app.monostax.ai/',
        $referrer: 'https://chat.app.monostax.ai/app/accounts/1/crm/Contact',
        $pathname: '/',
        crm_action: 'view',
        app: 'crm',
    });
    assert.equal(event.$set_once.$initial_current_url, 'https://app.monostax.ai/');
    assert.equal(ProductAnalytics.beforeSend(null), null);
});

test('a pageview names the route, never its record IDs or search text', () => {
    const {ProductAnalytics} = load();
    assert.deepEqual(
        plain(ProductAnalytics.pageview({controller: 'Contact', action: 'view', options: {id: 'abc', q: 'Jane'}})),
        {crm_controller: 'Contact', crm_action: 'view'},
    );
    assert.deepEqual(
        plain(ProductAnalytics.pageview({controllerClassName: 'global:controllers/import', action: 'index'})),
        {crm_controller: 'global:controllers/import', crm_action: 'index'},
    );
});

test('start identifies the Chatwoot person and account and records the current and later routes', () => {
    const {ProductAnalytics, calls} = load();
    const listeners = [];
    const router = {getLast: () => ({controller: 'Contact', action: 'list'}), on: (event, fn) => listeners.push([event, fn])};

    ProductAnalytics.reset();
    assert.equal(calls.length, 0, 'reset before start does nothing');

    ProductAnalytics.start(params, user, 7, router);
    listeners[0][1]({controller: 'Account', action: 'view', options: {id: 'xyz'}});

    const [init, ...rest] = calls;
    assert.equal(init[0], 'init');
    assert.equal(init[1], 'phc_test');
    assert.equal(init[2].autocapture, false);
    assert.equal(init[2].disable_session_recording, true);
    assert.equal(init[2].disable_external_dependency_loading, true);
    assert.deepEqual(plain(init[2].property_denylist), ['title']);
    assert.deepEqual(plain(rest), [
        ['identify', 'user-5', {email: 'jane@example.com', name: 'Jane'}],
        ['group', 'account', '7'],
        ['capture', '$pageview', {crm_controller: 'Contact', crm_action: 'list'}],
        ['capture', '$pageview', {crm_controller: 'Account', crm_action: 'view'}],
    ]);
    assert.equal(listeners[0][0], 'routed');

    // A later login reuses the initialized client; a user without a Chatwoot account has no group.
    ProductAnalytics.start({...params, distinctId: 'crm-user-1'}, user, null, {getLast: () => null, on: () => {}});
    assert.equal(calls.filter(call => call[0] === 'init').length, 1);
    assert.deepEqual(plain(calls.at(-1)), ['identify', 'crm-user-1', {email: 'jane@example.com', name: 'Jane'}]);

    ProductAnalytics.reset();
    assert.deepEqual(plain(calls.at(-1)), ['reset']);
});
