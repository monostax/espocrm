/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Monostax Tracker — browser SDK for the FeatureTrackingEvent public
 * ingest endpoint (kind=Website TrackingSource).
 *
 * Served statically from
 *   {siteUrl}/client/custom/modules/feature-tracking-event/lib/tracker.js
 * and loaded on CUSTOMER sites via the async snippet shown on the
 * TrackingSource detail view (trackerSnippet field). This file is NOT an
 * Espo AMD module and must never be added to scriptList — it is plain,
 * dependency-free, loader-agnostic JavaScript.
 *
 * Snippet contract (Mixpanel/GA command-queue style):
 *
 *   (function(w,d,u,n){w[n]=w[n]||function(){(w[n].q=w[n].q||[]).push(arguments)};
 *   var s=d.createElement("script");s.async=true;s.src=u;d.head.appendChild(s)})
 *   (window,document,"https://crm.example.com/client/custom/modules/feature-tracking-event/lib/tracker.js","mstx");
 *   mstx("init","https://crm.example.com/api/v1/TrackingEvent/receive/{sourceId}");
 *   mstx("page");
 *
 * Commands:
 *   mstx('init', ingestUrl)
 *       Must be called first. Captures UTM/click-id attribution from the
 *       landing URL (last-touch, persisted 30 days).
 *   mstx('page' [, props])
 *       Tracks a `page_view` event (title/path added automatically).
 *   mstx('track', code [, props])
 *       Tracks a custom event. `code` must match [a-z][a-z0-9_]{0,63}
 *       (the server rejects anything else). `props.value` (number) and
 *       `props.currency` (ISO code) are lifted to the canonical top-level
 *       payload fields; everything else travels under `properties`.
 *   mstx('identify', email [, signature [, timestamp]])
 *       Attaches an identity claim to all subsequent events (persisted
 *       across pages). When the TrackingSource has an
 *       identityVerificationSecret, `signature` must be the hex
 *       HMAC-SHA256 of `email` (or of `email:timestamp` when a unix
 *       `timestamp` is given; 24h replay window) computed by YOUR backend
 *       — never ship the secret to the browser.
 *   mstx('reset')
 *       Clears the anonymous id, identity and stored attribution (call on
 *       logout).
 *
 * Transport: POST with Content-Type: text/plain so the request stays a
 * CORS "simple request" — no OPTIONS preflight (which this deployment's
 * edge would swallow) and sendBeacon-compatible. The server derives
 * ip/userAgent itself and strips privileged fields on the public path;
 * the source's allowedOrigins list must include this page's origin.
 *
 * @see custom/Espo/Modules/FeatureTrackingEvent/Services/TrackingEventIngester.php
 */
