# FeatureCredits

Shared tenant credit accounting. Implemented foundations: exact quantities, pure AI pricing, protected accounting entity/schema definitions, transactional grant funding/expiration, internal operation holds with earliest-expiry-first allocations and pre-request release, persisted AI request authorization/extension, request outcomes with exact accrued pricing and authoritative completion of unknown metering, atomic AI operation settlement with allocation-linked consumption and late expiration, policy-controlled cancellation reconciliation/waiver, bounded recovery discovery, read-only projection consistency audits, expiration-aware balance status, paginated financial reports and CRM UI, and immutable tenant cutovers/execution routing with legacy-protocol isolation. Production reconciliation configuration/scheduling, purchasing, and unified execution adapters remain pending.

## Tenant cutover and execution regime

`Accounting/Cutovers::schedule(CutoverInput)` is an internal trusted service. Commands bind a tenant, explicit UTC `cutoverAt`, and nonempty canonical approval evidence. Scheduling owns a MySQL/PostgreSQL transaction, takes the existing tenant lock, rejects missing/deleted tenants and new backdated cutovers, and stores one immutable `TenantCreditCutover`. Identical retries return the original receipt; changed terms/evidence or a deleted record conflict. It creates no wallet or financial posting and never infers an opening grant.

`CreditExecutionRoute` binds a globally unique run ID to tenant, workflow, execution owner, immutable billing regime, original admission time, cutover snapshot, and optional unified `usageId`. Unique keys exclude deletion. Route insertion shares the actual admission transaction; route-write failure rolls back the admission. Internal `ExecutionRouting` primitives require the caller's tenant-lock transaction.

- `Chatwoot/Tools/Billing/AiBudget` retains HMAC/controller authentication and trusted tenant resolution. New legacy admissions persist `legacy-engagement-v1`. Existing legacy reservations are adopted on matching replay/settlement without repricing or changing their admission time. They finish under legacy semantics after cutover.
- The legacy status/admit protocol denies new unified work with `ai_credit_execution_required`; boolean settlement cannot mutate a unified operation. An old event with neither reservation nor route remains unadmitted with a null regime, rather than inheriting the current tenant cutover. No route/debit is created for that event.
- Execution-backed unified callers must pass `ReservationInput.execution` (`ExecutionIdentity`) and its `ai-run:<runId>` operation key. First admission requires reached cutover and rejects existing legacy identities. Matching replay requires the original unified route and usage reference, even after closure or changes to current cutover configuration. Execution binding uses version-3 hashes; earlier v1/v2 commands retain their hashes. The canonical execution prefix cannot be supplied without an execution binding.
- New route/cutover schema must be rebuilt before serving the updated budget authority. No production cutover is configured by this module. Once a cutover is reached, current legacy callers are denied, including auxiliary calls. Unified HTTP adapters, provider bounds/pricing, dispatch permission, terminal evidence, recovery, and transcription handling must be completed before pilot activation. Internal operation holds alone never authorize dispatch.

Both-dialect tests cover generated constraints, cutover boundary/replay/conflict guards, original-regime retention, missing/deleted/conflicting routes, financial/admission rollback, and separate-process schedule/admission races. The real-database legacy budget suite also covers original-admission adoption, boolean settlement isolation, rollback, and competing legacy admissions. Backend contracts are documented in `components/backend/docs/credit-execution-routing.md`.

## Signed unified admission and request authorization

`POST /CreditExecution` also accepts `admit` and `authorize`, using the same
dedicated signature protocol described below. Both require `billingRegime`
(`unified-prepaid-v1`), `tenantId`, `runId`, `workflowRunId`, `executionId`, and
`modelRateId`. Admission additionally requires `billingRateId` (an existing active
same-tenant agreement); authorization instead requires `usageId` and canonical
`requestKey`. Unknown fields, amounts, multipliers, caller token bounds, and source
attribution are rejected. Trusted workers must resolve tenant/agreement context.

`Pricing/AiCatalog` reads immutable **`AiModelCreditRate` entities** by their
content-addressed `policyId`. An instance administrator publishes policy versions
and tenant agreements through `POST /CreditConfiguration`; see
`components/crm/docs/unified-credit-configuration.md`. The legacy
`featureCreditsAiCatalog` config parameter is no longer consumed.

```php
// Illustrative structure only: supply explicitly validated production values.
$entry = [
    'provider' => $provider,
    'model' => $model,
    'multiplier' => $decimalString,
    'inputTokenBound' => $approvedConservativeInputCeiling,
    'outputTokenLimit' => $enforcedTotalOutputCeiling,
    'boundProfile' => $versionedProviderAdapterProfile,
];
$policy = new \Espo\Modules\FeatureCredits\Pricing\AiModelPolicy($entry);
// Configuration::publishModel((object) $entry) stores the version and returns
// its CRM entity ID and generated policyId. No Nix/environment catalog is used.
```

The reference hashes an ordered `ai-model-policy-v1` tuple containing provider,
model, formula/base rates, normalized exact multiplier, both ceilings, and profile.
Changing any financial/bound identity requires a new reference; editing an entry
under an old reference fails closed. Missing entries, unsafe/nonpositive integer
ceilings, malformed identities/multipliers, extra fields, and amount overflow are
unavailable configuration, with no default pricing/limits. These are fixed
conservative ceilings, not worker estimates. Input bounds must cover all billable
input, including cache/native-tool input where applicable; output includes reasoning.
Retain approved entries needed for recovery of admission/authorization retries.

The signed `configuration` command accepts `billingRegime`, `tenantId`, `runId`,
`workflowRunId`, `executionId`, `provider` and `model`, and returns
`{billingRegime, operation, selection: {billingRateId, modelRateId, maxRequests}}`.
It selects the single effective CRM agreement, or the original agreement for an
already-admitted execution, and checks ownership and the actual caller model.
This creates no wallet, hold, route or dispatch permission. Agreement versions
cannot overlap; prospective replacements close the previous selection window
without changing its prices or any already-admitted financial records.

Admission derives `max(0.5000, policy request bound)` server-side and persists a
version-4 reservation input hash binding the initial policy reference, including
when different policies have the same price/bound. Existing internal v1/v2/v3
hashes are unchanged. A hold grants no dispatch permission. Request authorization
checks the original route under tenant/wallet locks, persists the applied price,
limits and `boundProfile`, and grants permission only on first committed
authorization. Replays always return `dispatchAllowed: false`; a lost first
response is not permission to retry the provider. Changed request policies
conflict. Closed operations cannot authorize a new request.

Both responses are `{billingRegime, operation, receipt, policy}`; `policy` contains
`modelRateId`, `provider`, `model`, `inputTokenBound`, `outputTokenLimit`, and
`boundProfile`. Receipt shapes are the existing reservation/request service
contracts. Terminal outcome/settlement/release uses persisted accounting facts and
does not depend on the catalog still being configured.

Catalog validation establishes exact configuration and immutable references. It
does **not** establish actual provider limit semantics or enforce the SDK wire
request. Production entries require validated provider profiles and corresponding
backend enforcement before activation. No production entries are supplied by the
module. Signed transport is implemented in backend `$creditExecution.ts`; durable
binding persistence and the text/client-tool main caller are integrated. The
selected production agent's native search/bounded-evidence profile remains a
blocker to its live activation.

Service-created entity IDs use `Accounting/RecordId` and fit CRM's standard
17-character ID/link columns. Older wider IDs remain valid API inputs; tests now
exercise the generated default-width schema instead of assuming 24-character IDs.

## Signed unified execution terminal API

`POST /CreditExecution` accepts trusted worker commands `outcome`, `settle`, and
`release`. It uses a dedicated `AI_CREDIT_EXECUTION_SECRET`; it is unavailable
without that environment variable. Espo session authentication is bypassed by the
route, but the controller always requires these headers before parsing commands:

- `X-Credit-Execution-Timestamp`: Unix seconds, within 300 seconds of server time.
- `X-Credit-Execution-Signature`: `sha256=` plus lowercase HMAC-SHA256 hex over
  `credit-execution-v1.<timestamp>.<exact raw JSON body>`, keyed by the secret.

Legacy budget signatures do not authorize this protocol. The dedicated secret is
service authority across tenants and must only be available to trusted execution
workers. They derive `tenantId` from trusted admission context, never browser/agent
arguments. Normal administrator/API-user credentials alone grant no access.

All terminal commands require string fields `operation`, `billingRegime` (exactly
`unified-prepaid-v1`), `tenantId`, `usageId`, `runId`, `workflowRunId`, `executionId`.
`outcome` additionally requires the persisted CRM `requestId` (not request key or
provider ID), terminal `outcome`, `inputTokens`, `cachedInputTokens`, `outputTokens`,
and nonempty object `evidence`. `providerRequestId` is an optional string/null.
Token counts must be explicit nonnegative safe integers or null. Output already
includes reasoning exactly once; missing/unknown metering must never become zero.
Successful, cancelled, superseded, and infrastructure-failed requests retain the
existing `OutcomeInput` semantics, including authoritative measured recovery.
Unknown fields, caller amounts/prices, boolean billing, reasoning counters, and
metering on settle/release commands are rejected. Signed bodies are capped at
64 KiB with JSON depth 32. Evidence should reference authoritative results, not
carry model prompts or entire provider payloads.

