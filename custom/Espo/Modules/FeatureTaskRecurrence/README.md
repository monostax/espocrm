# FeatureTaskRecurrence

- Status: Implemented; acceptance verification in progress.
- Entry point: `Services/Recurrence.php` — scheduling preview, binding, scoped lifecycle and bounded generation.
- Persistence: native Tasks plus internal series segments and durable occurrence reservations.
- Scheduling: fixed calendar RRULE/RDATE sets or actual-completion calendar intervals.
- Interfaces: `feature-task-recurrence` Espo views and Chatwoot Activities.
- Deployment: normal CRM rebuild seeds `ProcessTaskRecurrences`; the existing cron/job worker runs it every minute.
- Operational reference: [`task-recurrence.md`](../../../../../docs/task-recurrence.md).

## Module slices

- `Controllers/` — authorized native recurrence API.
- `Services/` — transactional coordinator and native Task integration.
- `Tools/` — validation/expansion, civil dates, metadata-aware templates, reusable-file copies and editor capabilities.
- `Hooks/` — ordinary native saves/deletions and durable advancement receipts.
- `Jobs/` — bounded processing under the stored execution identity.
- `Rebuild/` — idempotent scheduled-job registration.
- `Resources/` — persistence, routes, layouts and English/Brazilian Portuguese translations.

## Contracts

- Date-only values remain dates; timed values retain series timezone and are stored in UTC.
- Calendar visibility is 30 local days plus the next sparse occurrence; completion mode has one known head.
- Ledger identities never change when a Task is rescheduled, skipped, deleted or reopened.
- Series changes require an optimistic version and explicit scope; direct Task writes default to this occurrence.
- Generated records pass actor-bound native validation, field savers, reminders and hooks.
- Owned uploads and custom relationships are excluded from template copying. File fields explicitly marked `recurrenceReusable` must reference readable same-workspace Documents and receive fresh attachment IDs.
- Density: 256 slots/31 days, 50 generated records/pass; coordinated edits: 2000 eligible Tasks.

## Verification

- [x] Focused PHP unit/database lifecycle checks and Automation scheduling regression.
- [x] Native reminder authorship/cleanup, inactive identity rejection, reusable-file ownership, deadline field ACL, workspace-scoped read/assignment and overlapping generation checks.
- [x] ActivityInbox workspace/field-ACL and batched recurrence badge integration.
- [x] Both frontend builds and focused serialization/date-type migration checks.
- [ ] Connected-browser, live native integrations and concurrent split/edit/delete acceptance.
