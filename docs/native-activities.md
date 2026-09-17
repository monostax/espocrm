# Native Chatwoot Activities

The Chatwoot Activities workspace combines Task, Call and Meeting records. Each
record uses its own CRM Note stream. No activity records are copied to Chatwoot.

## Deployment

1. Deploy this CRM revision and run the normal Espo rebuild (`php command.php rebuild`).
   It creates the private `ActivityReadState` table and enables Call streams.
2. Deploy the matching Chatwoot revision. The native entry point is
   `/app/accounts/{accountId}/activities`; the former `/crm/Activities` entry redirects.
3. Keep the CRM job worker running for `BroadcastActivityUpdate`. Existing Chatwoot
   account integration credentials authenticate the `/activity_events` notification.

## Contracts

- `/ActivityInbox` and `/ActivityInbox/counts` apply record ACL and the selected
  workspace before filtering, counting, and pagination. Task/Meeting use tenant
  teams. Calls with a tenant use that tenant, with team scoping for legacy calls.
- Record routes include both type and ID. IDs alone are not activity identities.
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
