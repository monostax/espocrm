/**
 * Product analytics in PostHog: identity, the Chatwoot account and pageviews by route, never
 * screen content. The `posthog-js` lib is the slim no-external build, so there is no autocapture,
 * replay or surveys and no remote script is loaded. Chatwoot's AnalyticsHelper sends the same
 * person (`user-<Chatwoot user ID>`) and account to the same project.
 */
// The loader resolves an AMD lib to its default export: the PostHog instance.
define('global:helpers/product-analytics', ['posthog-js'], function (posthog) {
    let initialized = false;

    // Query strings and fragments carry SSO tokens (`sso_auth_token`), record IDs and search
    // text, so URLs leave the browser as origin and path only.
    const stripUrl = value => typeof value === 'string' && /^https?:\/\//i.test(value) ?
        value.replace(/[?#][\s\S]*$/, '') : value;

    const stripUrls = properties => {
        if (!properties) return;
        for (const key of Object.keys(properties)) properties[key] = stripUrl(properties[key]);
    };

    /** Runs on every event PostHog sends, including person properties. */
    const beforeSend = event => {
        if (!event) return event;
        stripUrls(event.properties);
        stripUrls(event.$set);
        stripUrls(event.$set_once);
        event.properties.app = 'crm';
        return event;
    };

    /** A route as its controller and action; its options hold record IDs and search text. */
    const pageview = route => ({
        crm_controller: route.controller || route.controllerClassName || null,
        crm_action: route.action || null,
    });

    return {
        beforeSend,
        pageview,

        /**
         * @param {{token: string, host: string, distinctId: string}} params The `productAnalytics` app param.
         * @param {import('models/user').default} user
         * @param {?number} accountId The `chatwootAccountId` app param.
         * @param {import('router').default} router
         */
        start(params, user, accountId, router) {
            if (!initialized) {
                posthog.init(params.token, {
                    api_host: params.host,
                    person_profiles: 'identified_only',
                    autocapture: false,
                    capture_pageview: false,
                    capture_pageleave: false,
                    disable_session_recording: true,
                    disable_surveys: true,
                    advanced_disable_flags: true,
                    disable_external_dependency_loading: true,
                    respect_dnt: true,
                    // Record pages are titled with the record's name.
                    property_denylist: ['title'],
                    before_send: beforeSend,
                });
                initialized = true;
            }

            posthog.identify(params.distinctId, {email: user.get('emailAddress'), name: user.get('name')});
            if (accountId) posthog.group('account', String(accountId));

            // The router dispatched the first route before this module loaded.
            const capture = route => { if (route) posthog.capture('$pageview', pageview(route)); };
            capture(router.getLast());
            router.on('routed', capture);
        },

        /** Forget the person on this browser at logout, as Chatwoot does. */
        reset() {
            if (initialized) posthog.reset();
        },
    };
});
