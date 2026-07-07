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
 *   mstx('init', ingestUrl [, options])
 *       Must be called first. Captures UTM/click-id attribution from the
 *       landing URL (last-touch, persisted 30 days). Also consumes the
 *       TrackingLink redirect handoff params when present:
 *         mstx_a — anonymousId minted by the short-link redirect; adopted
 *                  when this browser has none yet, so the click event and
 *                  the landing session share one visitor id.
 *         mstx_c — CRM-minted ContactToken (per-recipient links); stored
 *                  as the identity and sent as `contactToken` with every
 *                  event, which also triggers retroactive stitching of the
 *                  visitor's prior anonymous history.
 *         mstx_l — the link slug; captured into attribution like a UTM.
 *       options.decorateWaLinks (default true): WhatsApp click-to-chat
 *       links (wa.me / *.whatsapp.com) are decorated at interaction time —
 *       the visitor's anonymousId is embedded into the pre-filled `text`
 *       param as invisible zero-width characters (server mirror:
 *       ZeroWidthCodec). When the lead sends that message, the CRM's
 *       Chatwoot pipeline extracts the id and joins the WhatsApp
 *       conversation to this browser's attribution history. A
 *       `whatsapp_click` event is tracked on click. Links whose `text`
 *       param is absent are left untouched (an invisible-only message
 *       would look empty). Pass {decorateWaLinks: false} to disable.
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
    // Server-side mirror: TrackingEventPersister::ATTRIBUTION_PARAMS
    // (mstx_l — the short-link slug — is client-side only).
    var ATTRIBUTION_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_id',
        'gclid', 'wbraid', 'gbraid', 'fbclid', 'msclkid', 'ttclid',
        'mstx_l',
    ];

    var state = {
        endpoint: null,
        identity: null, // {email, identitySignature?, identityTimestamp?} | {contactToken}
        waDecoratorInstalled: false,
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

            if (isPlainObject(data) &&
                (typeof data.email === 'string' || typeof data.contactToken === 'string')
            ) {
                state.identity = data;
            }
        } catch (e) {}
    }

    /**
     * TrackingLink redirect handoff (see TrackingLinkRedirector): adopt the
     * redirect-minted anonymousId when this browser has none, and store the
     * per-recipient ContactToken as the identity when present. A stored
     * email identity (explicit identify call) is never overwritten by a
     * token from a possibly-forwarded link.
     */
    function consumeLinkHandoff() {
        var params = queryParams();

        if (typeof params.mstx_a === 'string' && params.mstx_a !== '' && !storageGet(KEY_ANON)) {
            storageSet(KEY_ANON, params.mstx_a.slice(0, 64));
        }

        if (typeof params.mstx_c === 'string' && params.mstx_c !== '' &&
            !(state.identity && state.identity.email)
        ) {
            state.identity = {contactToken: params.mstx_c.slice(0, 512)};

            try {
                storageSet(KEY_IDENTITY, JSON.stringify(state.identity));
            } catch (e) {}
        }
    }

    /*
     * WhatsApp click-to-chat attribution (server mirror: ZeroWidthCodec +
     * TrackingLinkRedirector — keep the wire format in sync). The visitor's
     * anonymousId is encoded as invisible zero-width characters inside the
     * pre-filled message text: U+FEFF delimits the region; one group per
     * ASCII character, separated by U+2060, minimal-length binary with
     * U+200B = 0 and U+200C = 1.
     */
    var ZW_MARKER = '\uFEFF';
    var ZW_ZERO = '\u200B';
    var ZW_ONE = '\u200C';
    var ZW_SEP = '\u2060';

    function encodeInvisible(payload) {
        if (typeof payload !== 'string' || payload === '' || payload.length > 64 ||
            !/^[\x21-\x7E]+$/.test(payload)
        ) {
            return null;
        }

        var groups = [];

        for (var i = 0; i < payload.length; i++) {
            var bits = payload.charCodeAt(i).toString(2);
            var group = '';

            for (var j = 0; j < bits.length; j++) {
                group += bits.charAt(j) === '0' ? ZW_ZERO : ZW_ONE;
            }

            groups.push(group);
        }

        return ZW_MARKER + groups.join(ZW_SEP) + ZW_MARKER;
    }

    /** Inserted before the last character so edge-trimming never eats it. */
    function embedInvisible(text, payload) {
        var encoded = encodeInvisible(payload);

        if (!encoded || typeof text !== 'string' || text.length < 2) {
            return text;
        }

        return text.slice(0, -1) + encoded + text.slice(-1);
    }

    function isWhatsAppHost(host) {
        host = (host || '').toLowerCase();

        return host === 'wa.me' || host === 'www.wa.me' ||
            host === 'whatsapp.com' || host.slice(-13) === '.whatsapp.com';
    }

    function waPhoneFromUrl(url) {
        try {
            var candidate = url.searchParams.get('phone');

            if (!candidate) {
                var segment = url.pathname.replace(/^\/+/, '').split('/')[0];

                if (segment && segment.toLowerCase() !== 'send') {
                    candidate = segment;
                }
            }

            if (!candidate) {
                return null;
            }

            var digits = candidate.replace(/\D+/g, '');

            return digits.length >= 8 && digits.length <= 15 ? digits : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Rewrite a WhatsApp anchor's `text` param with the embedded
     * anonymousId. Idempotent (skips when a marker is already present —
     * ours from an earlier interaction, or the short-link redirector's).
     * Returns the parsed URL when the anchor is a WhatsApp link.
     */
    function decorateWaAnchor(anchor) {
        try {
            var url = new URL(anchor.href, window.location.href);

            if (!isWhatsAppHost(url.hostname)) {
                return null;
            }

            var text = url.searchParams.get('text');

            if (text && text.indexOf(ZW_MARKER) === -1) {
                url.searchParams.set('text', embedInvisible(text, anonymousId()));
                anchor.href = url.toString();
            }

            return url;
        } catch (e) {
            return null;
        }
    }

    function handleWaInteraction(event) {
        try {
            var node = event.target;
            var anchor = null;

            while (node && node.tagName) {
                if (node.tagName === 'A' && node.href) {
                    anchor = node;

                    break;
                }

                node = node.parentNode;
            }

            if (!anchor) {
                return;
            }

            var url = decorateWaAnchor(anchor);

            // Track once per actual activation (mousedown/touchstart only
            // pre-decorate for middle-click / tap navigation).
            if (url && event.type === 'click') {
                var props = {};
                var phone = waPhoneFromUrl(url);

                if (phone) {
                    props.wa_phone = phone;
                }

                api.track('whatsapp_click', props);
            }
        } catch (e) {}
    }

    /**
     * Delegated (capture-phase) so dynamically-added CTAs are covered and
     * the rewrite lands before the browser reads the href for navigation.
     */
    function setupWaDecorator() {
        if (state.waDecoratorInstalled || !window.URL || !document.addEventListener) {
            return;
        }

        state.waDecoratorInstalled = true;

        document.addEventListener('mousedown', handleWaInteraction, true);
        document.addEventListener('touchstart', handleWaInteraction, true);
        document.addEventListener('click', handleWaInteraction, true);
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

    api.init = function (ingestUrl, options) {
        if (typeof ingestUrl !== 'string' || ingestUrl === '') {
            return;
        }

        state.endpoint = ingestUrl;

        loadIdentity();
        captureAttribution();
        consumeLinkHandoff();

        if (!isPlainObject(options) || options.decorateWaLinks !== false) {
            setupWaDecorator();
        }
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
            if (state.identity.contactToken) {
                body.contactToken = state.identity.contactToken;
            }

            if (state.identity.email) {
                body.email = state.identity.email;

                if (state.identity.identitySignature) {
                    body.identitySignature = state.identity.identitySignature;
                }

                if (state.identity.identityTimestamp) {
                    body.identityTimestamp = state.identity.identityTimestamp;
                }
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