Each accounting service receives the validated `ExecutionIdentity` and checks its
persisted route under the existing tenant/wallet transaction **before replay or
writes**. Missing/deleted/foreign/legacy/conflicting routes, usage identity or
operation-key mismatches, and simultaneous legacy admissions fail closed. Current
cutover configuration cannot change the admitted route. Existing internal callers
may omit this additional transport guard; no public terminal command can omit it.

Responses are `{billingRegime, operation, receipt}` with the existing service's
exact-decimal receipt and private/no-store middleware. `settle` derives charges
only from persisted request snapshots/outcomes; unknown requests remain held.
`release` only closes operations with no persisted requests. Repeating the same
command recovers the existing receipt/current request state; conflicting facts
fail. Transport timestamp retries may be freshly signed without changing facts.
Authentication timestamps limit transport replay age; financial idempotency is
enforced by accounting identities, not by a nonce cache.

Recovery scheduling and policy-based cancellation waiver transport, backend
production callers/provider enforcement, deployment/secret provisioning, and live
HTTP acceptance remain pending.

## CRM-owned execution attempts and outcome delivery

`CreditExecutionAttempt` and `CreditOutcomeDelivery` are protected, rebuild-managed
CRM entities. They replace the local backend-PostgreSQL prototype; no credit Drizzle
schema or migration runner is required. Standard create/update/delete/link/unlink
and import/export cannot mutate them. A request digest is retained instead of the
provider prompt/body; trusted outcome evidence should contain only bounded metering
and provenance needed for accounting/recovery.

`POST /CreditDispatch` uses the same dedicated `AI_CREDIT_EXECUTION_SECRET` and
timestamp/signature headers as `CreditExecution`, with a distinct HMAC domain:
`credit-dispatch-v1.<timestamp>.<raw-body>`. The timestamp window is 300 seconds;
body limit is 64 KiB and JSON depth is 32. Commands reject extra fields and caller
delivery acknowledgements. Responses are `{operation, result}` and private/no-store.

Allowed commands:

- `claimIntent {intent}` → boolean, true only for the first committed claim.
  Intent contains original unified `scope` (tenant/usage/run/workflow/execution),
  stable `requestKey`, SHA-256 `requestHash`, `kind` (`generate`/`stream`), and the
  immutable six-field model policy returned by credit admission/authorization.
  Same-input replay returns false; changed input conflicts. A closed or sealed operation
  cannot claim a new request. Claims alone grant no financial/provider permission.
- `bind {binding}` → null. Binding is the intent plus `requestId`; validates the
  actual request, reservation, policy snapshot and ownership before saving its
  unique CRM request link. Matching replay is permitted only before completion is
  sealed. All post-seal bind commands are rejected, including recovered-binding
  replay, so a late acknowledgement cannot satisfy a worker's dispatch prerequisite.
- `load {scope, requestKey}` → `{intent, requestId}` (nullable before binding),
  after revalidating the persisted execution route and exact original scope.
- `enqueue {binding, facts, phase}` → null. Facts use the existing terminal outcome
  wire fields. Phase 0 is the immutable original; phase 1 is a measured resolution
  of an acknowledged unknown original with the same terminal outcome. Financial
  validity is enforced again when applying the queued facts through `Outcomes`.
- `deliver {tenantId, leaseMs, retryMs}` → `empty`, `delivered`, `retry`, or `leaseLost`.
  Each timing must be an integer between 1,000 and 3,600,000 ms; stored UTC timestamps
  round delays upward to seconds. Processes at most one due item, selected by the
  indexed tenant/state/due/id query. No caller facts or receipts are accepted here.
- `seal {scope}` → null. Once all work for this operation has stopped, its trusted
  owner persists an immutable `CreditExecutionRoute.completionRequestedAt` and an
  immediately due completion intent. Replays preserve the original timestamp.
  The tenant lock serializes the seal against new claims and authorizations;
  existing authorization replay remains readable with `dispatchAllowed: false`.
- `recover {scope}` → `{state: 'settled'|'released', receipt}` or
  `{state: 'pending', reason: 'request_recovery'|'outcome_delivery'|'metering'|'settlement'|'release'}`.
  Requires the original persisted route and seal. Reads at most 100 attempts and
  100 accounting requests (plus a sentinel row to detect overflow), validates
  ownership/policy, then applies their saved original/resolution facts through
  `Outcomes`. Known facts progress even when another attempt is ambiguous or its
  outcome fails. All requests must be covered and terminal before settlement;
  pre-request release requires both attempt and request sets to be empty.
  An unbound saved attempt can recover its link from an existing accounting request
  with the same usage and original request key, after validating tenant, reservation,
  execution and all six policy fields. The link is saved under the tenant lock;
  recovery issues no authorization and creates no request or outcome. Missing
  authorization or outcome facts remain pending. Recovered links are readable with
  `load` and accept late original facts through `enqueue`, but cannot reopen `bind`.
  Oversized or corrupted records fail closed. Missing facts are never inferred as
  zero or a waiver. Returns the existing financial receipt on replay.
- `recoverOne {tenantId, leaseMs, retryMs}` → `empty`, `completed`, `pending`, or
  `leaseLost`. Same explicit timing bounds as `deliver`. Leases one due sealed
  operation under the tenant lock, commits, then invokes recovery. Failed/pending
  work is delayed by `retryMs`, allowing newer work to progress. The scope comes
  from CRM; callers cannot submit completion receipts or replacement facts.

Completion scheduling uses nullable `completionRequestedAt`, `completionFinishedAt`,
`completionDueAt`, and `completionLeaseToken` on the existing protected route, with
a tenant/finished/due/id index. These fields require a CRM rebuild. Final completion
acknowledgement follows the financial commit; a crash between them replays the
original idempotent settlement/release and then acknowledges it. The existing
outcome-delivery worker can still acknowledge mailbox records after completion.

`Accounting/DispatchStore` serializes mailbox writes on the Tenant row without
creating a wallet or changing balances. Lease claiming commits before invoking
`Outcomes`; the accounting service owns its separate financial transaction. A lost
acknowledgement/crash can replay identical facts after lease expiration, retaining
one financial effect. A competing owner cannot acknowledge another owner's lease.
Failed application retains original facts and explicit retry timing. Bad/deleted/
foreign parents and changed request policies fail closed.

CRM owns the queue, bindings and acknowledgements. The backend Hatchet delivery
workflow only polls explicitly configured tenants through the signed API. Lease
recovery never dispatches provider work. Crash recovery before outcome facts reach
CRM or before a completion seal, provider metering lookup, retention,
deployment/live HTTP and main-workflow activation remain separate pending work.
Sealing is an explicit owner assertion that all operation work has stopped;
it cannot stop an already-dispatched provider call. Recovery does not infer that
an unsealed crashed worker stopped merely because time elapsed. The backend
completion coordinator seals after local work stops; the separately configured
`AI_CREDIT_OPERATION_RECOVERY` Hatchet task polls sealed operations only.

## CRM Credits & Usage page

Open `#Credits/index/tenantId=<id>` directly or use **Credits & Usage** in the existing AI Usage page. The page uses `GET /Credits/context` to list only the current user's financial-administrator tenants through `Access::tenants`; ordinary members, portal users, and API users receive an empty list. The context response uses private/no-store middleware. Every report and source request independently enforces its server-side authorization.

The English/Portuguese page displays exact posted/available/held/pending-expiration balances and paginated operations, grants, reservations, AI requests, and posted transactions. Each table uses the existing 25-row keyset API. Refresh and report/tenant changes restart pagination; previous/next navigation retains opaque cursor positions. Balance and report observation timestamps are shown separately because their snapshots are independent. Decimal strings are rendered without numeric conversion. Null final charges stay distinct from measured zero; waiver and unknown-metering states remain visible.

Tenant switching, clearing selection, and view removal invalidate outstanding responses and source navigation. Loading or failed reads clear previously shown financial data. Explicit unauthorized tenant URLs do not silently select a different tenant. Source buttons resolve the current operation through `CreditOperationSource` before using a standard CRM record route; repeated clicks are suppressed and stale resolutions cannot navigate after a tenant/report change. Opening the target still enforces its normal ACLs. The page has empty/unavailable/expired-session/no-wallet/exhausted states, manual refresh/retry, responsive tables, escaped content, and labeled status/error controls.

Verified by rendered-template, view-state race/error/pagination/navigation tests and the production AMD transpiler. Full deployment/rebuild and direct/embedded browser acceptance remain pending. Purchasing, production execution adapters, and Chatwoot native UI are separate pending work.

## Trusted admission source attribution

`ReservationInput` accepts an optional `SourceReference` argument, e.g. `new SourceReference('Opportunity', $opportunityId)`. Only trusted internal callers may construct accounting commands. They must derive the tenant, operation/execution identities, and source from their authorized execution context, not an untrusted request body. No public reserve endpoint is introduced.

