# Episode accounting

`ChatwootConversationEpisode` mirrors the source episode, policy snapshot,
boundary timestamps, durations, revision and transcript coverage. The source of
boundaries is Chatwoot; the CRM does not derive them from conversation statuses.

Enable `ChatwootAccount.episodeSyncEnabled` after the Chatwoot episode API is
deployed. `SyncConversationEpisodesFromChatwoot` runs every minute. Each account
has a MariaDB advisory lock so overlapping workers cannot reorder episode
membership updates. Unacknowledged source revisions are retried automatically.

The importer fetches all revision-qualified message pages, validates the unique
message count, then commits the episode and message membership together. It
acknowledges only that exact source revision. A failure after commit is safe to
retry. Reconstruction tombstones remove obsolete report rows and membership;
restored episodes retain their stable CRM IDs. Account identity includes the
platform, and access checks follow account teams and inbox membership.

## Native reports

| Stable ID | Report | Grain |
|---|---|---|
| `chwRptEpStart` | Atendimentos Iniciados / Mês e Caixa | One count per episode in its start month |
| `chwRptEpActive` | Atendimentos com Interação / Mês e Caixa | Distinct episodes with qualifying communication in the month |
| `chwRptEpClose` | Atendimentos Encerrados / Mês e Motivo | Episode closure month and reason |
| `chwRptEpList` | Atendimentos / Base para Exportação | One row per episode |
| `chwRptEpAgent` | Atendimentos por Agente / Participações | Distinct episodes per account and recorded agent/user name |
| `chwRptEpTeam` | Atendimentos por Equipe / Participações | Distinct episodes per account and recorded team name |

Reports use the system reporting timezone (`America/Sao_Paulo` in the main
installation), runtime account/inbox/date filters, native CSV/XLSX export and
row-level ACL. Policy-change closures are separate from resolutions/inactivity.
The active-episode report sums episode-months; that sum is not unique episodes
over a multi-month interval.

### Lifecycle activity columns

`chwRptEpList` also exports `lifecycleTags`, `lifecycleAssignees` and
`lifecycleTeams`: distinct names, comma-separated, in first-observed order within
the episode. The source replays activity pills before the start to establish
carried-in state, then accumulates values during `[started_at, closed_at)` (or
through the latest activity for active episodes). Removed tags and reassigned
users/teams remain in that episode's summary. Later episodes inherit only the
state still in effect, rather than every prior assignee/tag in the conversation.

These are historical Chatwoot names, not the CRM `teams` access-control relation
or the conversation's current assignee. Assignment actors are not assignees.
Public transcript coverage does not imply activity-history coverage: missing
pills cannot be recovered. Legacy English/Portuguese pills are supported; their
identical agent/team assignment wording is resolved using account names and
unambiguous historical pills. Unknown or ambiguous targets are not guessed.
New pills carry typed change data and the original change timestamp, so delayed
activity jobs and future name changes do not require text interpretation.

Deploy CRM and run its usual rebuild to create the text fields and refresh the
seeded report. Deploy the Chatwoot changes to both web and worker processes.
Then requeue existing source episodes once (dry run first):

```sh
ACCOUNT_ID=9 bundle exec rake conversation_episodes:resync_activity_history
ACCOUNT_ID=9 APPLY=true bundle exec rake conversation_episodes:resync_activity_history
```

The normal episode sync fills the new columns as pending revisions drain.
Activity-only changes also queue affected episodes, without adding transcript
messages or changing episode boundaries/counts. During rolling deployment, an
API response without the new keys leaves existing CRM summaries untouched.

### Drill-downs and participation reports

`layouts/ChatwootConversationEpisode/listSmall.json` is the layout used by native
report drill-down modals. It exposes tags, assigned agents/users and assigned
teams for `chwRptEpStart`, `chwRptEpClose` and the two participation grids.
These text fields are also available in drill-down CSV/XLSX exports. The monthly
start/close summary groupings retain their original meanings.

