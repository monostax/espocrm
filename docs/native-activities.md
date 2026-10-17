# Native Chatwoot Activities, Calls and Meetings

Activities contains Tasks. Calls and Meetings have dedicated workspaces backed by
the existing records, Note streams and read state. No records are copied.

## Deployment

1. Deploy this CRM revision and run the normal Espo rebuild (`php command.php rebuild`).
   Calls reuses the existing `ActivityReadState` table and Call streams; no data migration.
2. Deploy the matching Chatwoot revision. The native entry point is
   `/app/accounts/{accountId}/activities`, `/calls`, and `/meetings` under the same account prefix.
   Legacy typed details win over recognized creation intent, which wins over type filters.
   Call and Meeting links replace-navigate to their canonical workspace, preserving applicable
   filters, tabs, thread context and hashes. Canonical workspaces never redirect to another
   workspace because of query controls; unsupported creation intents are removed.
3. Keep the CRM job worker running for `BroadcastActivityUpdate`. Existing Chatwoot
   account integration credentials authenticate the `/activity_events` notification.

## Contracts

- `/ActivityInbox` and `/ActivityInbox/counts` apply record ACL and the selected
  workspace before filtering, counting, and pagination. Task/Meeting use tenant
  teams. Calls with a tenant use that tenant, with team scoping for legacy calls.
- Record routes include both type and ID. IDs alone are not activity identities.
- ActivityInbox list/counts/metadata are bounded to Task; CallInbox to Call; MeetingInbox
  to Meeting before counting or pagination. Incompatible type filters
  fail validation. Sidebar destinations retain their own filters; rail unread totals
  ignore the selected leaf. Existing page and bulk-read limits remain 100.
- CallInbox and MeetingInbox use `/{Call|Meeting}Inbox/:id` for read/update/delete,
  `/:id/stream`, `/:id/posts`, `/:id/readState`, `/bulkReadState`, and POST to the base path
  for create. Both expose `/metadata`, `/counts`, and authorized `/options` lookup.
  Every request retains `accountId`. Keys remain `Call:<id>` and `Meeting:<id>` in client
  state and bulk read payloads. The ActivityInbox Call and Meeting record-operation aliases
  remain available, including bulk-read compatibility and edit version checks.
- CRM tags use a tenant-scoped `CrmTag` catalog shared with Opportunities.
  Assignments are independent on each Task, Call, Meeting, and Opportunity.
  `tag=<id>` filters before pagination; navigation `tags` counts retain the
  global assignee scope. Page badges are hydrated in one query per record type.
- `group=read` orders unread activities before read activities in the database,
  before pagination, retaining the selected sort within each group. The Chatwoot
  list passes its active grouping; fresh workspaces default to Open, due-date groups,
  date ascending, list mode. Calls and Meetings have independent preferences with no copying.
  Obsolete type grouping normalizes to due-date grouping; single-type controls are hidden.
- Eligible embedded panels split Activities (Task/Appointment), Calls (Call), and Meetings
  (Meeting), including independent counts, searches, linking, creation and drawers.
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
  migration needs reversal. Roll back to the pre-Calls revision retaining Meetings.
  Calls preference keys may remain unused; `/calls` bookmarks will be unavailable until
  the feature returns. Legacy Activity URLs remain.
- Known in-repository consumers: dashboard activity API/store and recurrence forms,
  embedded relationships/bulk actions, next-action editors, unread badges, live-query
  projections and edit interception. Call and Meeting consumers use their dedicated adapters.
  Business-summary calculations retain their existing entity reads; full-page links are canonical.
  Communicate the Task-only ActivityInbox list/count/metadata contract and refresh requirement
  to known API consumers before release. External consumers were not exhaustively inventoried.
