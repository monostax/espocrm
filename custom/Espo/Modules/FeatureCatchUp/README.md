# Catch Up / Em dia

## Current status: disabled in the UI

Catch Up was hidden on **2026-09-26** because the feature is not ready for release.

- The **Em dia / Catch Up** workspace tab and Conversation Workflow settings panel have been removed from Chatwoot.
- The former entry point, `/app/accounts/:accountId/catch-up`, redirects to **Conversations** in the same account.
- Setting `catch_up_enabled` to `true` does not restore the UI. Re-enabling the feature requires restoring its UI entry points after it is ready.
- The CRM and Chatwoot APIs, account configuration and summary workflow remain implemented with their existing opt-in and authorization checks.

Chatwoot entry-point documentation: `components/chatwoot/source/app/javascript/dashboard/routes/dashboard/catchup/README.md`.

## Retained opt-in configuration and cost controls

**Default: disabled for every existing and new account.** Deploying or enabling Catch Up does not schedule LLM work. Each new summary requires a user to click **Summarize with AI**.

Before the UI was disabled, administrators could enable it in **Settings → Conversation Workflow → Catch Up / Em dia**, alongside Statusless mode. That panel is now hidden. The retained configuration uses these daily generation limits:

- Default: **20 model attempts per account per UTC day**, shared by all users, conversation summaries and opportunity summaries.
- Allowed account range: **0–50**. Set **0** to use the feed and existing drafts without generating new summaries.
- Installation-wide ceiling: **100 model attempts per UTC day**, configurable on Hatchet workers with `CATCH_UP_INSTALLATION_DAILY_LIMIT` (0 stops new model calls).
- Hatchet retries consume the same quota. Cache hits, polling, reading the feed and using existing drafts do not consume summary quota.
- Each model call receives at most **32 KB of evidence/facts**, plus a short fixed instruction, and requests at most **2,048 output tokens**.
- Limits reset at midnight UTC. These are generation/token bounds, not a fixed dollar guarantee; provider prices determine the invoice.

The settings are `account.settings.catch_up_enabled` and `catch_up_daily_generation_limit`. The opt-in is exposed as `features.catch_up` without adding a bit to the already-full 63-bit feature mask. There is no database migration or automatic enablement. Existing accounts without the setting are disabled.

Chatwoot gates all Catch Up endpoints. CRM reads the same authoritative opt-in from the Chatwoot Platform Account API before feed, review and summary access. Missing flags and failed lookups never enable the feature. The backend also requires a signed enabled policy; budgets are charged atomically immediately before each model attempt. Turning it off blocks new requests and result access; work already submitted may finish within those bounds.

## Behavior

- Unread updates use the existing personal conversation/opportunity attention filters.
- Conversation drafts are included even after the conversation has been read. Drafts use Chatwoot's existing store, websocket events and send/edit/discard APIs.
- The attention view includes pending conversation drafts and the current user's open opportunities with an overdue or missing next action.
- Cards load in pages. Conversation pages follow last activity; opportunity pages use a stable ID cursor.
- Reading a preview does not acknowledge it. **Reviewed** advances only to the displayed source snapshot. Thread replies use their own bounded read markers. Newer messages remain unread.
- **Skip for now** only moves through the current session; refresh includes skipped items again.
- Summaries are requested explicitly through the backend's **`catch-up-summary` Hatchet workflow**, bounded to the current evidence window, and cached by authorized content, language, model and prompt version. Existing read/tenant/inbox/field permissions are checked on every request and poll.
- Long unread histories are reviewed in windows of 30 entries, with earlier context. Source IDs link back to messages or the opportunity/thread.

## Deployment

Deploy the backend API and Hatchet workers, Chatwoot and CRM, including the updated deployment manifests. Run CRM's normal `php rebuild.php` to discover this module and its routes. No database migration is required.

Both summary types use the backend's shared `providers.google`, `CHATWOOT_AGENT_MODEL_ID` and `CHATWOOT_AGENT_PROVIDER_OPTIONS`, exactly like Monostax's Chatwoot agent. Model credentials stay in the backend. Rails and this CRM module authorize snapshots and make short, timestamped HMAC-signed requests to the backend; they do not invoke an LLM.

