# Catch Up / Em dia (v2: deal-first)

Em dia is a personal decision deck in Chatwoot (`/app/accounts/:accountId/catch-up`). Every card comes from an open deal: the AI reads the deal's whole conversation and decides whether a person has something to do. Most deals get no card. Plan and decisions: `gtm/05-catchup-decisions-plan.md` in the mono repo. Chatwoot side: `components/chatwoot/source/app/javascript/dashboard/routes/dashboard/catchup/README.md`.

## Opt-in, credits and limits

**Default: disabled for every account.** Administrators enable it in **Settings → Conversation Workflow → Em dia** (`account.settings.catch_up_enabled`, `catch_up_daily_generation_limit`). The CRM reads the opt-in from the Chatwoot Platform Account API on every request; a missing flag or failed lookup never enables it.

- Each analysis is charged to the tenant's AI credits on the Opportunity (`unified-prepaid-v1`). A stored verdict is reused for free.
- Account limit: 300 analyses per UTC day by default (0–2000), shared by all users. Installation ceiling: `CATCH_UP_INSTALLATION_DAILY_LIMIT` on the Hatchet workers (default 20000).
- At most 50 evidence entries (40 messages, 10 deal notes) and 32 KB reach the model. Images and documents in those messages go along as media: the newest 8, 20 MB and 20,000 input tokens in total, chosen by the backend. Voice notes go as their transcripts (as audio only when there is none); video and other kinds stay text markers.

## Loops and tabs (gtm/05 §16, decided 2026-11-10)

Loops are the core. Each subject's record (`Records`) keeps every move one side owes the other in `promises`: explicit ("te mando o contrato até 18h", "vou ver com meu sócio e te retorno") or implied (a question nobody answered, a proposal with no reply). Each loop has a side (`ours`, `theirs`), a short action text, a `dueAt` or none, and is open, kept or dropped. The model keeps the loops and answers only `loops`, `decided`, `next_step_missing` (deals), `new_deal` (conversations) or `none` (prompt version 12). `Loops` compares the loops' times with the clock and makes the card:

| Verdict | When | Tab |
| --- | --- | --- |
| `customer_waiting` | one of ours with no time: due now | Esperando a gente |
| `promise_due` | one of ours past its time (the earliest) | Esperando a gente |
| `follow_up_due` | one of theirs past its time, or with none after `Feed::FOLLOW_UP_AFTER_DAYS` quiet days; never while the AI follow-up is armed on the deal | Esperando o cliente |
| `decided`, `new_deal`, `next_step_missing` | the model's; a deal is stalled only with no loop open and no pending next step | Esperando a gente |
| `scheduled` | loops open, none due: no card, the revision moves when one comes due | Agenda |

The card shows the loop it was made from (`card.promise`: side, text, dueAt), and the loop's text stands in for copy the model left out. `scheduled` and `none` keep a subject out of the deck until its revision moves; the lists return the stored card verdict of the others (`Verdicts::judged`) and a `sort` key ([rank, key], `Feed::sortKey`) so Chatwoot can merge deals and conversations into one queue per tab.

A record keeps at most 30 loops: every open one, then the latest closed ones (at most 10). Before 2026-11-10 the parser kept the first 30, so a long deal lost its newest open loops.

## Who sees a deal

- A deal owned by a person goes to that person.
- A deal owned by an AI agent (a user behind an `isAI` membership), or by nobody, goes to the **whole team** (`Ownership::WHOLE_TEAM`, decided 2026-11-10). Until then it went to the last staff member who wrote in its conversation from the app; setting the constant to false brings that rule back, and the card still names the last staff writer as the owner.
- Tenant admins (the Tenant Admin role, `TenantRoleAuth::hasTenantAdminRole`) see every open deal in the tenant.
- **Chatwoot decides who may open a conversation, for everyone, tenant admins included (`ChatwootAccess`).** A deal is listed, counted on the badge or analysed only when the reader may open at least one of its conversations in Chatwoot, and only those conversations are read, scoped and quoted. The CRM's `ChatwootConversation` ACL mirrors inbox membership only, and it gives a Chatwoot global admin every inbox; Chatwoot lets a global admin open only the inboxes they belong to (on 2026-11-10 several did not belong to all of them), adds access through teams and participation, and narrows it for custom roles. So after the CRM ACL, `ChatwootAccess` asks Chatwoot (`POST /platform/api/v1/accounts/:id/conversation_access`, with the platform token and the reader's Chatwoot user), in batches of up to 200 and once per request. It fails closed: when Chatwoot can't answer, nothing from those conversations is shown. The Opportunity ACL applies on top.