(function (window, document) {
    'use strict';

    var NAME = 'mstx';

    var existing = window[NAME];

    // Double-load guard (two snippets on the same page).
    if (existing && existing.loaded) {
        return;
    }

    var KEY_ANON = 'mstx_anon';
    var KEY_IDENTITY = 'mstx_identity';
    var KEY_ATTRIBUTION = 'mstx_attr';

    var ATTRIBUTION_MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000; // 30 days
    var ATTRIBUTION_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'msclkid', 'ttclid',
    ];

    var state = {
        endpoint: null,
        identity: null, // {email, identitySignature?, identityTimestamp?}
    };

    // In-memory fallbacks for storage-less contexts (some private modes).
    var memory = {};

    function storageGet(key) {
        try {
            var value = window.localStorage.getItem(key);

            return value !== null ? value : (memory[key] || null);
        } catch (e) {
            return memory[key] || null;
        }
    }

    function storageSet(key, value) {
        memory[key] = value;

        try {
            window.localStorage.setItem(key, value);
        } catch (e) {}
    }

    function storageRemove(key) {
        delete memory[key];

        try {
            window.localStorage.removeItem(key);
        } catch (e) {}
    }

    function generateId() {
        try {
            if (window.crypto && window.crypto.randomUUID) {
                return window.crypto.randomUUID();
            }
        } catch (e) {}

        var hex = '';

        for (var i = 0; i < 32; i++) {
            hex += Math.floor(Math.random() * 16).toString(16);
        }

        return hex;
    }

    /** Sticky per-browser anonymous id (server caps at 64 chars). */
    function anonymousId() {
        var id = storageGet(KEY_ANON);

        if (!id) {
            id = generateId();
            storageSet(KEY_ANON, id);
        }

        return id.slice(0, 64);
    }

    function truncate(value, max) {
        if (typeof value !== 'string' || value === '') {
            return null;
        }

        return value.slice(0, max);
    }

    function isPlainObject(value) {
        return !!value && typeof value === 'object' && !(value instanceof Array);
    }

    function queryParams() {
        var out = {};
        var search = window.location.search || '';

        if (search.charAt(0) === '?') {
            search = search.slice(1);
        }

        if (search === '') {
            return out;
        }

        var pairs = search.split('&');

        for (var i = 0; i < pairs.length; i++) {
            var eq = pairs[i].indexOf('=');

            if (eq <= 0) {
                continue;
            }

            try {
                var key = decodeURIComponent(pairs[i].slice(0, eq));
                var value = decodeURIComponent(pairs[i].slice(eq + 1).replace(/\+/g, ' '));

                out[key] = value;
            } catch (e) {}
        }

        return out;
    }

    /**
     * Last-touch attribution: whenever the current URL carries UTM/click-id
     * params, overwrite the stored snapshot. Consumed on every event.
     */
    function captureAttribution() {
        var params = queryParams();
        var found = {};
        var any = false;

        for (var i = 0; i < ATTRIBUTION_PARAMS.length; i++) {
            var name = ATTRIBUTION_PARAMS[i];

            if (typeof params[name] === 'string' && params[name] !== '') {
                found[name] = params[name].slice(0, 512);
                any = true;
            }
        }

        if (!any) {
            return;
        }

        found.landing_page = truncate(window.location.href, 1024);

        if (document.referrer) {
            found.referrer = truncate(document.referrer, 1024);
        }

        found.captured_at = Date.now();

        try {
            storageSet(KEY_ATTRIBUTION, JSON.stringify(found));
        } catch (e) {}
    }

    function attribution() {
        var raw = storageGet(KEY_ATTRIBUTION);

        if (!raw) {
            return null;
        }

        try {
            var data = JSON.parse(raw);

            if (!isPlainObject(data)) {
                return null;
            }

            if (typeof data.captured_at === 'number' &&
                Date.now() - data.captured_at > ATTRIBUTION_MAX_AGE_MS
            ) {
                storageRemove(KEY_ATTRIBUTION);

                return null;
            }

            return data;
        } catch (e) {
            return null;
        }
    }

    function loadIdentity() {
        var raw = storageGet(KEY_IDENTITY);

        if (!raw) {
            return;
        }

        try {
            var data = JSON.parse(raw);

            if (isPlainObject(data) && typeof data.email === 'string') {
                state.identity = data;
            }
        } catch (e) {}
    }

    function sendBeacon(json) {
        if (!state.endpoint || !window.navigator || !window.navigator.sendBeacon) {
            return;
        }

        try {
            // text/plain Blob keeps sendBeacon CORS-safelisted.
            window.navigator.sendBeacon(state.endpoint, new Blob([json], {type: 'text/plain'}));
        } catch (e) {}
    }

    function send(body) {
        var json;

        try {
            json = JSON.stringify(body);
        } catch (e) {
            return;
        }

        if (window.fetch) {
            try {
                window.fetch(state.endpoint, {
                    method: 'POST',
                    // text/plain => CORS simple request, no preflight.
                    headers: {'Content-Type': 'text/plain'},
                    body: json,
                    keepalive: true,
                    credentials: 'omit',
                }).catch(function () {
                    sendBeacon(json);
                });

                return;
            } catch (e) {}
        }

        sendBeacon(json);
    }

    var api = {};

    api.init = function (ingestUrl) {
        if (typeof ingestUrl !== 'string' || ingestUrl === '') {
            return;
        }

        state.endpoint = ingestUrl;

        loadIdentity();
        captureAttribution();
    };

    api.track = function (code, props) {
        if (!state.endpoint || typeof code !== 'string' || code === '') {
            return;
        }

        var properties = {};

        if (isPlainObject(props)) {
            for (var key in props) {
                if (Object.prototype.hasOwnProperty.call(props, key)) {
                    properties[key] = props[key];
                }
            }
        }

        var body = {
            code: code,
            occurredAt: new Date().toISOString(),
            anonymousId: anonymousId(),
            url: truncate(window.location.href, 1024),
            referrer: truncate(document.referrer, 1024),
        };

        // Canonical top-level payload fields.
        if (typeof properties.value === 'number' && isFinite(properties.value)) {
            body.value = properties.value;
            delete properties.value;
        }

        if (typeof properties.currency === 'string' && properties.currency !== '') {
            body.currency = properties.currency;
            delete properties.currency;
        }

        var attr = attribution();

        if (attr) {
            body.attribution = attr;
        }

        if (state.identity) {
            body.email = state.identity.email;

            if (state.identity.identitySignature) {
                body.identitySignature = state.identity.identitySignature;
            }

            if (state.identity.identityTimestamp) {
                body.identityTimestamp = state.identity.identityTimestamp;
            }
        }

        for (var any in properties) {
            if (Object.prototype.hasOwnProperty.call(properties, any)) {
                body.properties = properties;

                break;
            }
        }

        send(body);
    };

    api.page = function (props) {
        var merged = {
            title: (document.title || '').slice(0, 255),
            path: truncate(window.location.pathname, 1024),
        };

        if (isPlainObject(props)) {
            for (var key in props) {
                if (Object.prototype.hasOwnProperty.call(props, key)) {
                    merged[key] = props[key];
                }
            }
        }

        api.track('page_view', merged);
    };

    api.identify = function (email, signature, timestamp) {
        if (typeof email !== 'string' || email === '') {
            return;
        }

        var identity = {email: email.slice(0, 255)};

        if (typeof signature === 'string' && signature !== '') {
            identity.identitySignature = signature;
        }

        if (typeof timestamp === 'number' && isFinite(timestamp)) {
            identity.identityTimestamp = Math.floor(timestamp);
        }

        state.identity = identity;

        try {
            storageSet(KEY_IDENTITY, JSON.stringify(identity));
        } catch (e) {}
    };

    api.reset = function () {
        state.identity = null;

        storageRemove(KEY_ANON);
        storageRemove(KEY_IDENTITY);
        storageRemove(KEY_ATTRIBUTION);
    };

    function dispatch(args) {
        var command = args[0];

        if (typeof command === 'string' && Object.prototype.hasOwnProperty.call(api, command)) {
            try {
                api[command].apply(null, Array.prototype.slice.call(args, 1));
            } catch (e) {}
        }
    }

    var queued = (existing && existing.q) || [];

    window[NAME] = function () {
        dispatch(arguments);
    };

    window[NAME].loaded = true;

    // Drain calls queued by the snippet stub (init is always first).
    for (var i = 0; i < queued.length; i++) {
        dispatch(queued[i]);
    }
})(window, document);
