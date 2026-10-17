# Native Chatwoot Tasks, Calls, Meetings and embedded Appointments

Tasks, Calls and Meetings have dedicated workspaces backed by the existing records,
Note streams and read state. Appointments are managed in embedded relationship groups
and drawers. No records are copied. Tasks retains the `activities` client store,
ActivityInbox contracts, recurrence, and existing account/viewer-scoped preference keys.

## Deployment

1. Deploy this CRM revision and run the normal Espo rebuild (`php command.php rebuild`).
   Appointment reuses the existing `ActivityReadState` table and native record services.
   Rebuild installs Appointment live-record/version metadata; no business-data conversion.
2. Deploy the matching Chatwoot revision. The native entry point is
   `/app/accounts/{accountId}/tasks`, `/calls`, and `/meetings` under the same account prefix.
   Task detail is `/tasks/:taskId`. `/activities`, typed Activity details and `/crm/Activities`
   remain compatibility redirects. There is no standalone Appointment dashboard route.
   Legacy typed details win over recognized creation intent, which wins over type filters.
   Call and Meeting links replace-navigate to their canonical workspace, preserving applicable
   filters, tabs, thread context and hashes. Canonical workspaces never redirect to another
   workspace because of query controls; unsupported creation intents are removed.
3. Keep the CRM job worker running for `BroadcastActivityUpdate`. Existing Chatwoot
   account integration credentials authenticate the `/activity_events` notification.

## Contracts

- `/ActivityInbox` and `/ActivityInbox/counts` apply record ACL and the selected
   workspace before filtering, counting, and pagination. Task/Meeting/Appointment use tenant
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
- AppointmentInbox exposes list/search, `/metadata`, `/options`, create, `/:id`
  read/update/delete, `/:id/stream`, `/:id/posts`, `/:id/readState`, and `/bulkReadState`.
  All require `accountId`; identity is `Appointment:<id>`. There are no Appointment counts,
  rail, recurrence, or preference endpoints. ActivityInbox never accepts Appointment records.
- Appointment `customerId` filtering is exact and checks customer ACL/tenancy before totals.
  Submitted customer, location, professionals, teams and conversation IDs require field
  permission, strict target ACL and account ownership. Native services retain required-field
  validation and derive assigned users from location/professionals. Task-style assignment,
  parent fields and assignee filters are rejected; Appointment tags are unsupported.
- CRM tags use a tenant-scoped `CrmTag` catalog shared with Opportunities.
  Assignments are independent on each Task, Call, Meeting, and Opportunity.
  `tag=<id>` filters before pagination; navigation `tags` counts retain the
  global assignee scope. Page badges are hydrated in one query per record type.
- `group=read` orders unread activities before read activities in the database,
  before pagination, retaining the selected sort within each group. The Chatwoot
  list passes its active grouping; fresh workspaces default to Open, due-date groups,
   date ascending, list mode. Task preferences keep their original keys and values;
   Calls and Meetings have independent preferences with no copying.
  Obsolete type grouping normalizes to due-date grouping; single-type controls are hidden.
- Eligible embedded panels split Tasks, Appointments, Calls and Meetings, including
  independent counts, searches, linking, creation and drawers. `tab=activities` selects Tasks.
  Appointment discovery combines native Contact customer associations, native conversation
  links and authorized explicit relationships, deduplicating by identity. Customer associations
  cannot be unlinked. After a confirmed create followed by failed linking, retry only linking.
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

Check Tasks, Calls, Meetings and embedded Appointments with separate users and workspaces: create/edit,
date-only deadlines, all-day meetings, reminders, ownership, completion/reopening,
mixed-type pagination, search, sidebar counts, posts, mentions, threads, reactions,
manual read/unread, deep links, and responsive list/detail navigation. Verify that
opening a thread does not consume unrelated replies, and that a failed send retains
the draft. Existing Opportunity streams use the same shared Vue discussion context.
For Appointments, also verify customer reassignment and conversation link/unlink refresh,
required customer/location/teams, read-only derived assignment, date-only edits, link-only
retries, native/API conflicts, Note attachment authorization, and no standalone navigation.

## Coordinated release and rollback

- Deploy CRM support and rebuild before releasing dependent dashboard assets, in one
  coordinated release. Communicate that existing clients may need a refresh.
- Verify separate inbox rows/counts/metadata, canonical CRUD/discussion/read-state,
  legacy redirects, embedded group totals, and negative authorization smoke checks.
- Block or roll back on separation failures, broken canonical APIs, critical workflow
   failures or authorization regressions, lost Task preferences or broken `/tasks` routes.
   Revert CRM and dashboard together to pre-Tasks/Appointment revisions, retaining Calls
   and Meetings. Restore metadata/live registrations through the normal rebuild process.
   Preserve created Appointments, Notes, read states and relationships; do not delete data
   as rollback cleanup. `/tasks` bookmarks will be unavailable during complete rollback;
   legacy Activity URLs remain the recovery path. Refresh stale dashboard clients.
- Known in-repository consumers: dashboard activity API/store and recurrence forms,
  embedded relationships/bulk actions, next-action editors, unread badges, live-query
  projections and edit interception. Call and Meeting consumers use their dedicated adapters.
  Business-summary calculations retain their existing entity reads; full-page links are canonical.
  Communicate the Task-only ActivityInbox list/count/metadata contract and refresh requirement
  to known API consumers before release. External consumers were not exhaustively inventoried.