The last staff writer and who spoke last live in `CatchUpDealState` (one row per opportunity, id = opportunity id). The `ChatwootMessage` afterSave hook `RefreshCatchUpDealState` keeps it current; the rebuild action `BackfillDealState` fills open deals that have no row. A failing refresh is logged and never breaks message sync.

## How the deck loads

1. `GET /CatchUp` returns candidates: open deals with a linked conversation in the reader's deck, not snoozed, quiet for at least 30 minutes (`Feed::SETTLE_MINUTES`; a conversation still moving is analysed once it settles), ordered without scanning messages: customers waiting on us for up to 7 days first, longest wait first (`Feed::WAITING_FIRST_DAYS`, decided 2026-11-10), then older waits up to 30 days, most recent first, then quiet past 2 days, then moving, then quiet 30+ days. Atendimento (`ServiceFeed`) uses the same wait order. A deal quiet for over 45 days (`Feed::DORMANT_DAYS`) has had its last check and is left out until a message moves it. Deals already judged `none` at their current revision are dropped, so a page can be short.
2. `GET /CatchUp/:id` builds the snapshot: both directions of every linked conversation, read from Chatwoot's messages API by `Transcript` (each labeled customer, ai, staff, outside or internal), plus the deal's Posts, the facts, the revision and the review token. Times are in the reader's time zone, with the offset. The CRM's `ChatwootMessage` copy is not used for evidence: its sync stores only each conversation's last message at every poll, so it misses messages. A conversation is read only when the reader passes the `ChatwootMessage` ACL for it (`Transcript::readable`), and a conversation Chatwoot can't return fails the analysis instead of being skipped.
   - **Replies and reactions (prompt 10).** An entry that quotes another message (WhatsApp reply, `content_attributes.in_reply_to`, double-encoded rows included) carries `replyTo`. When the quoted message is older than the entries sent, `replyText` quotes it (300 characters); up to 3 quoted messages per read are fetched from Chatwoot with the page that ends right after them, and a failed lookup only drops the quote. Emoji reactions travel as `reactions` (emoji, customer/ai/staff, name).
   - **Earlier deals (prompt 10).** The facts carry `dealOpenedAt` and `earlierDeals`: up to 3 older deals that share one of the deal's readable conversations, as one line (name, status, amount, opened and closed dates), and only deals the reader may read. On 2026-11-10, 461 of Grupo Mousa's 2,472 open deals shared their conversation with an earlier deal, nearly all won or lost about a month before; the model decides what carries over.