- Supports existing `ChatwootAiAgentRun` and `Opportunity` records. `SourceReference` validates a canonical CRM ID and an explicit table allowlist shared with navigation.
- On first admission, `Reservations` validates source existence, non-deletion, and exact tenant ownership under a source row lock after the tenant/wallet locks. Source columns are written in the same transaction as usage/reservation/allocations/holds. Invalid source attribution or a later write failure rolls back admission.
- Source-bearing commands use a version-2 input hash binding source type/ID. Source-less commands retain their exact version-1 hash. Adding, removing, or changing attribution on an existing operation conflicts; matching replay returns current financial state before revalidating a mutable source, so source deletion cannot break replay/release or reassign charges.
- Attribution is optional for backward compatibility and for executions whose source does not yet exist. No historical inference/backfill or post-admission rewrite occurs. The new source must exist before first admission if attribution is supplied.
- No production caller currently invokes unified `Reservations`; provider limits/pricing and authenticated unified execution adapters remain pending. Persisted regime selection and legacy isolation are implemented above. This implements the trusted accounting admission contract, not production workflow activation.

MySQL/PostgreSQL tests verify both source types, cross-tenant/missing/deleted/unattributed rejection, atomic rollback, immutable replay after source deletion, legacy no-source compatibility, and separate-process duplicate/conflicting source admissions.

## Financial administrator balance API

`GET /CreditBalance?tenantId=<id>` uses the normal authenticated Espo API route and returns the `BalanceStatus::inspect` snapshot as a JSON object. The non-entity `CreditBalance` scope resolves the controller to this module; it does not expose generic financial record mutations.

- Requires an explicit valid tenant identity; missing, array, numeric, blank, whitespace-padded, or oversized values produce `BadRequest`.
- Reuses `FeatureAiUsage/Services/Access`, the existing financial administrator policy: instance administrators or tenant administrators within their authorized memberships. Team-derived administrator permissions remain limited to the corresponding tenants. Ordinary members, portal users, API users, and foreign-tenant administrators are denied before accounting is read. Trusted backend API-user execution integration needs a separate contract.
- Sets `Cache-Control: private, no-store`, including controller validation/access failures. `Classes/Api/PrivateResponse`, registered through `app/api.controllerMiddlewareClassNameListMap.CreditBalance`, restores this exact header after Espo's `ControllerActionHandler` applies its generic success headers. This covers both the explicit route and generic controller-action routing. Returns `tenantId`, UTC `observedAt`, boolean `walletExists`, and four-decimal **strings** `balance`, `reservedCredits`, `pendingExpirationCredits`, `availableCredits`.
- An absent wallet is explicitly distinguished from an existing zero balance; reads never create a wallet. Missing/deleted accounting parents or inconsistent projections fail instead of being replaced by a zero balance.
- This endpoint reports funds, not billing-regime eligibility or permission to dispatch inference. Active production rebuild and live administrator, team-scoped tenant isolation, member/API-user denial, and embedded-origin cookie-session HTTP checks have passed (release record). Operational-member/Chatwoot account mapping and UI integration remain pending. The new success-header middleware still requires deployment and a live header recheck.

Verification includes real-policy denial tests and controller-to-accounting MySQL/PostgreSQL tests for absent wallets, maximum exact JSON quantities, read-only behavior, and corrupt-projection rejection. `PrivateResponseTest` reproduces the header overwrite through the real Espo handler, then verifies metadata-selected middleware restores privacy without changing response status, JSON body, or other headers. Existing authorization regression tests cover team-scoped tenant administration.

## Financial administrator transaction history API

`GET /CreditHistory?tenantId=<id>&limit=50&cursor=<nextCursor>` returns posted ledger history through the normal authenticated Espo API. `Controllers/CreditHistory` uses the same billing administrator `Access::assertTenant` policy as the balance endpoint before obtaining an accounting connection. The non-entity scope exposes no financial mutations. Endpoint-scoped `PrivateResponse` middleware preserves `Cache-Control: private, no-store` after Espo's success handler; controller validation and access failures also set this header.

- `Accounting/TransactionHistory::inspect(TransactionHistoryQuery)` returns `{tenantId, observedAt, list, nextCursor}`. Each list item contains only `id`, `type`, signed four-decimal string `credits`, and UTC `occurredAt`/`postedAt` timestamps. Posting types are `grant`, `debit`, `expiration`, and future `reversal`. One settlement can produce several allocation-linked debit postings; this is a posting list, not one row per operation.
- The default limit is 50; accepted query-string values are canonical integers from 1 through 100. A supplied cursor must be a nonempty canonical base64url version-1 position containing the selected tenant, posting timestamp, and ID. Invalid tenants, limits, timestamps, versions, or mismatched cursor tenants yield `BadRequest`. Treat cursor strings as opaque in clients. They are positions, not secrets or authorization credentials: every page is independently authorized and SQL is always tenant-scoped.
- Descending keyset pagination uses the existing `(tenantId, postedAt, id)` index and reads at most `limit + 1` rows. `nextCursor` is null on the final page; there is no total-count or offset scan. No wallet is created, no expiration runs, and reservations/accrued-but-unposted charges do not appear as transactions.
- Each page owns a database-enforced read-only repeatable-read transaction, including tenant validation. Missing/deleted tenants and deleted/invalid inspected ledger postings fail closed. History does not join mutable source records, so source deletion cannot erase a posting. This is not a full ledger consistency audit.
- Pages are **live history**, not a cross-request export snapshot. Immutable posting keys prevent repeating already-returned rows. A concurrent posting ordered before the cursor requires a first-page refresh; a newly committed posting ordered after it can appear on a later page (including same-second timestamps). `observedAt` describes that page only. Restart pagination when refreshing or changing tenant; discard previous-tenant responses.
- The response allowlist excludes evidence, idempotency keys, actor identities, source IDs/names, pricing snapshots, and source links. Future source-detail navigation must enforce source-record ACLs separately. Grant/usage/reservation/purchase views and pending-reconciliation reporting remain separate work.

Both-dialect tests verify exact maximum signed quantities, funding/expiration and measured-settlement history, same-second tie-breaking, concurrent append and snapshot visibility, replay-safe paging, tenant isolation, source-deletion preservation, immutable accounting reads, read-only enforcement/cleanup, and transaction guards. Controller tests cover malformed input and real-policy denial before any accounting read. Real-handler middleware tests cover both history and balance. Full application rebuild and live history HTTP/UI acceptance remain pending.

## Financial administrator grant balances API

`GET /CreditGrants?tenantId=<id>&limit=50&cursor=<nextCursor>` uses the same financial-administrator `Access::assertTenant` policy as balance/history, authorizing every page before accounting access. The authenticated non-entity controller and endpoint-scoped middleware return `Cache-Control: private, no-store`, including after the framework success handler.

- `Accounting/GrantBalances::inspect(GrantBalancesQuery)` returns `{tenantId, observedAt, list, nextCursor}`. Each item contains only `id`, `sourceType` (`purchase`, `subscription`, or `migration`), four-decimal strings `grantedCredits`, `remainingCredits`, `reservedCredits`, `pendingExpirationCredits`, `availableCredits`, and UTC `expiresAt` (nullable) and `createdAt` timestamps. Source keys, snapshots, payment identities, and source links are excluded.
- Remaining credits include holds and unposted expiration. At `expiresAt <= observedAt`, all unreserved remaining credits become pending expiration and available credits become zero; held credits remain reserved. After an expiration sweep or late release, the returned projections reflect the posted changes. Purchased non-expiring funds remain available. Exhausted lots remain listed with their original quantity and expiration.
- Uses descending `(createdAt, id)` keyset pagination and the new `(tenantId, createdAt, id)` index. Default limit 50, maximum 100, at most `limit + 1` inspected rows. Canonical opaque cursors are versioned `grants-1`, tenant-bound, and distinct from transaction-history cursors. Invalid query input is rejected before accounting access. No total-count scan or source expansion is performed.
- Each page owns a database-enforced read-only repeatable-read snapshot. Reads do not create wallets or expire grants. Missing/deleted tenants and inspected deleted/invalid grant projections fail closed, including negative holds, holds exceeding remaining credits, and remaining exceeding original credits. This bounded report validates inspected lots, not whole-wallet consistency; use `BalanceStatus` and `ConsistencyAudit` for their respective checks.
- Balances are live per page, not a cross-page export snapshot or admission permission. Immutable creation keys prevent duplicates of already-returned grants as balances change. Concurrent grants ordered before the cursor require a first-page refresh; those ordered after it can appear later. Restart pagination on refresh or tenant change and discard stale responses. Do not sum separate pages or separately fetched balance/history responses as though they shared one snapshot.

Both-dialect tests cover tie-breaking/pagination, concurrent funding and snapshot isolation, tenant isolation, exact maximum JSON quantities, expiration boundaries, preserved holds, late release, exhausted lots, source allowlisting, corrupt projections, read-only enforcement/cleanup, and transaction guards. Controller/policy and real-handler middleware tests cover validation, access denial, and private headers. Deployment/rebuild of the added index and endpoint, live HTTP, and financial UI integration remain pending.

## Financial administrator reservation history API

