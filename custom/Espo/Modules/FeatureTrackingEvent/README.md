# FeatureTrackingEvent

Per-tenant, first-party marketing/behavior event tracking for EspoCRM —
a self-hosted, Mixpanel-style event ledger. Receives events from websites
(browser SDK), chat, and server-side backends; stitches anonymous browsing
history to identified Contacts; caches multi-touch attribution for
downstream use (Meta CAPI / Google Offline Conversions dispatch).

- [Concepts](#concepts)
- [Quick start: track your website](#quick-start-track-your-website)
- [Installing via Google Tag Manager](#installing-via-google-tag-manager)
- [Browser SDK reference](#browser-sdk-reference)
- [Server-side (trusted) tracking](#server-side-trusted-tracking)
- [Verified identity (`identify`)](#verified-identity-identify)
- [Payload reference](#payload-reference)
- [Response codes](#response-codes)
- [Event types and auto-creation](#event-types-and-auto-creation)
- [Anonymous-to-Contact stitching](#anonymous-to-contact-stitching)
- [Tenancy, teams and permissions](#tenancy-teams-and-permissions)
- [Rate limiting](#rate-limiting)
- [Deployment notes (CORS / Traefik / Apache)](#deployment-notes-cors--traefik--apache)
- [Troubleshooting](#troubleshooting)
- [Module file map](#module-file-map)

---

## Concepts

Three entities:

| Entity | Role |
|---|---|
| **TrackingSource** | A credentialed ingestion endpoint ("write key"). Has a kind, secrets, origin allow-list, rate limit and counters. Managed by tenant admins under **Configurations** in the navbar. |
| **TrackingEventType** | The per-tenant event dictionary (`page_view`, `purchase`, …). Auto-created on first sight when the source allows it. |
| **TrackingEvent** | One row per received event. **Append-only ledger** — creation happens only through the ingest pipeline; UI/API edits and deletes are blocked by record hooks (delete is admin-only). |

### Trust model

The source's `kind` decides how much the payload is trusted
(single source of truth: `TrackingSource::isTrustedKind()`):

| Kind | Path | Authentication | May assert |
|---|---|---|---|
| `Server`, `Chatwoot` ("Chat"), `Other` | **Trusted** | Mandatory HMAC-SHA256 body signature (`X-Tracking-Signature`) | `contactId`, `ipAddress`, `userAgent`, `parentType`/`parentId`, `occurredAt` |
| `Website` | **Public** | None — the source id in the URL is a public write key. Gated by the `Origin` allow-list + per-IP rate limit | Nothing privileged — those fields are stripped; IP/UA are derived server-side |
| `Mobile` | **Public** | None (no Origin check either — native apps have no Origin) | Same as Website |
| `CRM` ("CRM (Internal)") | **Internal-only** | None needed — never reachable over HTTP (the endpoint answers 404 for it). Events are written in-process by `InternalEventRecorder` | Everything — the writer is the CRM itself (see [Internal CRM events](#internal-crm-events-kindcrm)) |

Events record which path they came through in the `channel` field
(`server` / `browser`).

The ingest endpoint (both routes are `noAuth`):

```
POST https://{crm-host}/api/v1/TrackingEvent/receive/{trackingSourceId}
```

---

## Quick start: track your website

### 1. Create the source

**Configurations → Tracking Sources → Create** (tenant-admin):

- **Kind**: `Website`
- **Allowed Origins**: one origin per line, e.g. `https://www.example.com`.
  Mandatory for Website — browser POSTs from any other origin get `403`.
  Origins are exact-match (`https://example.com` ≠ `https://www.example.com`;
  add both if both resolve).
- **Auto-create Unknown Codes**: leave on while developing; turn off once
  your event dictionary stabilizes.
- Assign at least one **Team** — this derives the Tenant and controls who
  sees the events.

Save. The detail view now shows the **Ingest URL** and the **Install
Snippet** (Website kind only).

### 2. Paste the snippet

Copy the Install Snippet field into the site's `<head>` (every page):

```html
<!-- Monostax Tracker -->
<script>
(function(w,d,u,n){w[n]=w[n]||function(){(w[n].q=w[n].q||[]).push(arguments)};
var s=d.createElement("script");s.async=true;s.src=u;d.head.appendChild(s)})
(window,document,"https://{crm-host}/client/custom/modules/feature-tracking-event/lib/tracker.js","mstx");
mstx("init","https://{crm-host}/api/v1/TrackingEvent/receive/{sourceId}");
mstx("page");
</script>
```

The loader is async and command calls are queued, so it never blocks page
rendering and `mstx(...)` can be called before the script finishes loading.

### 3. Verify

Load the page with DevTools open → Network → filter `receive`. You should
see a POST returning **202** `{"ok":true,...}`. On the TrackingSource
detail view, **Total Events** increments and **Last Event Status** shows
`Accepted`. Events appear in the *Events* relationship panel and under
Tracking Events.

---

## Installing via Google Tag Manager

Create a **Custom HTML** tag with the exact snippet above and fire it on
**Initialization / All Pages** (and `gtm.historyChange` for SPAs — the
tracker is double-load-guarded, but prefer calling only `mstx("page")` on
history changes).

Pitfalls we've hit in production:

- `mstx("page")` is the command that sends the pageview. **Any
  unrecognized command is a silent no-op** — e.g. `mstx("PAGE_WAS_VIEWED")`
  fires nothing and reports no error. Valid commands: `init`, `page`,
  `track`, `identify`, `reset`.
- After publishing the container, browsers cache `gtm.js` for up to ~15
  minutes — verify in a fresh/incognito window.
- **Ad blockers block GTM entirely** (`googletagmanager.com` is on every
  block list). If you see no requests at all, disable the ad blocker
  before concluding the tag is broken. Loading the tracker via the plain
  snippet (first-party host) instead of GTM avoids that failure mode for
  the tracker itself.

---

## Browser SDK reference

Served statically (no auth, no PHP) from:

```
https://{crm-host}/client/custom/modules/feature-tracking-event/lib/tracker.js
```

Dependency-free, ~4 KB gzipped, command-queue API on `window.mstx`.

| Command | Effect |
|---|---|
| `mstx('init', ingestUrl)` | Required first. Captures UTM/click-id attribution from the landing URL (last-touch, persisted 30 days in `localStorage`). |
| `mstx('page' [, props])` | Sends a `page_view` event. `title` and `path` are added automatically. |
| `mstx('track', code [, props])` | Sends a custom event. `code` must match `[a-z][a-z0-9_]{0,63}` (server lowercases, then rejects anything else). `props.value` (number) and `props.currency` (`USD/EUR/BRL/GBP/MXN/ARS`) are lifted to top-level payload fields; everything else travels under `properties`. |
| `mstx('identify', email [, signature [, timestamp]])` | Attaches an identity claim to all subsequent events (persisted across pages). See [Verified identity](#verified-identity-identify). |
| `mstx('reset')` | Clears anonymous id, identity and attribution. Call on logout. |

Examples:

```js
mstx('track', 'pricing_viewed', { plan: 'pro' });
mstx('track', 'purchase', { value: 199.9, currency: 'BRL', plan: 'pro' });
mstx('identify', 'user@example.com');                       // soft claim
mstx('identify', 'user@example.com', hexHmac, unixSeconds); // verified
```

Behavior details:

- **Anonymous id** — UUID persisted in `localStorage` (`mstx_anon`), with
  in-memory fallback when storage is unavailable.
- **Attribution** — `utm_source|medium|campaign|term|content`, `gclid`,
  `fbclid`, `msclkid`, `ttclid` captured whenever present in the URL
  (last-touch wins), stored with `landing_page`/`referrer`/`captured_at`,
  expires after 30 days, attached to every event as `attribution`.
- **Transport** — `fetch` with `Content-Type: text/plain` and
  `keepalive: true`, falling back to `navigator.sendBeacon`. `text/plain`
  keeps the POST a CORS *simple request*: **no OPTIONS preflight** (which
  this deployment's edge would swallow — see
  [Deployment notes](#deployment-notes-cors--traefik--apache)) and
  sendBeacon-compatible for exit events.
- The server enforces a **64 KB** body cap.

---

## Server-side (trusted) tracking

For kind `Server` / `Chatwoot` / `Other`, a **Signing Secret** is mandatory
(validated on save). Sign the **raw request body** with HMAC-SHA256 and
send the hex digest in `X-Tracking-Signature` (optional `sha256=` prefix).

```bash
BODY='{"code":"subscription_renewed","contactId":"67a...","value":49.9,"currency":"BRL"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')

curl -X POST "https://{crm-host}/api/v1/TrackingEvent/receive/{sourceId}" \
  -H "Content-Type: application/json" \
  -H "X-Tracking-Signature: sha256=$SIG" \
  -d "$BODY"
```

```php
$signature = hash_hmac('sha256', $rawBody, $secret);
```

```js
const signature = crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
```

The trusted path may set `contactId` (must exist within the tenant),
`ipAddress`, `userAgent`, `occurredAt` and a parent link
(`parentType` ∈ `Lead|Opportunity|Contact|Account` + `parentId`).

Secrets are encrypted at rest (`EncryptSecrets` hook) and write-only: the
API always reads them back masked (`********`). Saving the mask sentinel
keeps the stored value; saving a new string rotates the secret.

---

## Internal CRM events (kind=CRM)

The CRM can track events about itself — currently Opportunity funnel
motion — into the same TrackingEvent ledger. This does **not** go through
the HTTP endpoint: an Espo hook is already inside the trust boundary, so
`Hooks/Opportunity/TrackStageChange` calls `InternalEventRecorder`
in-process, which shares the persistence core (`TrackingEventPersister`)
with the ingester. No secret, no origin, no rate limit; the ingest endpoint
answers 404 for kind=CRM sources.

### Enabling (opt-in, per tenant)

Nothing is recorded until the tenant **creates a Tracking Source with
kind = "CRM (Internal)"** and assigns their team. That row is the whole
settings surface:

| Lever | Effect |
|---|---|
| No kind=CRM source exists | Internal tracking is **off** (recorder silently skips) |
| Source `isActive` off | Internal tracking **paused** |
| `allowUnknownEventCode` off | Only pre-created `TrackingEventType` codes are recorded |
| A `TrackingEventType` row deactivated | That single code is muted (e.g. keep `opportunity_won`, mute `opportunity_stage_changed`) |

**Exactly one CRM source per tenant** is enforced on save
(`ValidateSingleCrmSourcePerTenant`) — the recorder resolves the
destination by `(kind=CRM, tenantId)`, so a second row could only split or
duplicate the stream. Secret/origin/rate-limit/ingest-URL fields are
hidden for this kind (not applicable).

### Emitted events

| Code | When | Extras |
|---|---|---|
| `opportunity_stage_changed` | Every `opportunityStage` transition, including the initial stage on create (`fromStageId: null`) | — |
| `opportunity_won` | Derived `status` transitions to `Won` | `value`/`currency` from the opportunity amount |
| `opportunity_lost` | Derived `status` transitions to `Lost` | — |

All carry `parentType=Opportunity` + `parentId`, `contactId` (the
opportunity's primary contact, if set) and a `payload` with
`fromStageId/Name`, `toStageId/Name`, `funnelId/Name`, `status`, `amount`,
`assignedUserId`, `isNew`. Auto-created types get `category=System` (not
`Custom`), so internal lifecycle events stay distinguishable in reporting.

Recording is fail-safe: `InternalEventRecorder.record()` never throws — a
tracking failure is logged and can never break the user's save. Tenancy is
derived from the opportunity's teams (`teams -> Tenant.baseUserTeam`,
unambiguous single match required).

---

## Verified identity (`identify`)

Browsers can't keep secrets, so a public `identify` is only a *claim*.
Two modes, per source:

- **No `identityVerificationSecret` configured** — claims are accepted
  as-is (soft trust, the Mixpanel model). Fine for low-stakes analytics.
- **Secret configured** — the claim must carry a valid HMAC, computed by
  **your backend** (never ship the secret to the browser):

  - `identitySignature = HMAC_SHA256(email, secret)` — static, or
  - `identitySignature = HMAC_SHA256(email + ":" + identityTimestamp, secret)`
    with `identityTimestamp` in unix seconds — replay-limited to **24 h**.

  Invalid/missing signatures don't fail the request — the event is stored
  **anonymous** (the identity claim is ignored and logged).

Typical flow: on login, your backend returns `{email, signature, timestamp}`
and the page calls `mstx('identify', email, signature, timestamp)`.

Email resolution finds the most recently modified non-deleted Contact with
that email **within the tenant**. Unknown emails leave the event anonymous.

---

## Payload reference

Canonical JSON body (`code` is the only required key):

```jsonc
{
  "code": "purchase",                    // REQUIRED [a-z][a-z0-9_]{0,63}
  "occurredAt": "2026-07-07T12:00:00Z",  // clamped to server "now" if future
  "anonymousId": "anon-abc",             // ≤ 64 chars
  "email": "user@example.com",           // identity claim
  "identitySignature": "hex",            // see Verified identity
  "identityTimestamp": 1780000000,
  "url": "https://...",                  // ≤ 1024
  "referrer": "https://...",             // ≤ 1024
  "value": 199.9,
  "currency": "BRL",                     // USD|EUR|BRL|GBP|MXN|ARS
  "attribution": { "utm_source": "...", "gclid": "..." },
  "properties": { "anything": "goes" },

  // Trusted path only (stripped silently on the public path):
  "contactId": "...",
  "ipAddress": "...",
  "userAgent": "...",
  "parentType": "Opportunity",
  "parentId": "..."
}
```

The full (post-strip) body is preserved in the event's `payload` field.

## Response codes

| Status | Meaning |
|---|---|
| `202` | Accepted — `{"ok":true,"eventId":"..."}`. Also returned for deliberately **skipped** events (unknown code with auto-create off) so the endpoint is not an oracle. |
| `400` | Malformed body / missing-invalid `code` / body > 64 KB / unconfigured source (no tenant/teams). |
| `401` | Trusted path: missing or invalid `X-Tracking-Signature`. |
| `403` | Website path: `Origin` missing or not on the allow-list. **No CORS headers on 403** — the response must not be readable cross-origin. |
| `404` | Unknown or inactive source id. |
| `429` | Rate limited (`Retry-After: 60`). |

Every non-2xx outcome also updates the source's *Last Event Status/Error*
fields — the first place to look when debugging.

---

## Event types and auto-creation

Events resolve to a `TrackingEventType` by `(code, tenant)`:

- Existing + active → used.
- Existing + inactive → event **skipped** (202, not stored).
- Missing + source has **Auto-create Unknown Codes** on → type auto-created
  (`category=Custom`, teams inherited from the source). Concurrent
  first-sight races on the unique `(code, tenant)` index are handled.
- Missing + auto-create off → event **skipped**.

Types carry per-type counters (`totalEventsReceived`, `lastEventAt`),
bumped atomically (`SET x = x + 1`) alongside the source counters.

## Anonymous-to-Contact stitching

When an event arrives with **both** a resolved Contact and an
`anonymousId`, the `AnonymousStitcher` job is queued (serialized per
anonymous id via job group). It retroactively assigns that Contact to every
earlier anonymous event with the same `anonymousId` in the same tenant
(batches of 500, idempotent) — pre-signup browsing history attaches to the
person the moment they identify.

## Tenancy, teams and permissions

Teams for each event row resolve in fallback order:

1. the event type's teams,
2. the source's teams,
3. the tenant's `baseUserTeam`.

If none resolve, ingestion is refused (`400 source is not fully
configured`) — events are never written tenant-less.

Seeded ACL (`Global/Rebuild/SeedRole.php`): tenant base roles get
**read-only team** access to all three entities; **tenant-admin**
additionally gets full CRUD on `TrackingSource` and `TrackingEventType`
(managed via the Configurations navbar panel). `TrackingEvent` stays
read-only for everyone — the append-only ledger is enforced by the
`BlockWrite`/`BlockDelete` record hooks regardless of ACL (delete is
admin-exempt for GDPR/cleanup).

## Rate limiting

Fixed-window (per UTC minute), best-effort, backed by DataCache:

- **Per source** — `rateLimitPerMinute` field (default 600, `0` = unlimited).
- **Per client IP** — additionally on public kinds, 120/min (constant in
  `Services/RateLimiter.php`). Client IP = rightmost `X-Forwarded-For` hop
  (the only entry a client can't forge by prepending).

---

## Deployment notes (CORS / Traefik / Apache)

Three layers conspire on this deployment — know them before debugging CORS:

1. **Apache** (`components/crm/container/000-default.conf`) answers **all
   OPTIONS requests with 200 before PHP** — the module's
   `optionsActionPreflight` route is dead code in containers. Hence the SDK
   only ever issues preflight-free *simple requests* (`text/plain`).
2. **Traefik** — the tenant catch-all route applies the `cors-multi-tenant`
   middleware (ACAO restricted to `*.monostax.*`). A dedicated
   IngressRoute (`gitops/ai-monostax/:tenant/crm/add.crm.IngressRoute.nix`,
   `crm-tracking-ingest-route-https`, priority 150) matches
   `PathPrefix(/api/v1/TrackingEvent/receive)` **without** that middleware
   so browsers on arbitrary customer domains can POST.
3. **PHP is the CORS authority** on the ingest path:
   `TrackingEventReceiver` echoes the request `Origin` only after it passed
   the source's allow-list — and never on 403.

The SDK file itself (`/client/.../lib/tracker.js`) is served by the Apache
`/client/` alias as a plain static file; `<script src>` loads are
CORS-exempt, so no special routing is needed for it.

Client-side pieces live in
`client/custom/modules/feature-tracking-event/`: `lib/tracker.js` is plain
JS (never add it to `scriptList` — it is not an Espo AMD module), while
`src/views/**` + `lib/transpiled/**` are the usual Espo field views.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| No request in the Network tab at all | Ad blocker (especially when loading via GTM), GTM container not published, stale `gtm.js` browser cache (~15 min), or an invalid SDK command — unknown commands like `mstx("PAGE_WAS_VIEWED")` are silent no-ops. |
| `403` on POST | Page origin not in **Allowed Origins** (exact match, scheme included, no trailing slash), or the origin regex trap: apex vs `www.` are different origins. |
| `401` on POST | Signature computed over a re-serialized body instead of the exact raw bytes sent, wrong secret, or secret was rotated. |
| `404` | Wrong source id or source marked inactive. |
| `202` but no event row | Skipped: unknown code with auto-create off, or the event type is inactive. Check the source's *Last Event Status* = `Skipped` and *Last Event Error*. |
| `400 source is not fully configured` | Source has no teams (→ no tenant). Assign a team and re-save. |
| Events stored but invisible to users | Team resolution fell back somewhere users don't have; check the event type/source teams. |
| Identity claims ignored | `identityVerificationSecret` set but signature invalid/missing/expired (24 h window). Check logs — invalid claims are logged, not fatal. |
| Events not stitched to Contact | Stitcher is async — check the `AnonymousStitcher` job status; email must match an existing Contact in the same tenant. |

Server-side truth lives on the TrackingSource row: **Last Event
Status/Error** update on *every* attempt, success or failure. If they are
`NULL`, no request ever reached PHP — look upstream (blocker, DNS, edge).

## Module file map

```
custom/Espo/Modules/FeatureTrackingEvent/
├── Controllers/TrackingEventReceiver.php      # noAuth POST/OPTIONS endpoint
├── Services/TrackingEventIngester.php         # HTTP pipeline (trust, normalize)
├── Services/TrackingEventPersister.php        # shared persistence core (types, teams, counters)
├── Services/InternalEventRecorder.php         # in-process events (kind=CRM, opt-in)
├── Services/RateLimiter.php                   # fixed-window source/IP limits
├── Services/IngestResult.php                  # typed outcomes -> HTTP responses
├── Jobs/AnonymousStitcher.php                 # retroactive contact stitching
├── Entities/{TrackingSource,TrackingEvent,TrackingEventType}.php
├── Hooks/Opportunity/TrackStageChange.php     # stage/won/lost -> internal events
├── Hooks/TrackingSource/EncryptSecrets.php    # secrets encrypted at rest
├── Hooks/TrackingSource/ValidateSingleCrmSourcePerTenant.php  # one CRM source per tenant
├── Classes/RecordHooks/TrackingSource/ValidateKindRequirements.php
├── Classes/RecordHooks/TrackingEvent/{BlockWrite,BlockDelete}.php  # append-only
├── Classes/Record/TrackingSource/OutputFilter.php                  # secret masking
└── Resources/{metadata,layouts,i18n,routes.json,module.json}

client/custom/modules/feature-tracking-event/
├── lib/tracker.js                             # public browser SDK (plain JS)
└── src/views/tracking-source/fields/{ingest-url,tracker-snippet}.js
```
