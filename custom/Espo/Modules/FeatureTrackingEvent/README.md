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
- [Internal CRM events (kind=CRM)](#internal-crm-events-kindcrm)
- [Trackable short links (TrackingLink)](#trackable-short-links-trackinglink)
- [WhatsApp click-to-chat attribution](#whatsapp-click-to-chat-attribution)
- [Verified identity (`identify`)](#verified-identity-identify)
- [Payload reference](#payload-reference)
- [Response codes](#response-codes)
- [Event types and auto-creation](#event-types-and-auto-creation)
- [Event and type names](#event-and-type-names)
- [Anonymous-to-Contact stitching](#anonymous-to-contact-stitching)
- [Tenancy, teams and permissions](#tenancy-teams-and-permissions)
- [Rate limiting](#rate-limiting)
- [Deployment notes (CORS / Traefik / Apache)](#deployment-notes-cors--traefik--apache)
- [Troubleshooting](#troubleshooting)
- [Module file map](#module-file-map)

---

## Concepts

Four entities:

| Entity | Role |
|---|---|
| **TrackingSource** | A credentialed ingestion endpoint ("write key"). Has a kind, secrets, origin allow-list, rate limit and counters. Managed by tenant admins under **Configurations** in the navbar. |
| **TrackingEventType** | The per-tenant event dictionary (`page_view`, `purchase`, …). Auto-created on first sight when the source allows it. |
| **TrackingEvent** | One row per received event. **Append-only ledger** — creation happens only through the ingest pipeline; UI/API edits and deletes are blocked by record hooks (delete is admin-only). |
| **TrackingLink** | A trackable short link: `GET .../go/{slug}` records a click event and 302-redirects to the target URL (see [Trackable short links](#trackable-short-links-trackinglink)). |

### Trust model

The source's `kind` decides how much the payload is trusted
(single source of truth: `TrackingSource::isTrustedKind()`):

| Kind | Path | Authentication | May assert |
|---|---|---|---|
| `Server`, `Other` | **Trusted** | Mandatory HMAC-SHA256 body signature (`X-Tracking-Signature`) | `contactId`, `ipAddress`, `userAgent`, `parentType`/`parentId`, `occurredAt` |
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
| `mstx('init', ingestUrl [, options])` | Required first. Captures UTM/click-id attribution from the landing URL (last-touch, persisted 30 days in `localStorage`). Options: `decorateWaLinks` (default `true`) — embed the visitor id into WhatsApp anchors' pre-filled text; `decorateShortLinks` (default `true`) — append `mstx_a={anonymousId}` to TrackingLink short-URL anchors; `shortLinkHosts` (array) — extra hostnames treated as short-link domains (your `trackingLinkDomain`). |
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
- **Attribution** — `utm_source|medium|campaign|term|content|id`, `gclid`,
  `wbraid`, `gbraid`, `fbclid`, `msclkid`, `ttclid` captured whenever present
  in the URL (last-touch wins), stored with
  `landing_page`/`referrer`/`captured_at`,
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

For kind `Server` / `Other`, a **Signing Secret** is mandatory
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
| `opportunity_created` | Opportunity created (alongside the initial `opportunity_stage_changed`) | Distinct code so creations are filterable and mutable per-code |
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

## Trackable short links (TrackingLink)

Mautic/bit.ly-style links that record a click event and redirect
(**Configurations → Tracking → Links**, tenant-admin):

```
GET https://{crm-host}/api/v1/TrackingLink/go/{slug}     (noAuth)
  -> records TrackingEvent (code = eventCode, default link_clicked)
  -> 302 to targetUrl (+ forwarded params + mstx_* handoff)
```

Create a link with a **name**, a **target URL** and a **tracking source**
(same tenant; not `CRM (Internal)`). The immutable random `slug` and the
copy-paste **Short URL** appear after save. Teams default to the source's
teams; the tenant is derived from teams as everywhere else.

**Invariants** (`Services/TrackingLinkRedirector`):

- **Redirect > record.** Once an active slug resolves, the visitor is
  redirected no matter what — rate limits (600/min/link + the public
  per-IP cap) and recording failures only skip the *event*, never the
  redirect. Inactive/unknown slug = `404` (deactivating a link kills it;
  to keep redirecting but stop counting, deactivate its Event Type
  instead).
- **Param pass-through.** Ad platforms append click ids to whatever URL
  they're given — every incoming query param (`fbclid`, `gclid`,
  `utm_*`, …) is forwarded onto the target URL *and* captured server-side
  into the click event's `attribution` (ad-block-proof: the redirect is
  server-observed).
- **Bot flagging.** Unfurl/scanner hits (WhatsApp/Slack/Telegram previews
  etc.) are recorded with `payload.isLikelyBot=true`, not dropped —
  dedupe in analytics, the ledger keeps raw truth.

**Identity handoff.** The click is recorded under the visitor's own
`anonymousId` whenever the page runs `tracker.js`: its short-link
decorator (default on; `mstx('init', url, {decorateShortLinks: false})`
to disable) rewrites short-link anchors at interaction time with
`mstx_a={anonymousId}` — first-party `localStorage` only, no cookies —
so the click (and any WhatsApp conversation it produces) joins the
visitor's page-view history. Anchors are recognized by the
`/api/v1/TrackingLink/go/` path on any host; when you use a dedicated
short domain, list it: `mstx('init', url, {shortLinkHosts:
["mstx.to"]})`. Clicks arriving without a valid `mstx_a` (shared links,
QR codes, other people's forwards) get a fresh minted id. Either way the
id is appended to the target URL as `mstx_a` and `tracker.js` on the
landing page adopts it (when the browser has none yet), so the click
joins the visitor's journey and is stitched retroactively when they
identify. `mstx_l={slug}` is captured into attribution like a UTM.

**Known-recipient links.** Mint a per-recipient URL (read ACL on both
records + same tenant enforced):

```
POST /api/v1/TrackingLink/mintContactUrl
{"id": "{linkId}", "contactId": "{contactId}", "ttlDays": 90}
-> {"url": "https://.../{slug}?c={token}"}
```

The `c` token is a stateless HMAC (`Services/ContactToken`, signed with
the installation `cryptKey`, tenant-bound, default TTL 90 days). The click
lands with `contactId` set, and the token is forwarded as `mstx_c` so the
landing session identifies too (the ingester verifies `contactToken`
body fields on both paths) — which stitches the recipient's prior
anonymous browsing history. Invalid/expired/foreign tokens degrade
silently to the anonymous path.

**Dedicated short domain.** Set the `trackingLinkDomain` config (e.g.
`https://mstx.to`) and route `GET {domain}/{slug}` →
`/api/v1/TrackingLink/go/{slug}` at the edge — **and nothing else on that
host**. Keeping the CRM origin out of public links means a
phishing/blocklist incident on the link domain never taints the login
origin, and no CRM cookies ride along with redirect GETs. Prefer a
separate apex over a subdomain (subdomains share Safe-Browsing/mail
reputation with the root). `shortUrl` and minted URLs use it
automatically; empty = fall back to `{siteUrl}/api/v1/TrackingLink/go/…`.

---

## WhatsApp click-to-chat attribution

Joins WhatsApp conversations to the web click/session that produced them
— who messaged you *because of which ad/campaign* (the tintim.app model).
Requires the Chatwoot module (inbound messages arrive via its
conversation sync).

**Send side — invisible token.** The visitor's `anonymousId` is embedded
into the pre-filled message as zero-width Unicode characters
(`Services/ZeroWidthCodec`; wire-compatible with tintim: `U+FEFF` region
markers, `U+2060` char separators, `U+200B`/`U+200C` binary digits).
Invisible to the lead, survives the WhatsApp hop as ordinary message
text. Two producers:

- **TrackingLink → wa.me target.** When `targetUrl` is a
  `wa.me`/`*.whatsapp.com` click-to-chat URL, the redirector embeds the
  click's `anonymousId` — the visitor's own tracker.js id when the anchor
  was decorated (`mstx_a`), else a fresh per-click mint — into the `text`
  param instead of the (useless there) `mstx_*` query handoff. The click
  event additionally carries `payload.isWhatsApp` + `payload.waPhone`.
  The target **must have a `text` param** — with nothing visible to
  embed into, the link still redirects/records but relies on the
  time-window fallback.
- **tracker.js decorator** (default on; `mstx('init', url,
  {decorateWaLinks: false})` to disable). WhatsApp anchors on the page
  are rewritten at interaction time with the *stored* visitor id — this
  covers WhatsApp CTAs that never went through a short link — and a
  `whatsapp_click` event is tracked.

**Receive side** (`Services/WhatsAppAttributionLinker`, invoked by the
Chatwoot module's conversation sync — lazy FQCN, same pattern as the
Meta CAPI bridge). Every newly-synced incoming message is scanned;
matches record a `whatsapp_conversation_linked` event that copies the
origin click's `attribution` (fbclid/utm_*) and `trackingLink`, carries
the reconciled Contact, and schedules the anonymous-history stitch.
Match ladder, best first:

1. **token** — exact: zero-width payload decoded from the message. The
   linked event's `parent` is the origin click/session event, and
   consumption is per-origin: re-syncs and re-sent identical messages
   are no-ops, while a new click by the same visitor (sticky browser id)
   is a new origin and links a fresh conversation.
2. *(ctwa_clid — Meta click-to-WhatsApp ads referral — is handled by
   FeatureMetaConversionsApi, not here.)*
3. **time_window** — fuzzy fallback for erased pre-filled text: a NEW
   tokenless conversation is matched to the nearest unconsumed, non-bot
   wa.me short-link click targeting the receiving inbox's number within
   15 minutes. Marked `payload.matchType="time_window"` so downstream
   dispatch can weigh it accordingly (token matches are
   `matchType="token"`).

Caveats: the lead must send the pre-filled text unmodified for tier 1
(hence marketing copy like *"envie esta mensagem sem apagá-la"*); the
zero-width technique is undocumented WhatsApp behavior — valid Unicode
that clients currently preserve, but treat tiers 2–3 as the safety net.

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

## Event and type names

`TrackingEvent.name` is a human label — `{typeLabel} · {detail}` — composed
by `TrackingEventNameBuilder` at persist time (no timestamp: `occurredAt`
is its own column). Examples:

- `Clique em Link · WhatsApp Inbound` (short-link click; detail = link name)
- `Visualização de Página · /precos` (page view; detail = payload `title`,
  else URL path)
- `Conversa do WhatsApp Vinculada · +5511933253711`
- `Oportunidade Ganha · Projeto ACME` (internal events; detail = the
  `detail` option passed to `InternalEventRecorder`)

The label part resolves as: **TrackingEventType.name** when it differs
from the raw code (types are tenant-editable — renaming a type improves
future event names), else the i18n map `TrackingEvent > eventCodeLabels`
(en_US + pt_BR shipped), else prettified code (`form_submitted` → `Form
Submitted`). Language ladder: `Tenant.language` → instance default →
`en_US`. Auto-created types are seeded with the translated label instead
of the raw code. Names are data frozen at creation — they are not
re-rendered per viewing user's locale.

Backfill legacy `{code} @ {timestamp}` rows (idempotent, `--dry-run`
supported):

```sh
php command.php tracking-event:rebuild-names
```

## Anonymous-to-Contact stitching

When an event arrives with **both** a resolved Contact and an
`anonymousId`, the `AnonymousStitcher` job is queued (serialized per
anonymous id via job group). It retroactively assigns that Contact to every
earlier anonymous event with the same `anonymousId` in the same tenant
(batches of 500, idempotent) — pre-signup browsing history attaches to the
person the moment they identify. The same happens when a WhatsApp
conversation is linked by token (see above).

**Returning visitors are linked continuously.** Stitched rows KEEP their
`anonymousId` — the `(anonymousId, contact)` pair on the ledger is the
identity map. Every later contact-less event (SDK events, short-link
clicks, tokenless linked conversations) resolves its Contact at write time
via `TrackingEventPersister::resolveContactIdByAnonymousId` (most recent
attributed row wins, contact re-verified, backed by the
`(anonymousId, occurredAt)` index), so a known browser never goes
anonymous again between identify moments.

Trade-offs, deliberately accepted: a **shared browser** keeps resolving to
the first identified person until a newer identify/stitch supersedes it
(last stitch wins), and `mstx('reset')` — call it on logout — is what
severs a browser from the Contact (a fresh id is minted).

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
├── Controllers/TrackingLinkRedirect.php       # noAuth GET short-link redirect
├── Controllers/TrackingLinkMint.php           # authenticated per-recipient URL minting
├── Services/TrackingEventIngester.php         # HTTP pipeline (trust, normalize)
├── Services/TrackingEventPersister.php        # shared persistence core (types, teams, counters)
├── Services/InternalEventRecorder.php         # in-process events (kind=CRM, opt-in)
├── Services/TrackingLinkRedirector.php        # slug -> record click -> 302 (+param pass-through, wa.me embed)
├── Services/TrackingEventNameBuilder.php      # human event/type names ({label} · {detail}, tenant language)
├── Services/ContactToken.php                  # stateless HMAC contact tokens (tenant-bound)
├── Services/ZeroWidthCodec.php                # invisible payloads in WhatsApp texts (tintim wire format)
├── Services/WhatsAppAttributionLinker.php     # conversation <-> click join (token / time-window)
├── Services/RateLimiter.php                   # fixed-window source/IP limits
├── Services/IngestResult.php                  # typed outcomes -> HTTP responses
├── Jobs/AnonymousStitcher.php                 # retroactive contact stitching
├── Entities/{TrackingSource,TrackingEvent,TrackingEventType,TrackingLink}.php
├── Hooks/Opportunity/TrackStageChange.php     # stage/won/lost -> internal events
├── Hooks/TrackingSource/EncryptSecrets.php    # secrets encrypted at rest
├── Hooks/TrackingSource/ValidateSingleCrmSourcePerTenant.php  # one CRM source per tenant
├── Hooks/TrackingLink/{GenerateSlug,CascadeTeamsFromSource,AssignTenantFromTeam,ValidateLink}.php
├── Classes/RecordHooks/TrackingSource/ValidateKindRequirements.php
├── Classes/RecordHooks/TrackingEvent/{BlockWrite,BlockDelete}.php  # append-only
├── Classes/Record/TrackingSource/OutputFilter.php                  # secret masking
├── Classes/FieldSanitizers/AsciiUrl.php                            # IRI -> URI for pasted targetUrls
├── Classes/ConsoleCommands/TrackingEventRebuildNames.php           # name backfill (tracking-event:rebuild-names)
└── Resources/{metadata,layouts,i18n,routes.json,module.json}

client/custom/modules/feature-tracking-event/
├── lib/tracker.js                             # public browser SDK (plain JS)
├── src/views/tracking-source/fields/{ingest-url,tracker-snippet}.js
└── src/views/tracking-link/fields/{short-url,target-url}.js
```