`GET /CreditReservations?tenantId=<id>&limit=50&cursor=<nextCursor>` authorizes each page through the existing financial-administrator `Access::assertTenant` policy before accounting access. The authenticated non-entity controller uses endpoint-scoped private-response middleware, preserving `Cache-Control: private, no-store` after Espo's success handler.

- `Accounting/ReservationHistory::inspect(ReservationHistoryQuery)` returns `{tenantId, observedAt, list, nextCursor}`. Items contain only `id`, accounting `usageId`, `operationType` (`ai` or `apollo`), persisted `state`, four-decimal strings `reservedCredits` and nullable `settledCredits`, unrounded decimal string `accruedCreditsExact`, and UTC `createdAt`, `modifiedAt`, and nullable `settledAt` timestamps. No execution/operation keys, actors, source links, pricing, or provider/reconciliation evidence are exposed.
- Held reservations remain `held` during both inference and unknown-metering reconciliation under the current accounting lifecycle. `accruedCreditsExact` reports only known billable accrual; it is **not** a final charge or an estimate of missing usage. `settledCredits` stays null until closure, including measured-zero but unsettled work. Clients must not coerce null to zero. Closed reservations remain listed: `settled` has the stored operation charge and `released` has zero, with zero current hold in either case. Request-level pending/waiver reporting is available through `CreditRequests` below.
- Grant expiration does not erase an existing hold. Reads neither expire nor release funds. After actual settlement or pre-request release, projections show closure and the recorded timestamp. Accrual remains exact (e.g. `0.0001875`) while final consumption reflects operation-level half-up rounding (e.g. `0.0002`).
- Descending `(createdAt, id)` keyset pagination uses the new `(tenantId, createdAt, id)` reservation index. Default limit 50, accepted limits 1–100, and at most `limit + 1` inspected rows, including the lookahead. Canonical opaque `reservations-1` cursors are tenant-bound and distinct from grant/history cursors. There is no offset or total-count scan; the only join is the unique usage parent.
- Each page owns a database-enforced read-only repeatable-read snapshot including tenant validation. Missing/deleted tenants, deleted reservations, missing/deleted/cross-tenant/execution-mismatched usage parents, and contradictory inspected state/quantity projections fail closed. Validation includes nonnegative holds/accrual, active accrual within its hold, matching closure timestamps, closed zero holds, and settled amounts matching rounded accrual. This bounded report does not replace the full ledger/allocation/request consistency audit.
- States are live per page, not a cross-page snapshot. Closing a cursor row does not invalidate the cursor. Concurrent admissions ordered before the cursor require a first-page refresh; ones ordered after it can appear on later pages. Restart pagination on refresh or tenant change, discard stale responses, and do not sum separately fetched pages as one snapshot. Reporting does not authorize execution or source-record navigation.

Verification covers both-dialect lifecycle, unknown metering, exact accrual/settlement, late release, pagination/concurrent changes, tenant and parent isolation, maximum JSON quantities, read-only enforcement/cleanup, and snapshot stability across another connection's release. Controller/policy and real-handler middleware tests cover validation, denial before accounting reads, and private headers. Index/endpoint deployment, live HTTP, and UI acceptance remain pending.

## Financial administrator operation history API

`GET /CreditOperations?tenantId=<id>&limit=50&cursor=<nextCursor>` reports one row per accounting usage operation. The authenticated non-entity controller authorizes every page with `Access::assertTenant` before accounting access and preserves `Cache-Control: private, no-store` through endpoint-scoped middleware. The protected `CreditUsage` entity retains its service-only mutation contract.

- `Accounting/OperationHistory::inspect(OperationHistoryQuery)` returns `{tenantId, observedAt, list, nextCursor}`. Items contain only `id` (usage identity), `reservationId`, `operationType` (`ai` or `apollo`), `billingRegime` (`unified-prepaid-v1`), persisted `state`, four-decimal string `reservedCredits`, unrounded decimal string `accruedCreditsExact`, nullable four-decimal string `settledCredits`, UTC `admittedAt`, and nullable `settledAt`.
- Current lifecycle states are `admitted`, `settled`, and `released`. An admitted operation may contain unknown request metering; known accrual is not a final charge. `settledCredits` remains null until closure and must not be displayed as zero. Request-level pending/waiver details remain available through `CreditRequests`. Closed operations retain their rounded charge (or zero pre-request release) and have zero current hold.
- Descending `(admittedAt, id)` keyset pagination uses the new `(tenantId, admittedAt, id)` index. Limits are canonical integers 1–100 (default 50), inspecting at most `limit + 1` rows. Canonical opaque `operations-1` cursors are tenant-bound and endpoint-specific. Every page is a live read-only repeatable-read snapshot, not a cross-page export snapshot; refresh from the first page for newer operations and discard stale responses on tenant changes.
- The query starts from usage and joins its unique reservation, detecting missing/deleted/cross-tenant/execution-mismatched reservations rather than hiding orphan usage. Invalid regimes, states, holds/accrual, closure timestamps, and rounded charges fail closed, including corrupt lookahead rows. Missing/deleted tenants and nested transactions are rejected. This bounded projection check does not replace the full consistency audit.
- Source IDs/types/names, actor identities, operation/execution keys, pricing, and evidence are excluded. Source deletion does not remove financial history. Source navigation still requires separate source-record ACL checks. Reads do not create wallets, mutate funds, or authorize inference.

MySQL/PostgreSQL verification covers lifecycle/rounding, unknown usage, late settlement/release, replay, pagination/concurrent closure, snapshot stability, exact maximum JSON quantities, source allowlisting, corrupt/missing reservation rejection, and database-enforced read-only cleanup. Controller tests cover tenant-administrator success, malformed input, and member/portal/API/foreign-admin denial; real-handler tests cover private response headers. Source navigation has a separate ACL-aware endpoint below. Deployment/index rebuild, live HTTP, trusted source attribution, and UI remain pending.

## Financial administrator operation source API

`GET /CreditOperationSource?tenantId=<id>&id=<usageId>` resolves a single operation's optional CRM source through the authenticated non-entity controller. `Access::assertTenant` runs before operation/source reads; endpoint-scoped middleware preserves `Cache-Control: private, no-store`. Both query identities must be canonical 1–24-character CRM identities, as in the accounting reports.

- Returns only `{tenantId, id, source}`. `source` is either null or `{scope, id, name}`. Missing/deleted tenants or missing/deleted/foreign-tenant operations yield `NotFound`; an authorized tenant's operation never reveals another tenant's source.
- `Services/OperationSource` supports `ChatwootAiAgentRun` and `Opportunity`, whose records have explicit `tenantId` ownership. It requires scope-read permission, a present/non-deleted same-tenant record, and record-read permission. The name is null when field-read permission is denied. Financial administration alone does not grant source access.
- Missing pointers, malformed IDs, unsupported source types, deleted/missing sources, absent/mismatched source tenants, and ACL denial all return the same null source without exposing its identity, name, or rejection reason. Stored source types cannot dispatch arbitrary entity reads. No source identities are added to financial list responses.
- Resolution uses bounded ordinary ORM reads and live ACL evaluation, without a source cache, a financial snapshot, or accounting mutations. It is a navigation hint, not a capability: opening the target record must still use its normal authorized CRM route. Clients should use the allowlisted scope/ID as a standard record link, escape names, and discard old-tenant responses.
- Admission can persist an optional immutable validated `SourceReference` as described above. Production execution adapters must establish that attribution; this endpoint does not infer it from execution keys, accept caller-supplied target pointers, or backfill historical records. Additional source types require an explicit tenant ownership contract and authorization tests.

Verification covers malformed queries, real financial-policy denials, operation/tenant isolation, scope/record/name ACLs, missing/deleted/unsupported sources, permission reevaluation, explicit response allowlisting, authenticated routing, and real-handler privacy middleware. The CRM page now uses this endpoint for navigation. Deployment/rebuild, live HTTP/browser acceptance, and production execution attribution remain pending.

## Financial administrator request history API

`GET /CreditRequests?tenantId=<id>&limit=50&cursor=<nextCursor>` authorizes every page through `Access::assertTenant` before accounting access. The authenticated non-entity controller and endpoint-scoped middleware preserve `Cache-Control: private, no-store` after the framework success handler.