`chwRptEpAgent` and `chwRptEpTeam` use internal grid implementations, with the
normal report UI and exports. Their queries always apply strict episode ACL
(including account/inbox restrictions), and account labels are independently
permission-checked. Runtime filters include episode start/close date, account,
inbox, boundary policy and close reason.

- **Period:** `startedAt` selects an episode cohort; it does not select the date
  of an individual assignment. Active episodes can participate too.
- **Grain:** once per episode, account and recorded participant name. Repeated
  assignment to the same name does not increase its count. Names are distinct
  within each account; equal names in different accounts stay separate.
- **Totals:** sum of participation, not unique episodes across all participants.
  A handoff episode can count under several names. Clicking an account total
  lists its unique episodes, so the list can contain fewer rows than the sum.
- **Historical identity:** grouping uses recorded names, not stable person/team
  IDs. Same-name people within an account share a group; renames can form
  separate groups. Missing/ambiguous activity history is omitted, not attributed
  to an invented "unassigned" participant.
- **Storage:** `lifecycleAssigneeNames` and `lifecycleTeamNames` preserve the source
  arrays separately from display text. Names containing commas remain intact.
  Older text-only rows are not split to infer participants. Requeue source
  episodes using `conversation_episodes:resync_activity_history` after deploying
  this importer to populate both arrays and display columns.

The aggregation reads only distinct permitted episode IDs, account IDs and the
relevant name array. Drill-down membership is evaluated before pagination, then
records are fetched again under strict ACL with the requested fields/order/page.

The existing conversation creation, public movement and reporting-event metrics
have their original meanings. For the August 2026 Mousa pilot, independently
reconstructed source figures were 3,284 episodes started, 3,330 with interaction,
and 1,940 distinct conversations with qualifying interaction. Those are different
from 1,541 newly created conversations and 1,982 with any public incoming/outgoing
message, which includes CSAT and failed sends.

Typesafe classification is a subsequent phase. It should consume closed episodes
with complete transcripts and persist classification against the source episode
revision, model/rule version and input hash, preserving human corrections.

## Completed Mousa pilot — 2026-09-17

Tracking is enabled on Chatwoot account **9**, using the **lifecycle** policy.
Recurring CRM synchronization is enabled on `ChatwootAccount/69f887d1df9e9e80c`.
The August pilot covers source inboxes **68** and **69** and the UTC interval
`[2026-08-01 03:00:00, 2026-09-01 03:00:00)`, corresponding to São Paulo's month.

| August metric | Chatwoot reconstruction | Native CRM report |
|---|---:|---:|
| Episodes started | 3,284 | 3,284 |
| Episodes with qualifying interaction | 3,330 | 3,330 |
| Episodes closed | 3,218 | 3,218 |

The completed reconciliation snapshot included **4,739 episodes and 77,889
messages**, covering complete surrounding histories and live interactions as
well as August. Every source episode was present in CRM at the same revision.
Per-episode message counts, interaction counts and ordered message-ID hashes all
matched; there were no missing/extra episode IDs or outstanding revisions in
that snapshot. These all-history totals naturally increase with live traffic.

Production verification used a non-global tenant administrator:

- All three summary reports returned the expected August totals.
- Native CSV and XLSX generation succeeded for each summary.
- The native episode-list CSV contained exactly **3,284 data rows**.
- A real user belonging to another tenant could not read the Mousa report rows.
- Temporary export attachments created for verification were removed.

During catch-up, MariaDB returned record-change error 1020 while the regular
conversation importer was also writing messages. Episode synchronization now locks existing message
rows and retries the complete local transaction for record-change, deadlock and
lock-timeout errors, up to three attempts. A rollback acceptance check verified
that a failed attempt leaves no partial writes. Source acknowledgement still
occurs only after the successful commit.

The redundant per-episode source refresh was removed: transcript pages already
check their revision, and acknowledgement conditionally verifies the same
revision after commit. Normal deployment's cached image digest was also aligned
with the fixed image so subsequent deployment applies retain the implementation.
