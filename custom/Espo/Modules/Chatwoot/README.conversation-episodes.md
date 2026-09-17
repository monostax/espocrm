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

Reports use the system reporting timezone (`America/Sao_Paulo` in the main
installation), runtime account/inbox/date filters, native CSV/XLSX export and
row-level ACL. Policy-change closures are separate from resolutions/inactivity.
The active-episode report sums episode-months; that sum is not unique episodes
over a multi-month interval.

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
