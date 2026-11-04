# Catch Up / Em dia (v2: deal-first)

Em dia is a personal decision deck in Chatwoot (`/app/accounts/:accountId/catch-up`). Every card comes from an open deal: the AI reads the deal's whole conversation and decides whether a person has something to do. Most deals get no card. Plan and decisions: `gtm/05-catchup-decisions-plan.md` in the mono repo. Chatwoot side: `components/chatwoot/source/app/javascript/dashboard/routes/dashboard/catchup/README.md`.

## Opt-in, credits and limits

**Default: disabled for every account.** Administrators enable it in **Settings → Conversation Workflow → Em dia** (`account.settings.catch_up_enabled`, `catch_up_daily_generation_limit`). The CRM reads the opt-in from the Chatwoot Platform Account API on every request; a missing flag or failed lookup never enables it.

- Each analysis is charged to the tenant's AI credits on the Opportunity (`unified-prepaid-v1`). A stored verdict is reused for free.
- Account limit: 300 analyses per UTC day by default (0–2000), shared by all users. Installation ceiling: `CATCH_UP_INSTALLATION_DAILY_LIMIT` on the Hatchet workers (default 20000).
- At most 50 evidence entries (40 messages, 10 deal notes) and 32 KB reach the model.

## Who sees a deal

- A deal owned by a person goes to that person.
- A deal owned by an AI agent (a user behind an `isAI` membership), or by nobody, goes to the **last staff member who wrote in its conversation from the app**.
- Tenant admins (`TenantRoleAuth::hasTenantAdminRole`) see every open deal in the tenant. The Opportunity ACL applies to everyone.

The last staff writer and who spoke last live in `CatchUpDealState` (one row per opportunity, id = opportunity id). The `ChatwootMessage` afterSave hook `RefreshCatchUpDealState` keeps it current; the rebuild action `BackfillDealState` fills open deals that have no row. A failing refresh is logged and never breaks message sync.

## How the deck loads

1. `GET /CatchUp` returns candidates: open deals with a linked conversation in the reader's deck, not snoozed, ordered without scanning messages (customer waiting first, then quiet past 2 days, then moving, then quiet 30+ and 60+ days). Deals already judged `none` at their current revision are dropped, so a page can be short.
2. `GET /CatchUp/:id` builds the snapshot: both directions of every linked conversation from `ChatwootMessage` (each labeled customer, ai, staff, outside or internal) plus the deal's Posts, the facts, the revision and the review token.
3. `POST /CatchUp/:id/summary` returns the stored verdict for the same revision, locale and evidence, or starts or polls the backend analysis and stores the verdict when it completes. An answer that isn't a valid verdict is stored as `none`.

A **revision** (`Revision::of`) hashes the stage, amount, owner, follow-up status, next step, the deal state, the newest Post and the quiet-time step (1, 2, 4, 7, 14, 30, 60 days). A verdict is reused until the revision changes. Verdicts live in `CatchUpVerdict`, shared by readers who see the same evidence.

## Endpoints

- `GET /CatchUp?accountId=&timeZone=&cursor=&limit=`: `{items, cursor}`
- `GET /CatchUpCount?accountId=`: card verdicts from the last 24 hours that nobody acted on (rail badge)
- `GET /CatchUp/:id?accountId=&timeZone=`: snapshot
- `POST /CatchUp/:id/summary` with `{accountId, locale, timeZone, requestId?}`: `{status, requestId, verdict, reason, card, cached, snapshot}`
- `POST /CatchUp/:id/review` with `{snapshot}`: 409 when the deal changed since the card was shown; otherwise marks its verdict acted on
- `POST /CatchUp/:id/close` with `{accountId, status: Won|Lost}`: moves the deal to its funnel's 100% or 0% stage through the record service
- `POST /CatchUp/:id/resolvedElsewhere` with `{accountId, text}`: writes a Post "Resolvido por fora: …" that the next analysis reads
- `POST /CatchUpSnooze` with `{accountId, type: Opportunity, id, until}`: per-user Adiar, at most 30 days

Next steps use the existing `POST /Opportunity/:id/nextAction`.

## Deployment

Deploy the backend and Hatchet workers, then the CRM, then Chatwoot. Run `php rebuild.php`: it creates `CatchUpVerdict` and `CatchUpDealState` and backfills the deal state. Transport to the backend is unchanged (HMAC-signed `POST /internal/catch-up/summary`, `CATCH_UP_BACKEND_URL` / `CATCH_UP_BACKEND_SECRET`).

## Automated checks

From the CRM source directory, inside the repo's nix shell (`nix develop path:../../nix#crm`):

```sh
php phpunit.phar custom/Espo/Modules/FeatureCatchUp/Tests
```

From `components/backend/source`:

```sh
node --import tsx --experimental-test-module-mocks --test 'source/modules/hatchet/workflows/$catchUpSummary.test.ts'
```
