# WhatsApp registration verification

## Configuration and rollout

Deploy backend/accounting support and compatible Chatwoot assets first. The existing
`verificationKind` varchar field already supports `whatsapp_registration`; no new
table, queue or grouped operation is required. Keep the normal CRM rebuild step.

Web and worker processes use the existing `WAHA_PLATFORM_BACKEND_URL` and
`WAHA_PLATFORM_API_KEY`. HTTP is supported for the existing in-cluster connection;
redirects are never followed. Configure these additional settings identically on
both processes through the shared CRM deployment template:

| Nix setting | Runtime environment | Default |
| --- | --- | --- |
| `crmVerificationWhatsAppEnabled` | `VERIFICATION_WHATSAPP_ENABLED` | `false` |
| `crmVerificationWhatsAppSession` | `VERIFICATION_WHATSAPP_SESSION` | empty |
| `crmVerificationWhatsAppSessions` | `VERIFICATION_WHATSAPP_SESSIONS` | empty; otherwise JSON array of approved session names |

The operator can configure a shared pool of existing sessions for this CRM installation
and shared database. A nonempty pool setting takes precedence over the legacy single
session. Invalid/empty explicit JSON pools fail closed. New sessions require an explicit
pool update; customer input cannot choose or add sessions. Shared sessions also carry
their inbox traffic and share its connection/rate limits.

After the durable dispatch claim and outside database locks, pool mode performs one
GET to `/api/sessions?all=true` (at most five seconds), intersects the approved names
with exact `WORKING` statuses, deduplicates and randomly selects one member. No eligible
member or failed discovery produces a zero-charge failure. Context/admission checks
configuration only, so a runtime pool outage can still be discovered after reservation.
There is no customer pairing, tenant allowlist, automatic session start, health-probe
loop, alternate-number probing or retry/failover after lookup. Missing configuration
disables admission. Disabling does not invalidate retained evidence.

The documented supplier contract is one GET to `/api/contacts/check-exists` with
`phone` (international digits, no `+`) and `session`, authenticated with `X-Api-Key`.
Only a successful HTTP response with a boolean `numberExists` completes. `chatId`,
`pn`, names and other supplier fields are discarded. Discovery and lookup share the
same at-most-30-second timeout budget; discovery cannot extend the dispatch lease.
the original operation deadline is five minutes. A claimed dispatch is never resent.

## Activation smoke test (deployment gate)

The deployed WAHA version and session identity have **not** been validated by local
fixture tests. Do not globally enable until an operator has recorded:

1. Deployed version/engine and approved session pool (or single session), with working connectivity.
2. Controlled lookup of a known registered and known unregistered number using that
   exact pool/API version: boolean true/false, no message or identity mutation.
3. Empty working pool, disconnected-session, malformed-response and timeout behavior
   classified as errors; no lookup retry or automatic session restart.
4. In an isolated deployment using the same version/configuration, save authorized
   test Contacts and confirm each check through the product API/UI. Each definitive
   result settles exactly `2.0000`; failures settle `0.0000` and release reservations.
5. Duplicate confirmation/recovery uses one operation, fresh reuse is free, and an
   explicit refresh after the 60-second cooldown creates one new paid operation.
6. With both selected and only eight credits available, WhatsApp reserves two first;
   ownership can fail admission independently. Confirm no second automatic admission
   after an uncertain WhatsApp response. Verify both supported customer languages.

After these pass, enable globally. Existing credit permissions, Contact/phone/source
access and sufficient funds remain mandatory for every tenant. Twilio activation and
Brazil-only eligibility remain independent.

## API and confirmation contract

- `POST /CreditWhatsAppVerification/context`: `tenantId`, `contactId`, `sources[]`.
- `POST /CreditWhatsAppVerification`: `tenantId`, `contactId`, `source`, `requestKey`,
  `contextRevision`, optional `refresh`, optional `maxChargeCredits`.
- `GET /CreditWhatsAppVerification/status`: tenant/Contact and request key or operation ID.
- `GET /CreditWhatsAppVerification/history`: tenant/Contact, `limit`, `beforeAt`/`beforeId`.

Sources are `contact_phone`, saved `channel` IDs (or channelType/sourceId references),
and purchased receipt/index references. Numbers are always resolved and authorized
server-side. Explicit `+country code` supports international numbers; Brazilian
shorthand is preserved without changing subscriber digits. Extensions, groups,
LIDs and other chat identifiers are rejected.

New phone confirmations send `maxChargeCredits`: `0.0000` for reuse/join, otherwise
`8.0000` for ownership or `2.0000` for registration. The optional ceiling is bound to
the request key. If a free reuse/join becomes unavailable, admission returns 409
`context_changed` before reserving funds. Changed subject/revision likewise requires
new confirmation. Registration evidence older than 30 days requires explicit refresh.
Requests without this additive field preserve legacy phone/email command hashes.

Each kind has independent admission, settlement, polling and a browser recovery
journal containing IDs/revisions/charge terms, never phone values or results. The UI
defaults to ownership, visibly skips ineligible checks, and admits WhatsApp first.
An uncertain first admission stops combined progression (D18). Recovery uses its
original request key; any unstarted check requires another explicit confirmation.
Closing the dialog does not cancel admitted work.

## Monitoring and rollback

Use the existing instance-admin `CreditPhoneVerificationConfiguration` diagnostics:
per-kind state/outcome/failure counts, pending age, waived-after-claim counts and
recovery heartbeat. Run the existing accounting consistency audit to check the
kind/price/outcome bindings. Inspect IDs and aggregate counts, not supplier URLs,
credentials, raw responses or phone values in logs.

`DispatchPhoneVerification` must continue running every minute. It closes crashed
claims/deadlines and releases holds, and erases evidence at 90 days. Contact privacy
erasure scrubs ciphertext and waives pending operations; financial receipts remain.

Rollback by setting `crmVerificationWhatsAppEnabled = false` on web and workers.
Retain compatible backend/accounting/schema and scheduled recovery. Retained results
stay readable and reusable; no new dispatch is allowed. Never delete financial
records or deploy old code that cannot reconcile the new kind.
