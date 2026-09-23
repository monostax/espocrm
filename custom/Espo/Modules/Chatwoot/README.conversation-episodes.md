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
Already-acknowledged historical episodes are not automatically redelivered when
new report fields are deployed. Populate those fields using the CRM backfill:

```sh
# Run in the CRM application directory; preview first.
CRM_ACCOUNT_ID=69f887d1df9e9e80c MAX_PAGES=100 \
php run-module-script.php \
  custom/Espo/Modules/Chatwoot/Scripts/BackfillEpisodeActivityHistory.php \
  'Espo\Modules\Chatwoot\Scripts\BackfillEpisodeActivityHistory'

CRM_ACCOUNT_ID=69f887d1df9e9e80c MAX_PAGES=100 APPLY=true \
php run-module-script.php \
  custom/Espo/Modules/Chatwoot/Scripts/BackfillEpisodeActivityHistory.php \
  'Espo\Modules\Chatwoot\Scripts\BackfillEpisodeActivityHistory'
```

The command reads all source episodes, including acknowledged revisions, in
100-record pages. It fills only the three summary strings and two name arrays.
Each update locks the CRM row and requires an exact source-revision match;
missing, superseded or different-revision records are deferred to normal sync.
Transcript membership, coverage, boundaries, revision and source acknowledgements
are preserved. Repeated runs skip unchanged summaries. Output includes counts
and an `after` cursor; pass `AFTER=<last after>` to resume when `hasMore` is true.
The default budget is 10 pages. Missing source activity keys abort with a rollout
error rather than producing empty historical values.

The normal episode sync maintains the new columns on later revisions.
Activity-only changes queue affected episodes, without adding transcript
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
  Older text-only rows are not split to infer participants. Run the CRM activity
  history backfill after deployment to populate both arrays and display columns.

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

## Historical activity enrichment completed — 2026-09-23

Account 9's new history columns initially existed only on recently synchronized
episodes. The source still retained historical pills: episode 4369 (31 August)
returned `consultas` plus three assigned agents while its acknowledged CRM
revision still had null report fields.

The summary backfill processed 5,376 existing episodes: 797 in July, 3,284 in
August and 1,295 in September at verification time. It enriched 5,320 rows;
55 were already current and one live revision was deferred and subsequently
filled by the regular consumer. The final check found zero unprocessed summary
strings/name arrays on current CRM episodes for this account.

Native report/CSV verification used an existing non-global account administrator.
The August `chwRptEpList` CSV contained exactly 3,284 data rows, with 3,211
non-empty agent histories, 2,001 team histories and 51 tag histories. Legitimately
empty or unavailable activity evidence remains empty. The August participation
grids returned 5,421 agent participations and 2,079 team participations. The
temporary verification export was removed after its values were checked.

## Late-confirmation boundary correction — 2026-09-23

Conversation #5882 exposed a separate source-accounting issue: an outgoing
message created at 11:17:47 São Paulo time was confirmed at 11:18:57, after the
agent resolved the conversation at 11:17:58. The confirmation reopened the
conversation and created a second one-message episode, so that row showed the
new automatic assignment to Regiane rather than the earlier IA/Connect Center
history.

Chatwoot's lifecycle/reconstruction correction uses the transactional resolution
high-water mark to recognize replies already queued before closure. Their
interaction time becomes the original message time; the recorded confirmation
is retained in `additional_attributes.episode_confirmed_at`. Genuine post-close
follow-ups, inactivity policy and policy-cutover cases retain their timing rules.

The source repair was applied to 17 messages across 12 affected account-9
conversations, and all resulting revisions/tombstones were synchronized to CRM.
Some repaired conversations also recovered previously unsynchronized older
episodes during full-history reconstruction. No crossing confirmations or pending
CRM revisions remained at verification time.

For #5882 on 23 September, episode 5281 now contains 10 messages, starts at 07:22
and closes at 11:17 São Paulo time, with `IA - Grupo Mousa` and `Connect Center`.
Episode 5341 is superseded and hidden from current reports. An actual native CSV
export under a non-global account administrator was checked for the single
corrected row, its histories and message count; the temporary export was removed.
The preventive source-code guard requires deployment to Chatwoot web and workers.
