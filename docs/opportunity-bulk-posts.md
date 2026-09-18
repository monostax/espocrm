# Opportunity bulk posts

Chatwoot submits a fixed selection and the board composer's Markdown to the CRM. The CRM persists the message once and one private result row per opportunity. A job processes at most 50 pending targets, then queues a continuation. Jobs share a serial group per tenant; other tenants can progress independently.

## Deployment

Run the normal CRM rebuild (`php command.php rebuild`) to create `opportunity_bulk_post` and `opportunity_bulk_post_target` and their indexes. Deploy the matching Chatwoot frontend. The existing CRM job runner/daemon must be running; no additional queue infrastructure is required.

## API

All routes require the authenticated CRM user. `accountId` is the numeric Chatwoot workspace ID; the server resolves an unambiguous integration and enforces tenant membership plus account ACL.

- `POST /OpportunityBulkPost/workspaces/:accountId`: `{ ids, post, idempotencyKey }`. Maximum 10,000 unique targets and 100 KB of Markdown. Returns an operation summary.
- `GET /OpportunityBulkPost/workspaces/:accountId`: latest 20 operations plus outstanding operations belonging to the caller.
- `GET /OpportunityBulkPost/workspaces/:accountId/:id`: counts and status.
- `GET /OpportunityBulkPost/workspaces/:accountId/:id/results?after=...`: at most 100 original opportunity IDs and outcome codes; `next` is an opaque cursor.
- `POST /OpportunityBulkPost/workspaces/:accountId/:id/retry`: resumes interrupted work and resets failed targets. Successful targets are immutable.

Statuses: `Queued`, `Running`, `Completed`, `Partial`, `Interrupted`. The last status indicates an exhausted/missing job and can be resumed with Retry.

## Access and delivery guarantees

- Submission validates the entire selection. Cross-tenant IDs reject the request even for instance administrators.
- Operations snapshot the author, CRM account, numeric Chatwoot account, platform, and tenant. Account rebinding cannot retarget queued posts.
- Every worker chunk uses a fresh application container as the original active internal user, including record and ORM hooks. Each target rechecks workspace binding, tenant, record/stream ACL, and Note creation ACL while holding the opportunity row lock.
- Status/results/retry are restricted to the original author and exact current workspace. Internal ledger scopes deny generic ACL access and expose no CRUD, mass actions, or export.
- A scoped idempotency key protects submission; reuse with a different payload returns a conflict. Per-target locks and a transaction containing Note creation, target outcome, and counters prevent duplicate posts after retries or lost responses. Deleting a successful Note does not reset its receipt.
- Normal Note hooks resolve mentions, update read states, and schedule AI mentions. Identity mapping caches are request-local; opportunity-specific access checks are not cached by the bulk implementation.
- Native per-record/personal realtime events are retained. Account-wide Chatwoot invalidations are coalesced per chunk. A dirty flag is committed with each successful Note and drained into the existing durable broadcast queue, including on worker recovery.

## Verification

The opt-in database integration suite uses the real Note pipeline, ACL and transactions. Point `data/config.php` at a **disposable test database**, with `useCache: false`, before running:

```sh
ESPO_BULK_POST_TEST=1 php phpunit.phar tests/integration/Espo/Modules/Chatwoot/OpportunityBulkPostTest.php
```

It creates the metadata schema and isolated fixtures, including regular users, two workspaces, mentions, and queued jobs. Do not run its job queue against external integrations. On PostgreSQL it also injects a result-write failure to verify that the Note and hook side effects roll back together.

For a larger run, set `ESPO_BULK_POST_SCALE=1000`. The first test verifies all target receipts, authors, read states, duplicate deliveries, and one account invalidation per chunk. The test invokes the worker directly; it does not measure queue scheduling latency or external notification delivery.
