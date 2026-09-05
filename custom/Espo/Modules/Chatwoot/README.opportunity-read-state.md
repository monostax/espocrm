# Opportunity post read state

## Deployment

Run the normal CRM rebuild (`php rebuild.php` from the CRM source directory)
before deploying/reloading the updated Chatwoot frontend. The metadata rebuild
creates the read-state table, Note mention column and unread query index. There
is intentionally no schema creation or silent fallback in API requests.

`BackfillOpportunityReadStates` then normalizes existing mentions and initializes
assignees, stream subscribers, Post authors and mentioned users at the deployment
cutoff. It processes history in pages and preserves existing personal cutoffs.
The `opportunityReadStatesBackfilledAt` config flag makes this a one-time action;
a partially completed run can safely be retried. Do not delete the flag to reset
users' read state.

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
- Chatwoot refreshes posts/read state every 15 seconds while the page is visible.
  Only a successfully loaded, rendered Board tab can automatically mark posts
  read. Merely refreshing unchanged posts does not clear a manual unread from
  another device. Manual unread returns to the Opportunity list.

## Verification

Before production rollout, exercise two users in a staging tenant: posting,
mentions, switching tabs during loading, manual unread, and access revocation.
Also verify the normal rebuild on the deployed database engine. The isolated
development checks exercise the service with real ORM SQL/mapper and disposable
SQLite storage, but do not replace a live MySQL/PostgreSQL concurrency test.