- `Accounting/RequestHistory::inspect(RequestHistoryQuery)` returns `{tenantId, observedAt, list, nextCursor}`. Items contain only `id`, accounting `usageId`/`reservationId`, `operationState`, `outcome`, `meteringState`, `billingState`, nullable `waiverReason`, four-decimal string `authorizedCredits`, nullable unrounded decimal string `pricedCreditsExact`, and UTC `authorizedAt`/nullable `completedAt`. Evidence, provider identities, request/execution keys, reconciliation policies/owners, pricing snapshots, tokens, and source links are excluded.
- `inFlight`/`pending`/`unknown` is distinct from terminal unknown metering (`success`, `cancelled`, or `superseded` with `pending`/`unknown`). Measured zero is the string `"0"`, not null. Request outcomes remain visible after settlement; `operationState` distinguishes admitted from settled operations.
- `waived` reports either `infrastructure_failure` (measured or unknown) or `unrecoverable_cancellation` (unrecoverable). A measured waived request retains its exact price but contributes no charge. A pending or unrecoverable request retains a null price. Clients must use billing state, never interpret missing usage as zero, and never sum waived prices as billable usage.
- `authorizedCredits` is the original request bound, **not a current hold**. `pricedCreditsExact` is **not a posted charge**: rounding occurs once per operation, with the final amount in `CreditReservations` and postings in `CreditHistory`. Current holds remain in reservation reporting. Reporting grants no provider dispatch permission or source-record access.
- Descending `(authorizedAt, id)` keyset pagination uses the new `(tenantId, authorizedAt, id)` request index, default limit 50, maximum 100, and at most `limit + 1` inspected rows. Canonical opaque `requests-1` cursors are tenant-bound and endpoint-specific. No offset/count scan or evidence expansion occurs. Resolving a cursor row preserves pagination; refresh from page one for newer requests. Pages are live, not a cross-page export snapshot; restart on tenant changes and discard stale responses.
- Each page owns a database-enforced read-only repeatable-read snapshot. Missing/deleted tenants, inspected deleted requests, missing/deleted/cross-tenant/mismatched usage and reservation parents, invalid state/price/waiver combinations, and billable bound violations fail closed, including lookahead rows. Current request services support AI; unsupported Apollo request records fail closed until Apollo settlement/reporting is implemented. This bounded projection validation does not replace evidence verification or whole-ledger/allocation auditing.
- Verification: both-dialect lifecycle, unknown versus measured zero, exact prices, measured/unknown infrastructure waivers, policy-backed cancellation waiver, settled history/replay, pagination/concurrent resolution, parent/tenant isolation, response allowlist, snapshot/read-only enforcement, and controller/privacy checks. Deployment/rebuild, live HTTP, and UI acceptance remain pending.

## Arithmetic contract

- `Accounting/Amount` represents signed `NUMERIC(14,4)` quantities, including ledger debits. Wallet/grant services must separately enforce nonnegative balances and authorization constraints.
- Inputs to `Amount::fromString` must be decimal **strings**; numbers, scientific notation, whitespace, excess precision, and out-of-range values are rejected. Output/JSON always contains four decimal places as a string. Do not convert amounts through PHP floats or JavaScript `Number`.
- `brick/math` is a direct Composer dependency (already locked at 0.14.8). Exact calculation works without requiring BCMath/GMP.
- Keep request charges as unrounded `BigDecimal` values until operation settlement. `Amount` is for posted amounts/holds, not intermediate pricing.
- `AiPricing::request` expects normalized input (including cache), cached input, and output (including reasoning). Null counts require reconciliation. Reasoning must not be added again.
- `AiRate` is an immutable in-memory snapshot with provider/model/rate identity and formula `ai-per-10000-v1`. It does not activate a model catalog or persist pricing references. A future formula must retain the old evaluator for admitted operations.
- `AiPricing::settle` sums exact billable request charges and rounds half-up once. `Settlements` applies the same `Amount::settlement` boundary after validating every request; omitting unknown billable usage is not a valid settlement.
- `AiPricing::requiredHold` returns the **total** required hold, including accrued charges and all in-flight bounds, rounded upward. Initial admission uses `max(0.5000, total)` rather than adding a minimum. The service must compute a delta under lock, enforce available funds, and allocate eligible grants before returning authorization.
- Construct request bounds only from conservative input bounds and enforced output/reasoning limits. These helpers do not derive provider limits or authorize requests.

## Persistence foundation

Use Espo's `decimal` field type with explicit `precision: 14, scale: 4`, not the default precision of 13 or a float/currency field. The core Decimal converter maps this to a string-valued attribute backed by a decimal database column. Validate with `Amount` before saving; core ORM decimal preparation only pads the fractional part and is not a financial validator.

The module defines ten internal entities:

| Entity | Persistence responsibility |
| --- | --- |
| `TenantCreditBalance` | One non-null unique tenant key; signed ledger balance and held-credit projection. |
| `TenantCreditBillingRate` | Versioned dated commercial terms, exact unit price/monthly allowance, renewal/restriction snapshots. |
| `CreditUsage` | Tenant/type-scoped business-operation key, input hash, execution ownership, admitted billing regime and agreement, nullable settled amount. |
| `CreditReservation` | One reservation per usage; held amount, exact accrued pricing string, reconciliation evidence and state. |
| `CreditRequest` | Usage-scoped request key; authorization bound, applied pricing snapshot, provider/model/action, independent outcome/metering/billability/waiver evidence. |
| `CreditGrant` | Tenant/source-scoped funding identity, unique grant posting, original quantity and mutable remaining/held projections, expiration and source snapshot. |
| `CreditAllocation` | Reservation/allocation-key/grant identity; optional request attribution, held/consumed/released/expired amounts and posting links. |
| `CreditTransaction` | Tenant/type-scoped posting key, input hash, signed amount, usage/grant/allocation attribution, actor, timestamps, evidence and original reversal reference. |
| `TenantCreditCutover` | Immutable tenant-unique cutover timestamp and approval evidence/hash. |
| `CreditExecutionRoute` | Immutable globally unique run routing, tenant/workflow/execution ownership, original regime/admission time, optional unique unified usage reference. |

Important contracts for the accounting-service slice:

- `Classes/Database/RequiredFields` preserves explicitly required fields when building the SQL schema. Espo's generic converter otherwise makes foreign IDs nullable, and its decimal converter loses `notNull`. The modifier retains all converted attributes, relationships, and indexes. It is local to these ten entities.
- Unique financial identities exclude `deleted`, so soft deletion cannot free a key for reuse. Generic record create/update/delete/link/unlink hooks always reject mutations, including administrator calls. No entity controller, import/export surface, or tenant reverse relationship is exposed. Trusted internal ORM/SQL code can still write; this is not a database privilege boundary.
- Required links are SQL `NOT NULL`, **not foreign-key/tenant-equality constraints**. Accounting services must validate existence and same-tenant ownership under locks. Funding now validates tenant existence and grant/wallet amounts. Agreement non-overlap, usage ownership, duplicate/over-reversal checks, and reservation state transitions remain service work.
- `inputHash` supports conflicting grant, operation-hold, and request replay rejection as specified below. Settlement accepts only existing operation/execution identities and derives all quantities from persisted outcomes; allocation posting identities are specified below.
- Request `pricingSnapshot` must preserve rate identity/formula and every applied decimal parameter. Persistent model/action rate catalogs and immutability enforcement remain pending.
- Unknown metering/pricing/settlement stays nullable or explicitly pending. `pricedCreditsExact` and `accruedCreditsExact` are unrounded decimal **strings**, not four-decimal posted amounts. Validate these through exact arithmetic before persistence; never sum them with SQL coercion or floats.
- Allocation `reservedCredits` records the held lot quantity. At closure it is partitioned into consumed, released, and expired quantities; unused late release goes to `expiredCredits`, not spendable balance. Operation-level allocation ordering, pre-request release, expiration-revalidated request assignment/extension, and AI consumption are implemented.
- Reversals can be attributed to their original allocation through transaction links. No refund-into-expired-grant or depleted-balance policy is selected by this schema.

Both-dialect schema tests establish generated-table creation, required tenant keys, unique identities, exact PDO decimal round trips, and a basic wallet-update rollback. Accounting tests additionally establish funding/expiration/operation-hold/request/AI-settlement multi-record atomicity and concurrency as described below. Balance controller authorization is tested above; full application rebuild/metadata merging and live HTTP authentication remain to be verified.

## Internal funding contract

`Accounting/Funding` is injectable through Espo's factory (`WalletLock` receives the existing `EntityManager` connection and an injectable UTC `Clock`). It is an internal service, with no HTTP route or record mutation action. Only trusted payment/entitlement/migration adapters should construct `GrantInput`, after verifying their source; those adapters and their authentication/authorization are still pending. An evidence snapshot is an audit record, not payment verification.

