# Native Chatwoot Activities and Meetings

Activities contains Tasks and Calls. Meetings has a dedicated workspace backed by
the same Meeting records, Note streams and read state. No records are copied.

## Deployment

1. Deploy this CRM revision and run the normal Espo rebuild (`php command.php rebuild`).
   It creates the private `ActivityReadState` table and enables Call streams.
2. Deploy the matching Chatwoot revision. The native entry point is
   `/app/accounts/{accountId}/activities` and `/app/accounts/{accountId}/meetings`.
   Legacy Meeting detail and `type=Meeting` / `create=Meeting` links replace-navigate
   to Meetings, preserving supported query context and hashes.
3. Keep the CRM job worker running for `BroadcastActivityUpdate`. Existing Chatwoot
   account integration credentials authenticate the `/activity_events` notification.

## Contracts

- `/ActivityInbox` and `/ActivityInbox/counts` apply record ACL and the selected
  workspace before filtering, counting, and pagination. Task/Meeting use tenant
  teams. Calls with a tenant use that tenant, with team scoping for legacy calls.
- Record routes include both type and ID. IDs alone are not activity identities.
- ActivityInbox list/counts/metadata are bounded to Task/Call; MeetingInbox equivalents
  are bounded to Meeting before counting or pagination. Incompatible type filters
  fail validation. Sidebar destinations retain their own filters; rail unread totals
  ignore the selected leaf. Existing page and bulk-read limits remain 100.
- MeetingInbox uses `/MeetingInbox/:id` for read/update/delete, `/:id/stream`,
  `/:id/posts`, `/:id/readState`, `/bulkReadState`, and POST `/MeetingInbox` for create.
  Every request retains `accountId`. Meeting keys remain `Meeting:<id>` in client
  state and bulk read payloads. The ActivityInbox Meeting record-operation aliases
  remain available, including bulk-read compatibility and edit version checks.
- CRM tags use a tenant-scoped `CrmTag` catalog shared with Opportunities.
  Assignments are independent on each Task, Call, Meeting, and Opportunity.
  `tag=<id>` filters before pagination; navigation `tags` counts retain the
  global assignee scope. Page badges are hydrated in one query per record type.
- `group=read` orders unread activities before read activities in the database,
  before pagination, retaining the selected sort within each group. The Chatwoot
  list passes its active grouping; fresh workspaces default to Open, due-date groups,
  date ascending, list mode. Meetings has independent preferences with no migration.
- Activity streams share the historical `opportunityThreadRootId`,
  `opportunityMentionUserIds`, and Note capability fields with Opportunities.
  Activity read cursors live separately, keyed by user, parent type, parent ID,
  and thread key. `main` identifies the top-level stream.
- Automatic read writes provide a rendered Note ID and `expectedVersion`.
  An explicit null Note ID means an empty snapshot. Explicit mark-all-read actions
  can include threads; viewing the main stream never reads replies automatically.
- Create/edit use the native CRM record services, retaining validation, hooks,
  custom fields, reminders, participants, and field permissions.
- Realtime messages contain only an account invalidation. Browsers refetch with
  their own credentials. Reconnect/resume and periodic polling recover missed events.

## Product verification

Check Tasks, Calls and Meetings with separate users and workspaces: create/edit,
date-only deadlines, all-day meetings, reminders, ownership, completion/reopening,
mixed-type pagination, search, sidebar counts, posts, mentions, threads, reactions,
manual read/unread, deep links, and responsive list/detail navigation. Verify that
opening a thread does not consume unrelated replies, and that a failed send retains
the draft. Existing Opportunity streams use the same shared Vue discussion context.

## Coordinated release and rollback

- Deploy CRM support and rebuild before releasing dependent dashboard assets, in one
  coordinated release. Communicate that existing clients may need a refresh.
- Verify separate inbox rows/counts/metadata, canonical CRUD/discussion/read-state,
  legacy redirects, embedded group totals, and negative authorization smoke checks.
- Block or roll back on separation failures, broken canonical APIs, critical workflow
  failures or authorization regressions. Revert CRM and dashboard together; no data
  migration needs reversal. New preference keys may remain unused. New Meeting
  bookmarks are unavailable on a complete rollback; legacy Activity URLs remain.
- Known in-repository consumers: dashboard activity API/store and recurrence forms,
  embedded relationships/bulk actions, next-action editors, unread badges, live-query
  projections and edit interception. Meeting consumers now use the Meeting adapter.
  External combined-ActivityInbox consumers must adopt the split; they were not
  exhaustively inventoried.