**Running record (`Records`, step 3 of gtm/05 §12).** Each deal and Atendimento conversation keeps a record: `agreed` (what's agreed now) and `promises` (ours or theirs, text, `dueAt`, the message that opened it, and the message that closed it if any). It lives in `CatchUpRecord`, one row per subject and `sourceScope` (the set of conversations the reader may open), so a reader never gets a record built from a chat they can't see. It is updated on demand, in the same analysis as the verdict:
- the first analysis builds it from the newest 40 messages;
- later ones send the record plus the messages after `coveredAt` and 3 before (`Records::trim`).

`dueTs`, the earliest open promise's due time, is code comparing the model's times. It feeds the revision, so a deal is judged again once a promise passes due. The verdict is filed under the revision the next read will compute with the new record (`revisions.due` / `revisions.notDue`), and review accepts either. `promise_due` (our open promise past due) is a card in both lanes. The card shows only the due promise (`card.promise`), never the record.

**Atendimento (`ServiceFeed`, step 2 of gtm/05 §12).** Conversations of the deck's Chatwoot account with no open deal, one card per conversation, under `/CatchUpService` (list, read, summary, review, resolved, label). The access rules:
- Candidates pass the `ChatwootConversation` strict access filter (inbox membership), then Chatwoot's own rules (`ChatwootAccess`).
- Every action checks the conversation again and requires it to belong to the deck's account.
- A label can only be set on a participant of the card the reader was shown.

Candidates are active in the last 30 days and quiet for 30 minutes. They are not the WhatsApp control chat, not snoozed, and not labeled personal, automated or team. `catch_up_party` mirrors labels so that filter needs no Chatwoot read; it is refreshed on every read and write. A conversation assigned to a person in Chatwoot goes to that person; one with no person assigned goes to the whole team (`Ownership::WHOLE_TEAM`, 2026-11-10; before, to the last staff writer). The card names the assignee, otherwise the last staff writer (`CatchUpConversationState`, kept by a `ChatwootMessage` hook and filled by `BackfillConversationState` for the last 30 days). Tenant admins see all, always within what Chatwoot lets them open.

Verdicts live in `CatchUpVerdict` with `conversationId` instead of `opportunityId`. `Summary` keeps only `customer_waiting`, `follow_up_due`, `new_deal` and `none` for a conversation, and makes it `none` when the conversation's own contact or group is personal, automated or team.

**Who is who (`Parties`, step 1 of gtm/05 §12).** A contact's or group's class (customer, partner, team, personal, automated) lives on the Chatwoot contact as the "Em dia" list field (custom attribute `em_dia_tipo`). People see and correct it in the contact panel. `Transcript` returns the groups among a deal's conversations and the people writing in them as `participants`, keyed by account (a group is one contact per Chatwoot account). A message from someone labeled Equipe counts as staff. Unlabeled groups and group members are classified in the same model call as the verdict, never in a background sweep, and `Summary` writes each class into the contact's field only while it is still empty, creating the field definition the first time. The verdict is stored under the evidence scope the next read will compute with those labels (`Feed::evidenceScope` hashes each entry with who it counts as sent by), so a later correction gets the deal judged again. A deal's own contact in a direct chat is never classified.

3. `POST /CatchUp/:id/summary` returns the stored verdict for the same revision, locale and evidence, or starts or polls the backend analysis, turns its loops into the verdict (`Loops`) and stores it when it completes. An answer that isn't a valid verdict is stored as `none`.

A **revision** (`Revision::of`) hashes the prompt and evidence versions, the stage, amount, owner, follow-up status, next step, the deal state, the newest Post and the quiet-time step (1, 2, 4, 7, 14, 30 days; 30 is the last check). A verdict is reused until the revision changes. Verdicts live in `CatchUpVerdict`, shared by readers who see the same evidence.

## Endpoints

- `GET /CatchUp?accountId=&timeZone=&cursor=&limit=`: `{items, cursor}`
- `GET /CatchUpCount?accountId=`: card verdicts from the last 24 hours that nobody acted on (rail badge); `scheduled` doesn't count
- `GET /CatchUpAgenda?accountId=&timeZone=`: `{items, days}`, what comes due in the next 7 days (`Agenda::DAYS`), earliest first, at most 60: loops whose time hasn't come (`loop`, from the record built from the conversations the reader may open), the deal's pending next step (`step`: Task, Call or Meeting) and the AI follow-up armed on a deal (`ai`). Stored data only, never an analysis. Same reach as the deck: Ownership, the CRM ACL and Chatwoot access; a deal shows only with a conversation the reader may open. Deal loops carry `nextAction` and `canSchedule` (no pending next step and the reader may edit the deal) for Chatwoot's **Agendar**.
- `GET /CatchUp/:id?accountId=&timeZone=`: snapshot
- `POST /CatchUp/:id/summary` with `{accountId, locale, timeZone, requestId?}`: `{status, requestId, verdict, reason, card, cached, snapshot}`
- `POST /CatchUp/:id/review` with `{snapshot}`: 409 when the deal changed since the card was shown; otherwise marks its verdict acted on
- `POST /CatchUp/:id/close` with `{accountId, status: Won|Lost}`: moves the deal to its funnel's 100% or 0% stage through the record service
- `POST /CatchUp/:id/resolvedElsewhere` with `{accountId, text}`: writes a Post "Resolvido por fora: …" that the next analysis reads
- `POST /CatchUpSnooze` with `{accountId, type: Opportunity, id, until}`: per-user Adiar, at most 30 days
- `GET /CatchUpRecord?accountId=&conversationId=` (Chatwoot display id): `{records: [{subject, agreed, promises, coveredAt}]}` for the conversation sidebar (`ConversationRecord`). Each open deal of the conversation the reader may read (newest 3), with its record under the reader's source scope; without one, the conversation's own Atendimento record. Only agreed items (with up to 2 earlier values in `was`) and open promises are returned. It reads stored records only and never analyses. It resolves the workspace with `Workspace::account`, which skips the Chatwoot opt-in call so opening a chat costs no request to Chatwoot; Chatwoot only calls it on accounts with Em dia on, and the conversation must pass both the `ChatwootConversation` and the `ChatwootMessage` ACL.

- `GET /CatchUpProposedDeals?accountId=&timeZone=`: `{items: [{conversationId, contactName, inboxName, name, amount, why}], funnels: [{id, name, isDefault}]}` for "Criar todas": the unresolved `new_deal` cards at each conversation's current revision, in the reader's deck and Chatwoot access, conversations still without an open deal, at most 50, a mirrored group once. Stored verdicts only, never an analysis.
- `POST /CatchUpProposedDeals` with `{accountId, funnelId?, items: [{conversationId, name?, amount?}]}`: creates each item that is still one of the reader's proposals through the Opportunity record service as the reader, in the chosen funnel (default: the default funnel, else the only one; `funnelRequired` / `noFunnel` otherwise) and its first open stage, assigned to the reader, with the reader's default team when it is the workspace's (else its base team), linked to the conversation; then resolves its card. Returns `{created, skipped, failed}`; one deal that can't be saved doesn't stop the others.

Next steps use the existing `POST /Opportunity/:id/nextAction`.

## Analysing without the deck

`Services/Prefetch` runs the first cards of a reader's deck (deals first, then conversations, at most N, skipping what is judged) through the same feeds, snapshots and `Summary`, and polls queued analyses until they complete or a deadline passes. With no wait it only queues them and returns the pending ones (`waiting`); `poll()` asks once more about exactly those, so a job never sleeps in a worker slot. The caller sets the reader (the self-service first read uses `ActorScope` with the workspace owner); `Classes/DeckRequest` carries the deck's parameters. Verdicts land under the deck's own keys, so the deck opens on them.

## Deployment

Deploy the backend and Hatchet workers, then Chatwoot, then the CRM. The CRM's access checks call Chatwoot's `conversation_access` endpoint and fail closed, so a CRM deployed before it shows an empty deck. Run `php rebuild.php`: it creates `CatchUpVerdict` and `CatchUpDealState` and backfills the deal state. Transport to the backend is unchanged (HMAC-signed `POST /internal/catch-up/summary`, `CATCH_UP_BACKEND_URL` / `CATCH_UP_BACKEND_SECRET`).

## Automated checks

From the CRM source directory, inside the repo's nix shell (`nix develop path:../../nix#crm`):

```sh
php phpunit.phar custom/Espo/Modules/FeatureCatchUp/Tests
```

From `components/backend/source`:

```sh
node --import tsx --experimental-test-module-mocks --test 'source/modules/hatchet/workflows/$catchUpSummary.test.ts'
```