- `grant(GrantInput): array` takes the trusted tenant ID, source type (`purchase`, `subscription`, `migration`), canonical source key, positive decimal-string credits, UTC occurrence timestamp, nullable UTC expiration, detached JSON-object evidence, and optional existing actor ID. Purchases cannot expire; subscriptions require a next-renewal expiration after occurrence; migrations use explicitly supplied terms. Future occurrence timestamps are rejected on first posting.
- Source keys are 1–128 lowercase ASCII characters matching `[a-z0-9][a-z0-9._:/-]*`. Adapters must derive them from durable business identities (e.g. provider/payment, entitlement/period, agreed migration), never webhook delivery IDs. For opaque case-sensitive upstream IDs, use a stable hash rather than lowercasing them and conflating distinct identities.
- Hash version `grant-v1` includes tenant, source type/key, normalized four-decimal quantity, occurrence/expiration, actor, and canonical evidence. Object keys are sorted recursively; list order is significant; decimal evidence must be strings, not floats. A matching tenant/source/key replay returns the original `{grantId, transactionId, grantedCredits, expiresAt}` receipt. Changed input conflicts, including changed evidence/actor. Receipts do not represent the current spendable balance.
- Every mutation owns one PDO transaction and rejects nested transactions. Lock order is **Tenant → wallet → grants → future reservations/allocations → postings**. Locking the existing tenant first serializes initial wallet creation and establishes the convention future accounting services must share. The clock is sampled after lock acquisition. Unknown/deleted tenants and deleted financial records are rejected; optional actors must exist on first posting.
- Funding locks/sweeps eligible old grants, inserts one positive ledger posting and its new grant, then updates the wallet atomically. An already-expired delayed grant receives its positive grant posting and equal expiration debit in the same transaction. Obsolete funds are expired before adding new funds to avoid transient wallet-overflow rejection.
- `expire(tenantId): array` returns `{balance, reservedCredits}` after sweeping expired **unreserved** funds under the same locks, with one `sweep:<grantId>` expiration posting per grant. Running again has no additional financial effect. Purchase grants survive. Held funds remain in both wallet balance and grant remaining credits; balance is not synonymous with availability. Calling on an existing tenant without a wallet creates an empty wallet.
- Projections require `remaining >= reserved >= 0`; all calculations use `Amount`, and overflow aborts the transaction. SQL is parameterized; postings are append-only through this service. Callback errors, constraint failures, or failed commits roll back the whole transaction. Infrastructure/deadlock retries must repeat the same command; the service does not blindly retry uncertain outcomes.
- Operation admission sweeps/filters expired grants even when the expiration job is delayed. Pre-request release and AI settlement expire late releases with allocation-scoped postings. No expiration scheduler is enabled by the funding service.

Real-database funding tests exercise same/conflicting-key replay, tenant separation, delayed grants, renewal, expiration with seeded holds, exact balances, overflow, nested-transaction rejection, and injected database failures at posting/grant/wallet boundaries. Separate PHP processes prove lock contention, first-wallet replay safety, no lost concurrent funding updates, and concurrent renewal/expiration behavior. Seeded hold projections are **not** evidence of implemented reservation allocation or concurrent spending.

## Internal operation-reservation contract

`Accounting/Reservations` shares `WalletLock` and `Funding`'s expiration sweep. These are trusted internal services; adapters must establish tenant attribution, cutover, feature eligibility, and conservative pricing before calling them. An operation hold is **not a provider-request dispatch authorization**. `Accounting/Requests` persists request identity/pricing, assigns still-eligible held funds, and rechecks expiration before dispatch.

- `reserve(ReservationInput)` takes tenant, operation type (`ai` or `apollo`), durable operation key, execution owner, active tenant agreement ID, and a positive four-decimal conservative bound. Operation/execution keys use the funding key's lowercase ASCII grammar and 128-character limit. Hash opaque case-sensitive external IDs instead of lowercasing them. AI holds are `max(0.5000, bound)`; Apollo holds equal the supplied bound. Callers must round bounds upward before constructing this command.
- Hash version `reservation-v1` includes the original normalized bound as well as the held amount, tenant/type/key, execution, and agreement. Even two different bounds below the AI minimum conflict under the same operation key. A changed execution cannot take over an existing operation.
- First admission requires exactly one active agreement for the tenant and the supplied ID, using half-open effective windows. Missing, expired, cross-tenant, or overlapping active agreements fail closed. Agreement administration and persistent pricing snapshots are still pending; an agreement ID alone is not an applied model/action price snapshot.
- Under the tenant/wallet lock, admission sweeps expired unreserved funds, checks available wallet funds, locks eligible grants ordered by expiration (null last), then grant ID, and creates usage/reservation/allocations atomically. Grants and wallet retain held amounts in remaining/balance. Holding credits adds no debit. All decimal allocation projections are explicitly initialized; Espo metadata defaults are not SQL defaults.
- The receipt is `{usageId, reservationId, state, reservedCredits}`. Matching replay returns **current reservation state**, including `released`, without allocating again. It never authorizes a restarted execution. Denials throw `Conflict`; unknown tenants throw `NotFound`. Adapter-level structured denial responses remain pending. A failed admission rolls back its sweep too, but expired grants are always excluded from authorization.
- `release(tenantId, usageId, executionId)` only closes an admitted/held operation belonging to that execution, with zero accrued usage and **no persisted requests**, including soft-deleted requests. Persisted requests require terminal outcomes and `Settlements`; unresolved usage requires reconciliation first. Release validates allocation ownership and totals, returns unused funds to their original grants, and immediately posts expired amounts under `release:<allocationId>` expiration identities with usage/grant/allocation links. It records zero settled credits and retains allocation history. Repeat release has no additional effect.
- `Funding::expireLocked` is an internal collaborator: only call inside the shared `WalletLock` transaction and persist its returned wallet balance in that transaction. All future request/rate writers must use the same tenant serialization discipline.

Both-dialect tests verify ordering, minimums, exact projections, replay/conflicts, closed-operation replay, ownership guards, expired-grant exclusion, pre-request-only release, and rollback at usage/reservation/allocation/posting/wallet writes. Independent PHP processes verify competing holds, duplicate/conflicting admissions, and release/expiration/renewal races. These prove operation-hold concurrency, not integrated provider execution or measured debits.

## Internal AI request authorization contract

`Accounting/Requests::authorize(RequestInput)` uses the same tenant/wallet transaction and lock discipline. It accepts only an admitted unified AI operation and its original execution owner. It has no HTTP endpoint. Trusted adapters must resolve the applicable model rate and enforce the actual provider limits; model catalog activation, provider-specific input estimation, output/reasoning enforcement, cutover, and authentication remain integration work.

- `RequestInput` takes tenant ID, usage ID, execution ID, stable request key, immutable `AiRate`, conservative input-token bound, and a positive output-token limit **including reasoning**. Execution/request keys use the existing canonical lowercase ASCII grammar. Each intentionally distinct provider invocation needs its own key; retrying uncertain authorization keeps the original key.
- The bound assumes all input is uncached and prices input/output at the supplied rate, rounding upward to four decimals. The durable snapshot contains rate identity, provider/model, formula, all decimal pricing parameters, and both token limits. Hash `ai-request-v1` includes the command identities, canonical snapshot (normalized multiplier), and bound. Changed pricing or limits under the same key conflict even if the rounded bound is unchanged. The model rate is supplied by a trusted adapter, not validated against an activated catalog in this slice.
- A matching replay returns the persisted request's current outcome, metering state, and billing state with `dispatchAllowed: false`. Only the call that first commits returns `dispatchAllowed: true`. The receipt is `{requestId, authorizedCredits, outcome, meteringState, billingState, dispatchAllowed}`. A lost response or crash after commit requires reconciliation; retry does not grant another dispatch. This is accounting admission, not an exactly-once provider transport. Future adapters must disable hidden provider retries or authorize each separately.
- Before each new authorization, sweep expired unreserved funds and close all **unassigned** open allocations. Eligible lots record a release; expired lots receive allocation-linked `release:<allocationId>` expiration postings. Reallocate the request first, then any still-eligible admission buffer, in earliest-expiry/grant-ID order. The old allocation quantities and closure history remain intact. Funds are never externally available between release and reallocation: all writes share one transaction and tenant lock.
- Existing request-assigned lots are untouched, including expired grants, unknown/in-flight requests, and measured usage awaiting settlement. This slice conservatively retains their **full original bounds**, rather than reclaiming a measured request's excess early. A new request can use only eligible unassigned funds plus an atomic extension. Minimum admission buffer is preserved while eligible; expired buffer cannot authorize another request or force retention of expired minimum credits.
- Insufficient funds, wrong ownership/regime/type, closed reservations, conflicting replays, or inconsistent open allocation totals fail closed. Any failure rolls back reallocation, request insertion, expiration postings, grant projections, reservation and wallet updates together. A denied command also rolls back its expiration sweep; subsequent authorization still excludes those expired funds. The admitted agreement reference is retained rather than requiring a new agreement on each request.
- New requests start `inFlight` / `unknown` / `pending`, with no measured price or usage debit. `Reservations::release` rejects every operation with a persisted request. `Outcomes` records request-scoped pricing/waivers and `Settlements` posts completed operations as described below. Reconciliation scheduling and privileged reversal remain pending.

Both-dialect tests cover exact snapshots/bounds, canonical replay, ownership, buffer splitting, extensions, expiry before/after first authorization, closed-operation denial, and injected rollback at posting/request/allocation/grant/reservation/wallet writes. Separate processes verify one dispatch permission for duplicate commands, conflicting replay, competing same-operation and cross-operation extensions, and request/expiration/renewal races. Seeded terminal fields test replay reporting only, not an implemented metering or settlement service.

## Internal AI request outcome contract

`Accounting/Outcomes::record(OutcomeInput)` accepts authoritative normalized facts from a trusted execution/reconciliation adapter under the existing tenant/wallet lock. It has no public endpoint. The adapter must authenticate attribution and verify provider evidence; a nonempty evidence object is an audit requirement, not proof of authenticity.

