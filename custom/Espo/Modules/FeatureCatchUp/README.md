# Catch Up / Em dia

Em dia is the personal decision deck in Chatwoot (`/app/accounts/:accountId/catch-up`). This module serves its opportunity and task cards, their per-user snoozes, and the signed requests for AI-written card copy. Chatwoot entry points and card behavior: `components/chatwoot/source/app/javascript/dashboard/routes/dashboard/catchup/README.md`.

## Opt-in, credits and limits

**Default: disabled for every account.** Administrators enable it in **Settings → Conversation Workflow → Em dia**. The settings are `account.settings.catch_up_enabled` and `catch_up_daily_generation_limit`, exposed as `features.catch_up` without adding a bit to the full 63-bit feature mask. CRM reads the opt-in from the Chatwoot Platform Account API before every feed, snapshot, review, snooze and summary request. A missing flag or failed lookup never enables it.

Once enabled, the AI writes each card's copy as the user reaches it and prepares the next two, so enabling Em dia does spend credits:

- Each written card is charged to the tenant's AI credits (`unified-prepaid-v1`). Opportunity cards are charged to the Opportunity, task cards to the Task, conversation cards to the ChatwootConversation.
- Account limit: **300 cards per UTC day** by default, configurable from 0 to 2000 and shared by all users. With 0, cards render template copy and spend nothing.
- Installation ceiling: `CATCH_UP_INSTALLATION_DAILY_LIMIT` on Hatchet workers, default **20000** per UTC day.
- Hatchet retries count against the limits. Cache hits, polling and feeds do not.
- Each model call receives at most **32 KB of evidence/facts** and requests at most **2,048 output tokens**.

There is no automatic enablement. The `CatchUpSnooze` entity is created by `php rebuild.php`.

## Behavior

- **Attention** opportunity cards are the user's open opportunities whose selected next action is late or missing (`RecordActivityBuckets`). **Unread** cards use the personal opportunity attention filter.
- **Task cards** are the user's pending tasks due today or late, through the Tasks workspace query (same ACL and team tenancy). A task that is the next step of the user's own open opportunity is left to the opportunity card.
- Snapshots carry the facts the card copy is written from: stage, amount, contact, the hydrated next action, the reason, the last customer message and today's date in the reader's zone. Facts only change at day granularity, so summary polls are not superseded every minute.
- Reading a card does not acknowledge it. Committing a card reviews the displayed snapshot first; thread replies keep their own bounded read markers and newer messages stay unread. A changed card answers 409.
- Task completion and rescheduling go through the normal Task record service and require the displayed `status`, `dateEnd` and `dateEndDate`. A task changed since it was shown answers 409.
- **Adiar** stores a per-user `CatchUpSnooze` (at most 30 days ahead) that the feeds exclude until it expires. The record itself is not changed.
- Long unread histories are reviewed in windows of 30 entries, with earlier context.

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

The CRM endpoints are defined exclusively in this module:

- `GET /CatchUp?accountId=<Chatwoot account>&view=unread|attention&cursor=<id>&timeZone=<IANA zone>&limit=<1-20>`; add `source=task` for task cards (offset cursor)
- `GET /CatchUp/:opportunityId?timeZone=`
- `POST /CatchUp/:opportunityId/review` with `{snapshot}`
- `POST /CatchUp/:opportunityId/summary` with `{locale, timeZone}` to start/reuse, or `{locale, timeZone, requestId}` to poll
- `GET /CatchUpTask/:taskId?accountId=&timeZone=` and `POST /CatchUpTask/:taskId/summary`
- `POST /CatchUpTask/:taskId/complete` and `/reschedule` with `{accountId, expectedStatus, expectedDateEnd, expectedDateEndDate}` plus `{dateEndDate}` or `{dateEnd}` (ISO time)
- `POST /CatchUpSnooze` with `{type: Opportunity|Task, id, until}`

Summary responses carry `card` (`emoji`, `headline`, `whyNow`, `insight`, `keyPoints`, `nextTask`) next to the plain-text `summary`.

Review snapshots use per-user/per-opportunity CRM cache slots with 24-hour validity. CRM cache clearing invalidates old review tokens; refreshing a card obtains a new one. Summary results live in the backend cache.

## Automated checks

From the CRM source directory:

```sh
php phpunit.phar custom/Espo/Modules/FeatureCatchUp/Tests
php phpunit.phar tests/unit/Espo/Modules/FeatureCredits/SourceReferenceTest.php
php phpunit.phar tests/unit/Espo/Modules/Chatwoot/Services/OpportunityStreamQueriesTest.php
```

From `components/backend/source`:

```sh
node --import tsx --experimental-test-module-mocks --test 'source/modules/hatchet/workflows/$catchUpSummary.test.ts'
```

Manual checks are listed in the Chatwoot README.
