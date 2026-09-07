# Opportunity stream events

Incoming, non-private Chatwoot messages create `ChatwootMessageReceived` Notes
on explicitly linked Opportunities in the same tenant. The existing signed
delivery webhook writes them before campaign-specific handling; conversation
sync is an idempotent reconciliation path. No message body, attachment, sender
details, or arbitrary URL is copied into the Note.

Each Note is immutable and has its own `number` (the opportunity read cursor).
A unique SHA-256 key derived from the opportunity, CRM ChatwootAccount, and
message ID prevents duplicate webhook/sync entries, including replays after
soft deletion. Creation is serialized on the opportunity row.

Tasks, Meetings and Calls also create `ActivityOverdue` Notes when a pending
activity's deadline passes. The `PostOverdueOpportunityActivities` scheduled job
checks every minute, including when nobody is viewing the opportunity. The event
key includes the activity type/ID, opportunity, and deadline: repeated ticks do
not duplicate it, while a new overdue deadline after rescheduling can create a
new event. Completion and rescheduling are rechecked under an activity row lock.
An activity created after activation with an already-past deadline is also eligible.
Its event occurs at creation (not before the activity existed), while the original
deadline remains in the Note data and deduplication key. Activities both created
and due before activation remain excluded, even if an unrelated field is edited.

## Rollout

1. Deploy the CRM changes and run the normal CRM rebuild (`php rebuild.php`)
   before releasing the Chatwoot frontend. This installs the Note field/index,
    types, access controls, native stream views, latest-entry query filter, and
    overdue-activity scheduled job.
2. The first rebuild records `opportunityMessageEventsStartedAt`. Rebuilds do
   not reset it. Sync ignores older messages, so deployment does not make
    historical conversations unread. `opportunityOverdueEventsStartedAt` similarly
    prevents posting old overdue backlogs on deployment. The scheduler must be running.
3. Ensure the existing account delivery webhook subscribes to `message_created`
   and has a signing secret. Unsigned delivery requests do not create events.

The frontend uses `/Opportunity/{id}/stream`, restricted to `Post`,
`ChatwootMessageReceived` and `ActivityOverdue`. `/posts` intentionally remains Post-only for other
consumers. The existing `lastPostId` API parameter now also accepts an accessible
event Note; existing clients remain compatible.

The read-state service requires its event-access, select-builder and request-filter
dependencies. Do not make them nullable: Espo's injectable factory returns `null`
for unbound nullable dependencies, silently excluding event Notes and bypassing
navigation search/record-access filtering. The regression tests exercise the real
factory in addition to the stream SQL predicates.

## Presentation and unread behavior

- Consecutive events in the same account/conversation and local calendar day
  are grouped into a single pill. A five-minute gap, intervening Post, different
  conversation/account, or opening read boundary starts a new pill.
- Grouping is visual only: ten received messages are ten unread events, not one.
- An overdue activity is always its own pill and counts as one unread entry.
  It links to the CRM activity. Its label and deadline remain historical facts
  after the activity is completed or rescheduled.
- Date-only Task deadlines expire at the end of the **tenant's** local day
  (`Tenant.timeZone`, then instance `timeZone`, then UTC), not a viewer-dependent
  time. Datetime deadlines use the stored UTC `dateEnd`. DST is respected.
- The pill links to the first unread-at-opening message, or the latest message
  for a read group. Chatwoot's `messageId` query parameter locates that message.
- The oldest visible burst is completed across API page boundaries, so the
  50-entry polling window cannot cap a group's count at 50.
- Opening the opportunity only updates its CRM read state. It never calls a
  Chatwoot conversation read endpoint.
- Existing opportunity participation, closed-opportunity and mention rules are
  preserved; automated events do not create participants or mentions.
- Conversation read/inbox ACL is applied to stream lists, pinned Notes, direct
  Note reads, previews, navigation counts, and read cutoffs. Portal users cannot
  read these internal events. Overdue events use the source activity's read ACL
  in the same places. Core Note hooks cannot clear their internal flag, and these
  events do not fan out additional native CRM notifications per message/tick.

## Product verification

Use a linked opportunity and a user who can access both it and the conversation.
Mark the opportunity read, then receive ten messages in quick succession. Expect
one `10 mensagens recebidas na conversa #…` pill and an unread count of ten.
Open the opportunity, check the message link, then receive another message with
the same timestamp: its new Note sequence must still make it unread when the
opportunity stream is not visible. Replay the webhook and run sync; neither
should duplicate events. Verify a Post, another conversation, a five-minute gap,
and the unread boundary split pills. Also try a burst of more than fifty messages
and a user with no access to the source inbox.

Create a pending activity with a deadline a minute ahead. After the scheduler
tick, expect one `Atividade ficou atrasada: …` pill, one unread event, and the
activity as the latest preview. Further ticks must not duplicate the event.
Complete another activity before its deadline and verify no event is posted.
Reschedule an overdue activity into the future, let the new deadline pass, and
verify one new event. Check a date-only Task near local midnight too.
Create a new pending activity with a deadline before feature activation: it must
produce one event on the next scheduler tick. An old pre-activation overdue task
must still produce none. No message replay or read-cursor reset is needed when
fixing dependency injection: existing event Notes are counted automatically for
users who can access their sources.