Deployment supplies `CATCH_UP_BACKEND_URL` to Rails/CRM and a matching `CATCH_UP_BACKEND_SECRET` to Rails/CRM/backend API, derived from the existing namespace configuration. Local processes need the same configuration. The former `CATCH_UP_SUMMARY_MODEL` / `catchUpSummaryModel` overrides are no longer used.

## Summary lifecycle

1. The source endpoint authorizes the caller and builds the current evidence snapshot.
2. The backend validates the signed request and derives a cache key including source instance, tenant, user, record, evidence/unread range, language, shared model settings and prompt version.
3. Concurrent starts share one pending attempt. The backend calls `catchUpSummary.runNoWait`; the browser receives `pending` plus `requestId` and polls the source endpoint.
4. Hatchet's `generate-summary` task applies bounded execution and one retry, using the shared provider and token-accounting helper. It has no operational tools.
5. A separate `persist-summary` task saves the result. Persistence retries reuse the generation output. Attempt-checked writes prevent delayed workers/failure handlers from replacing newer or completed results.
6. Every poll rechecks source permissions and current evidence. Changed input returns `superseded`; the UI refreshes the card rather than attaching an old summary to newer messages.

Completed summaries live in backend Redis for 24 hours. Pending reservations expire after 15 minutes; expired polling never starts another job implicitly. A failed job can be retried explicitly. Model usage is recorded in Hatchet output and backend logs using the existing `ModelUsage` format. The workflow is independent of the reply agent's conversation cancellation buckets and follow-up logic.

AI/manual reply drafts continue to use the existing draft workflows and actions. Navigating away stops browser polling; a running summary can finish and be reused later.

The new CRM endpoints are defined exclusively in this module:

- `GET /CatchUp?accountId=<Chatwoot account>&view=unread|attention&cursor=<id>&timeZone=<IANA zone>`
- `GET /CatchUp/:opportunityId`
- `POST /CatchUp/:opportunityId/review` with `{snapshot}`
- `POST /CatchUp/:opportunityId/summary` with `{locale}` to start/reuse, or `{locale, requestId}` to poll

Review snapshots use per-user/per-opportunity CRM cache slots with 24-hour validity. CRM cache clearing invalidates old review tokens; refreshing a card obtains a new one. Summary results live in the backend cache.

## Manual verification

For the current disabled state, confirm that **Em dia** is absent from the workspace rail, the Catch Up settings panel is absent from Conversation Workflow, and `/app/accounts/:accountId/catch-up` redirects to Conversations in the same account, including for accounts with `catch_up_enabled: true`.

Automated checks from the CRM source directory:

```sh
php phpunit.phar custom/Espo/Modules/FeatureCatchUp/Tests/FeedTest.php
php phpunit.phar tests/unit/Espo/Modules/Chatwoot/Services/OpportunityStreamQueriesTest.php
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G --level=3 custom/Espo/Modules/FeatureCatchUp/Services custom/Espo/Modules/FeatureCatchUp/Controllers
```

From `components/backend/source`:

```sh
node --import tsx --experimental-test-module-mocks --test 'source/modules/hatchet/workflows/$catchUpSummary.test.ts'
```

### Feature checklist for a future re-enable

The following checks apply after restoring the UI entry points in development:

1. Open **Em dia** from the workspace rail. Confirm unread conversations and opportunities match the user's normal access.
2. Review an existing manual/AI draft. Send, discard, and edit it; edit should open the original conversation composer. Template drafts retain their template editor.
3. Open the same draft from another browser. Sending/discarding there must update its status and prevent a second send.
4. While a card is open, receive a new message. Review the old card, refresh, and confirm the new message remains unread.
5. In an opportunity with unread thread replies, review one window and confirm later replies remain unread.
6. Generate a summary twice without changing the source; the second request should reuse cached output. Changing content or language must invalidate the cache.
7. Check mobile width and keyboard left/right navigation. **Skip for now** must not mark the item read.