- The command identifies tenant, usage, original execution owner, and an already-authorized request. Supported terminal outcomes are `success`, `cancelled`, `superseded`, and `infrastructureFailure`. Apollo/no-match and transcription use future adapters, not this AI inference command.
- Input includes cache, output includes reasoning. All three counts must be known nonnegative integers for measured pricing; any null preserves unknown metering and the supplied partial counts. Measured zero is distinct from unknown. Cache cannot exceed input. Evidence uses the shared canonical JSON-object convention (no floats), detached at construction; optional opaque provider request IDs retain case.
- Hash `ai-outcome-v1` covers identities, outcome, token counts, provider request ID, and canonical evidence. `CreditRequest.outcomeRecord` preserves the initial command/hash/server receipt time and, if applicable, one authoritative resolution. Matching replay of either returns the current request receipt, including after operation closure. Changed measured outcomes conflict; retries cannot change evidence, reprice, reclassify a success as a failure, or take over execution.
- An unknown terminal report may transition once to fully measured usage with the **same outcome**. A known provider request ID cannot be replaced or cleared. The authoritative resolution may correct partial counts; original partial evidence remains in `outcomeRecord`. `completedAt` remains the initial terminal report time; the resolution has its own recorded timestamp.
- Measured prices use only the persisted authorization snapshot, validating formula, identity and every v1 rate parameter. Unknown/changed formulas fail closed. Successful, cancelled, and superseded requests are billable when measured; infrastructure failures waive only that request. A failed request retains measured price/COGS evidence when available, but contributes zero to customer accrual. Later recovery of its missing metering preserves the waiver.
- Billable token-limit or monetary-bound violations throw `Conflict` and retain the existing state/hold, requiring operator reconciliation; the service never caps a charge or invents overdraft capacity. Waived infrastructure requests can retain over-bound provider usage as evidence. Automated over-bound incident handling remains integration work.
- Request facts and `CreditReservation.accruedCreditsExact` update atomically. Accrual is recomputed from all same-operation measured billable requests using exact decimal arithmetic, without per-request rounding. It excludes waived requests and leaves unknown billable requests explicitly pending. Accrued zero does **not** imply metering completeness; settlement must inspect every request.
- Outcomes retain full authorized allocations and unassigned holds, including waived bounds, until `Settlements` closes the operation. Outcomes write no debit and perform no release. Expiration cannot reclaim request-assigned funds; subsequent authorization cannot reuse accrued or assigned funds. The receipt is `{requestId, outcome, meteringState, billingState, pricedCreditsExact, waiverReason}`; `pricedCreditsExact` is a measured price, not a posted customer charge.
- `Reconciliation` implements policy-controlled unrecoverable-cancellation waivers as specified below. Production policy approval, operator authentication, scheduling, and reversals remain pending. No timeout alone converts unknown metering to zero or a waiver.

Both-dialect tests cover measured success/cancellation/supersession, isolated infrastructure waivers, cache normalization, unrounded accrual, unknown-to-measured reconciliation with preserved evidence, measured zero, immutable replay/ownership/provider IDs, closed operations, unsupported pricing and bound violations. Database faults prove request/accrual atomicity. Independent processes verify duplicate/conflicting outcomes, concurrent distinct-request accrual, and outcome/expiration/new-authorization races.

## Internal AI settlement contract

`Accounting/Settlements::settle(tenantId, usageId, executionId)` is a trusted internal terminal command under the shared tenant/wallet lock. The adapter must call it only when execution has finished issuing requests. It accepts no caller-supplied quantity, pricing, waiver, or parent-run billability flag. An authorization racing settlement either commits first and must be resolved before settlement, or is rejected after closure.

- Only the original execution owner of an admitted/held unified AI operation can settle. Pre-request cancellation uses `Reservations::release`; Apollo requires its future action-outcome service. Deleted/cross-tenant records, missing terminal evidence, inconsistent request/reservation/allocation projections, and pending/in-flight billable requests fail closed, preserving all holds. Rejected settlement does not change the operation into another state or block authoritative outcome completion.
- Every billable request must be measured with a nonnegative stored exact price within its authorized bound. Infrastructure-waived requests contribute zero, including unknown metering; their unused holds are released or expired. Policy-reconciled cancellation/supersession contributes zero only with `unrecoverable` metering, the explicit waiver reason, and persisted completed reconciliation evidence. Other unknown usage remains pending. Settlement checks the exact request sum against persisted reservation accrual and rounds half-up to four decimals once for the operation.
- Consumption stays within each request's original assigned lots. Process grants by expiration (null last), then grant ID, then allocation ID. Assign each request's exact price to its lots earliest-expiry-first. Convert those exact lot amounts into posted quantities using differences between half-up-rounded cumulative prefixes. These differences telescope to the single rounded operation total, never exceed a four-decimal lot bound, and allocate zero to waived requests and unassigned buffers. This is a deterministic distribution of rounding residuals, not independently rounded request billing. Expired funds belonging to a waived request cannot pay for another request.
- Every nonzero consumed allocation gets a negative `debit` posting; unused expired amounts get a negative `expiration` posting. Both use `settle:<allocationId>` identities scoped by tenant and transaction type, a hash of tenant/type/key/amount, and `ai-settlement-v1` evidence. Allocation links identify the request, original grant, and usage. No zero ledger entries are created; a measured-zero or all-waived operation still persists a settled-zero receipt and closes its allocations.
- Sweep unrelated expired free funds, post consumption/late expiration, partition all open allocations into consumed/released/expired amounts, update original grant projections, close reservation and usage, and update wallet balance/holds in one transaction. Previously closed reallocation history stays intact. Exact accrued pricing remains available after closure. Any write failure rolls back the whole mutation, including the expiration sweep.
- Receipt: `{usageId, reservationId, state: "settled", settledCredits, settledAt}`. Matching retries return the original amount and time without sweeping again or posting another debit. Ownership is checked before replay. There is no mutable settlement payload: corrections require a future linked reversal. Existing matching outcome replays remain readable after settlement; new outcome/resolution commands cannot rewrite a settled operation.

Both-dialect tests verify operation-only/sub-quantum/half-up rounding, zero and waived settlement, measured cancellation/supersession, original-lot consumption, multi-grant ordering, late expiration, no donation of expired waived funds, immutable receipts, ownership and closed-execution guards, unknown usage and corruption rejection. Injected faults cover posting, grant, allocation, reservation, usage, and wallet writes. Independent processes verify duplicate and cross-operation settlements, competing admissions, and settlement races with outcomes, request authorization, expiration, and renewal. Production reconciliation scheduling/configuration, privileged reversals, provider enforcement, and execution/API adapters remain pending.

## Internal cancellation reconciliation contract

`Accounting/Reconciliation::recordUnavailable(ReconciliationInput)` records a completed authoritative usage lookup that could not recover metering. The trusted adapter performs the lookup outside financial locks, authenticates the configured operator, and binds evidence to this tenant/provider request. A failed transport or unexecuted job is not authoritative unavailability evidence. This internal service exposes no HTTP route, scheduler, provider lookup, or operator authentication.

- `ReconciliationPolicy` requires a version, accountable operator identity, deadline seconds, retry seconds, and minimum attempt count. No default values are supplied. Deadline starts at the persisted initial terminal `completedAt`; first accepted attempt snapshots the policy immutably. Validation permits positive cadence, 2–1,000 minimum attempts, a feasible minimum retry span, and a deadline of at most one year. These are input bounds, not approved production settings. Policy/owner changes for an existing request fail closed.
- `ReconciliationInput` identifies tenant, usage, original execution, request, stable lookup attempt key, matching operator, UTC observation time, policy, and detached canonical evidence. Evidence requires nonempty string `source`, `reference`, and `reason` (each at most 512 bytes); reference must identify a distinct completed lookup and its retained evidence. Provider-request identity and partial/original metering remain on the request. Adapters must verify the evidence's authenticity and attribution; supplying an operator string alone is not authentication.
- Under the shared tenant/wallet lock, only admitted/held unified AI requests with terminal `cancelled` or `superseded` outcome, unknown metering, pending billing, and no price/waiver qualify. In-flight, successful, measured, infrastructure-waived, deleted, cross-tenant, and closed requests reject new attempts. Matching attempt replays remain available after resolution/settlement; changed payloads conflict.
- `CreditRequest.reconciliationRecord` retains the policy, deadline, each canonical input/hash/server receipt time, next eligible observation time, and final waiver time. Observations cannot precede terminal completion or the prior observation plus cadence, or lie after server time. Reusing a source/reference under another key cannot count twice. Retries do not add attempts. Receipts expose current metering/billing/waiver, attempt count, deadline, next attempt time, and operator; resolved receipts have no next attempt.
- A waiver requires both the configured minimum distinct spaced attempts and a fresh unavailable observation at or after the deadline. Passing the deadline alone never waives usage. Request state becomes `unrecoverable` / `waived` / `unrecoverable_cancellation` atomically with its audit history. Unknown price remains null; original terminal/partial evidence and successful-request accrual are preserved. Full assigned bounds remain held until operation settlement, which releases or expires unused original lots. Other unresolved requests still block settlement.
- Authoritative recovered measurements use `Outcomes::record` with the original outcome/provider identity. Recovery racing a final waiver is serialized: measured-first remains billable; waiver-first rejects new metering and cannot create a later charge. Existing matching outcomes remain replayable. Corrections after waiver/settlement require an explicit future operator adjustment path. Reconciliation history survives measured recovery.
- Both-dialect tests verify deadlines, cadence, evidence/replay/policy conflicts, ownership/state guards, unknown-versus-zero semantics, measured recovery, successful-charge retention, expired-original-lot release, and injected waiver/audit rollback. Independent processes cover duplicate/distinct attempts, final waiver versus measured recovery, and duplicate final waivers racing settlement/expiration/renewal. A production scheduler must discover eligible unknown cancellations, use authoritative lookups, honor returned timing, and invoke terminal settlement only when execution is finished; that adapter remains pending.

