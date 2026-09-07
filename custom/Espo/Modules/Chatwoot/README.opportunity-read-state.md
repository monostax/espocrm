# Opportunity post read state

## Deployment

Run the normal CRM rebuild (`php rebuild.php` from the CRM source directory)
before deploying/reloading the updated Chatwoot frontend. The metadata rebuild
creates the read-state table, Note mention column and unread query index. There
is intentionally no schema creation or silent fallback in API requests.

`BackfillOpportunityReadStates` then normalizes existing mentions and initializes
assignees, stream subscribers, Post authors and mentioned users at the deployment
cutoff. All historical posts start as read, including for existing personal
rows; viewers are not enrolled as participants just to initialize their state.
It processes history in pages and never moves a newer personal cutoff backwards.
The cutoff is persisted before processing so retries cannot mark later posts as
read. Only posts after that fixed boundary contribute new unread counts.
The `opportunityReadStatesBackfilledAt` config flag makes this a one-time action;
a partially completed run can safely be retried. Do not delete the flag to reset
users' read state. Subsequent deployments do not reset personal read/unread choices.

The rebuild CLI deliberately has no current user. The backfill resolves its
user-dependent helpers lazily, after the core system-user action, and supplies
an explicit system user and ACL only to those helpers. It does not change the
application's current user or the authorization context used by API requests.

## Semantics

- A row does **not** imply participation. Only assignment, posting, a verified
  mention or an existing stream subscription sets `isParticipant`. Viewing and
  manual read/unread actions never enroll a participant.
- A null cutoff is not unread. Won/Lost opportunities only demand attention for
  an unread mention and cannot be manually marked unread.
- Automatic reads identify the last rendered Post and the observed state
  version. Note numbers distinguish posts created in the same UTC second and
  prevent a newly arrived, unrendered post from being cleared.
- Writes are serialized under an Opportunity row lock without updating the
  Opportunity itself. Stale versioned reads cannot undo a newer manual unread.
- Every endpoint checks record existence, stream/read ACL and tenant membership.
  State is always scoped to the authenticated CRM user, not a supplied user ID.
- The shared editor sends the Chatwoot account context. User/team mention IDs
  are mapped through that tenant's integration memberships to CRM users with
  stream access. Ambiguous integrations are not guessed. Native CRM mentions
  remain supported; unrelated Note data is never treated as a mention.
- CRM changes invalidate Chatwoot cards, stream and sidebar counts over ActionCable.
  A 60-second visible-page poll is only a recovery fallback.
  Only a successfully loaded, rendered Board tab can automatically mark posts
  read. Merely refreshing unchanged posts does not clear a manual unread from
  another device. Manual unread returns to the Opportunity list.
- The Opportunity list accepts the `chatwootUnread` and `chatwootMentions`
  primary filters. Filtering happens before pagination and respects read/stream
  access and tenant membership. Unread uses the same personal cutoff and attention
  rules as the cards; Mentions includes verified mentions of the current CRM user,
  including already-read mentions. Rebuild CRM metadata before deploying the
  corresponding Chatwoot sidebar links. No additional backfill is required.

## Real-time delivery

- `PublishOpportunityUpdate` records a `BroadcastOpportunityUpdate` job when an
  Opportunity, its Post/message/overdue Note, or a personal read-state row is
  saved or removed. The job is committed with the change, so rolled-back writes
  cannot send notifications. The `q0` queue runs as soon as possible and retries
  failed delivery up to three times. Keep the CRM daemon running.
- The job publishes to Espo's existing ACL-checked record/stream websocket topics
  and calls `POST /api/v1/accounts/:account_id/opportunity_events` for Chatwoot
  accounts belonging to the opportunity's tenant. It uses the existing
  `ChatwootPlatform.backendUrl` and `ChatwootAccount.apiKey` (account admin).
- Chatwoot authenticates the account/admin, then queues an `opportunity.updated`
  ActionCable broadcast on its agent-only account stream. The payload is only
  `account_id`: no opportunity IDs, Note content or personal counts are broadcast.
  Each browser refetches through its own CRM session/ACL and current sidebar scope.
- Event bursts are debounced; events received during refresh trigger another pass.
  Reconnect and returning to a visible tab also refresh. The active stream retains
  the rendered-post cutoff/version safeguards for automatic marking as read.
- Deploy the Chatwoot endpoint, frontend and critical Sidekiq worker before
  enabling the CRM hooks. Run the normal CRM rebuild to discover the hooks and
  restart long-running CRM workers/websocket processes. No new secrets, routes
  exposed without authentication, or database tables are required for this bridge.

## Verification

Before production rollout, exercise two users in a staging tenant: posting,
mentions, switching tabs during loading, manual unread, and access revocation.
Also verify the normal rebuild on the deployed database engine. The isolated
development checks exercise the service with real ORM SQL/mapper and disposable
SQLite storage, but do not replace a live MySQL/PostgreSQL concurrency test.

For the real-time bridge, use two browser sessions in one tenant: send a linked
conversation message, post/mention in CRM, and mark read/unread on the other
device. Confirm `opportunity.updated` arrives on `/cable` and the list, stream and
sidebar update without waiting for the fallback poll. Also check reconnect,
burst traffic, an inaccessible opportunity and a different tenant/account; the
event must never expose record data or bypass CRM ACL.