## Internal recovery discovery contract

`Accounting/ReconciliationDiscovery::scan(tenantId, limit = 100, afterRequestId = null)` supplies read-only candidates to a future trusted recovery adapter. It uses the existing `EntityManager` connection and UTC `Clock`, requires an existing non-deleted tenant, and rejects execution inside a PDO transaction. Tenant attribution and operator authentication belong to the caller; this internal method has no public route.

- Only terminal unknown/pending cancellation or supersession requests in admitted unified AI operations with held reservations qualify. Request, usage, reservation, execution, and tenant joins must agree; deleted, closed, measured, waived, future-completed, and incompletely evidenced requests are excluded.
- Each call inspects at most `limit` eligible rows (1–500), with one lookahead row to detect continuation. The `recoveryScan` index supports tenant/pending/unknown ID traversal. Retry times are read from JSON in PHP rather than dialect-specific SQL. Results contain `observedAt`, due `items`, `scannedCount`, and nullable `nextAfterRequestId`.
- **An empty `items` list does not mean the sweep is complete.** Continue while `nextAfterRequestId` is non-null; the cursor advances over inspected rows even when every row is waiting for its next retry. IDs are ordered by the database; callers must use the returned cursor unchanged and within the same tenant. Each later sweep restarts with a null cursor, revisiting newly due requests and newly inserted IDs behind the previous cursor. This is live keyset traversal, not a stable snapshot or an oldest-first queue.
- Candidate items carry tenant/usage/reservation/execution/request identities, provider/model/provider-request identity, original outcome/completion time, due time, saved policy/deadline, and attempt count. Before the first lookup, policy/deadline remain null and due time is completion time. The adapter must obtain an explicitly approved policy; discovery does not choose one. Existing policies, including operator ownership, are returned from their immutable saved snapshots.
- A saved retry is due at `nextAttemptAt <= observedAt`. Passing the deadline does not bypass cadence, add an attempt, waive a request, release a hold, or trigger settlement. A malformed persisted schedule raises `Conflict` for the page rather than silently substituting immediate work; operator investigation is required before retrying that page.
- Discovery is **not a work claim or authoritative provider evidence**. Overlapping scans may return the same request, and a candidate can resolve before processing. Provider lookups happen outside financial locks; the existing `Outcomes` and `Reconciliation` services revalidate state/ownership under lock and enforce replay/conflict rules. Missing provider request IDs stay null and require adapter-specific recovery, never fabricated unavailable evidence. Terminal settlement still requires proof execution has finished issuing requests.
- Both-dialect database tests cover retry boundaries, preserved policy/owner, deadline-without-waiver, empty-page continuation, another connection resolving a cursor row between pages, tenant/parent isolation, deleted/closed/ineligible records, malformed schedules, validation, and read-only behavior. Production scheduling, durable lookup attempt/dispatch identities, provider adapters, authenticated operators, and terminal settlement dispatch remain pending.

## Internal projection consistency audit

`Accounting/ConsistencyAudit::inspect(tenantId, findingLimit = 100)` diagnoses the implemented prepaid ledger and its projections. It is a trusted internal service; callers must establish tenant/operator authorization before exposing results. It owns a **read-only, repeatable-read transaction** on MySQL/PostgreSQL, samples one MVCC snapshot, and rejects nested transactions and missing/deleted tenants. Transaction settings apply only to that transaction. Concurrent financial mutations can commit without waiting on audit row locks.

- Returns `{tenantId, observedAt, consistent, findings, findingsTruncated}`. Each finding contains a stable diagnostic `code`, `entityType`, and the inspected tenant's record `id`. Foreign-tenant parent IDs and financial values are not emitted. A healthy empty tenant needs no wallet; financial rows without a wallet produce `wallet.missing`.
- Exact database DECIMAL sums compare wallet balance with ledger and grant balances, and wallet holds with grant, reservation, and open-allocation totals. Per-grant ledger/hold checks catch offsetting lot drift even when aggregate wallet totals agree. Reservation holds and state/execution ownership, usage settlement/debit/consumption totals, request authorization/allocation totals, and nonnegative projection ranges are checked independently.
- Allocation checks preserve historical closed/reallocated lots, require valid original parents, partition closed lots into consumed/released/expired amounts, and verify debit/expiration links, amounts, type, grant and usage attribution in both directions. Grant source postings must match their original quantity and ownership. Soft-deleted financial rows are reported rather than silently removed from sums.
- Diagnostics cover the **currently implemented** `grant`, `debit`, and `expiration` posting types. Extend these invariants with future reversal/Apollo settlement implementations. This is a projection audit, not independent provider-evidence validation, snapshot repricing, exact request-accrual recomputation, commercial-agreement validation, or proof that execution is terminal. Unknown usage, honored expired in-flight holds, and delayed expiration sweeps are valid accounting states.
- Findings are deterministic by check order and database ID order. `findingLimit` is 1–500; it bounds returned diagnostics, **not inspected history or query cost**. All check types run in the same snapshot; `findingsTruncated` means additional findings exist. A capped report with findings is never healthy. This is an offline tenant-wide diagnostic, not a paginated balance endpoint or admission decision; large-tenant scheduling/query budgeting remains operational integration work.
- No repairs, wallet creation, expiration, settlement, or posting occurs. A finding requires investigation and an approved service correction; never overwrite balances to make the report green. Query/transaction errors propagate after rollback rather than returning a healthy report.
- Both-dialect tests cover the valid funding/admission/reallocation/unknown-outcome/expiration/settlement/release lifecycle, exact sub-unit amounts, offsetting lot drift, corrupted projections and posting links, deleted/missing records, tenant isolation, capped findings, transaction cleanup, and a second connection committing after the first snapshot read. An attempted write inside the audit fails at the database boundary, and subsequent normal accounting transactions remain writable.

## Internal balance status

`Accounting/BalanceStatus::inspect(tenantId)` returns `tenantId`, `observedAt`, `walletExists`, and four-place decimal strings `balance`, `reservedCredits`, `pendingExpirationCredits`, and `availableCredits`. It owns a read-only repeatable-read transaction on MySQL/PostgreSQL and requires a trusted caller to authorize the tenant before invocation. Missing/deleted tenants are rejected; an existing tenant without a wallet returns explicit zero amounts and `walletExists: false`, without creating records.

`balance` is the posted wallet projection. `reservedCredits` includes all existing holds, including expired grants' protected holds. `pendingExpirationCredits` is the unreserved remainder of grants whose expiration is at or before `observedAt`. `availableCredits = balance - reservedCredits - pendingExpirationCredits`; delayed expiry jobs therefore never inflate reported spendable funds. Expiration processing moves pending expiration out of the posted balance without changing availability. Reads do not sweep expiration or release funds.

The service checks wallet/grant balance and hold totals, deleted projections, missing wallets with grants, and grant ranges, failing rather than presenting contradictory balances. Use `ConsistencyAudit` for broader ledger/request/allocation validation. A result describes one database snapshot, not a provider dispatch permission, configuration check, or promise of future admission. Agreement eligibility, tenant billing regime, authorization, and fresh financial locking remain responsibilities of the relevant adapters and mutation services. No public API is introduced.

## Verification

From `components/crm/source/`, with PHP available:

```sh
php phpunit.phar --do-not-cache-result --fail-on-deprecation tests/unit/Espo/Modules/FeatureCredits
```

This suite covers arithmetic, funding/reservation/request/outcome command validation, actual Espo ORM/schema conversion for both dialects, generic record-mutation guards, and opt-in real-database funding/reservation/request/outcome/settlement tests. Database tests require isolated, empty databases named `feature_credits_schema_test`, with user/password `credits_test`; concurrency cases also require `proc_open`:

```sh
FEATURE_CREDITS_MYSQL_DSN='mysql:host=127.0.0.1;dbname=feature_credits_schema_test' \
FEATURE_CREDITS_POSTGRESQL_DSN='pgsql:host=127.0.0.1;dbname=feature_credits_schema_test' \
php phpunit.phar --do-not-cache-result --fail-on-deprecation tests/unit/Espo/Modules/FeatureCredits
```

The tests refuse any other database name or a nonempty schema, create the eight tables using Espo-generated DDL (plus a minimal tenant fixture for funding), and drop their tables in `finally`. Without DSNs, database tests skip. See the release tracker for the isolated Docker verification command.
