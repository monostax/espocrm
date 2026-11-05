# Catch Up / Em dia (v2: deal-first)

Em dia is a personal decision deck in Chatwoot (`/app/accounts/:accountId/catch-up`). Every card comes from an open deal: the AI reads the deal's whole conversation and decides whether a person has something to do. Most deals get no card. Plan and decisions: `gtm/05-catchup-decisions-plan.md` in the mono repo. Chatwoot side: `components/chatwoot/source/app/javascript/dashboard/routes/dashboard/catchup/README.md`.

## Opt-in, credits and limits

**Default: disabled for every account.** Administrators enable it in **Settings → Conversation Workflow → Em dia** (`account.settings.catch_up_enabled`, `catch_up_daily_generation_limit`). The CRM reads the opt-in from the Chatwoot Platform Account API on every request; a missing flag or failed lookup never enables it.

- Each analysis is charged to the tenant's AI credits on the Opportunity (`unified-prepaid-v1`). A stored verdict is reused for free.
- Account limit: 300 analyses per UTC day by default (0–2000), shared by all users. Installation ceiling: `CATCH_UP_INSTALLATION_DAILY_LIMIT` on the Hatchet workers (default 20000).
- At most 50 evidence entries (40 messages, 10 deal notes) and 32 KB reach the model. Images and documents in those messages go along as media: the newest 8, 20 MB and 20,000 input tokens in total, chosen by the backend. Voice notes go as their transcripts (as audio only when there is none); video and other kinds stay text markers.

## Who sees a deal

- A deal owned by a person goes to that person.
- A deal owned by an AI agent (a user behind an `isAI` membership), or by nobody, goes to the **last staff member who wrote in its conversation from the app**.
- Tenant admins (`TenantRoleAuth::hasTenantAdminRole`) see every open deal in the tenant. The Opportunity ACL applies to everyone.

The last staff writer and who spoke last live in `CatchUpDealState` (one row per opportunity, id = opportunity id). The `ChatwootMessage` afterSave hook `RefreshCatchUpDealState` keeps it current; the rebuild action `BackfillDealState` fills open deals that have no row. A failing refresh is logged and never breaks message sync.

## How the deck loads

1. `GET /CatchUp` returns candidates: open deals with a linked conversation in the reader's deck, not snoozed, quiet for at least 30 minutes (`Feed::SETTLE_MINUTES`; a conversation still moving is analysed once it settles), ordered without scanning messages (customer waiting first, then quiet past 2 days, then moving, then quiet 30+ days). A deal quiet for over 45 days (`Feed::DORMANT_DAYS`) has had its last check and is left out until a message moves it. Deals already judged `none` at their current revision are dropped, so a page can be short.
2. `GET /CatchUp/:id` builds the snapshot: both directions of every linked conversation, read from Chatwoot's messages API by `Transcript` (each labeled customer, ai, staff, outside or internal), plus the deal's Posts, the facts, the revision and the review token. Times are in the reader's time zone, with the offset. The CRM's `ChatwootMessage` copy is not used for evidence: its sync stores only each conversation's last message at every poll, so it misses messages. A conversation is read only when the reader passes the `ChatwootMessage` ACL for it, and a conversation Chatwoot can't return fails the analysis instead of being skipped.
**Atendimento (`ServiceFeed`, step 2 of gtm/05 §12).** Conversations of the deck's Chatwoot account with no open deal, one card per conversation, under `/CatchUpService` (list, read, summary, review, resolved, label). The access rules:
- Candidates pass the `ChatwootConversation` strict access filter (inbox membership).
- Every action checks the conversation again and requires it to belong to the deck's account.
- A label can only be set on a participant of the card the reader was shown.

Candidates are active in the last 30 days and quiet for 30 minutes. They are not the WhatsApp control chat, not snoozed, and not labeled personal, automated or team. `catch_up_party` mirrors labels so that filter needs no Chatwoot read; it is refreshed on every read and write. The owner is the Chatwoot assignee when that's a person, otherwise the last staff writer (`CatchUpConversationState`, kept by a `ChatwootMessage` hook and filled by `BackfillConversationState` for the last 30 days). Admins see all.

Verdicts live in `CatchUpVerdict` with `conversationId` instead of `opportunityId`. `Summary` keeps only `customer_waiting`, `follow_up_due`, `new_deal` and `none` for a conversation, and makes it `none` when the conversation's own contact or group is personal, automated or team.

**Who is who (`Parties`, step 1 of gtm/05 §12).** A contact's or group's class (customer, partner, team, personal, automated) lives on the Chatwoot contact as the "Em dia" list field (custom attribute `em_dia_tipo`). People see and correct it in the contact panel. `Transcript` returns the groups among a deal's conversations and the people writing in them as `participants`, keyed by account (a group is one contact per Chatwoot account). A message from someone labeled Equipe counts as staff. Unlabeled groups and group members are classified in the same model call as the verdict, never in a background sweep, and `Summary` writes each class into the contact's field only while it is still empty, creating the field definition the first time. The verdict is stored under the evidence scope the next read will compute with those labels (`Feed::evidenceScope` hashes each entry with who it counts as sent by), so a later correction gets the deal judged again. A deal's own contact in a direct chat is never classified.

3. `POST /CatchUp/:id/summary` returns the stored verdict for the same revision, locale and evidence, or starts or polls the backend analysis and stores the verdict when it completes. An answer that isn't a valid verdict is stored as `none`.

A **revision** (`Revision::of`) hashes the prompt and evidence versions, the stage, amount, owner, follow-up status, next step, the deal state, the newest Post and the quiet-time step (1, 2, 4, 7, 14, 30 days; 30 is the last check). A verdict is reused until the revision changes. Verdicts live in `CatchUpVerdict`, shared by readers who see the same evidence.

## Endpoints

- `GET /CatchUp?accountId=&timeZone=&cursor=&limit=`: `{items, cursor}`
- `GET /CatchUpCount?accountId=`: card verdicts from the last 24 hours that nobody acted on (rail badge)
- `GET /CatchUp/:id?accountId=&timeZone=`: snapshot
- `POST /CatchUp/:id/summary` with `{accountId, locale, timeZone, requestId?}`: `{status, requestId, verdict, reason, card, cached, snapshot}`
- `POST /CatchUp/:id/review` with `{snapshot}`: 409 when the deal changed since the card was shown; otherwise marks its verdict acted on
- `POST /CatchUp/:id/close` with `{accountId, status: Won|Lost}`: moves the deal to its funnel's 100% or 0% stage through the record service
- `POST /CatchUp/:id/resolvedElsewhere` with `{accountId, text}`: writes a Post "Resolvido por fora: …" that the next analysis reads
- `POST /CatchUpSnooze` with `{accountId, type: Opportunity, id, until}`: per-user Adiar, at most 30 days

Next steps use the existing `POST /Opportunity/:id/nextAction`.

## Deployment

Deploy the backend and Hatchet workers, then the CRM, then Chatwoot. Run `php rebuild.php`: it creates `CatchUpVerdict` and `CatchUpDealState` and backfills the deal state. Transport to the backend is unchanged (HMAC-signed `POST /internal/catch-up/summary`, `CATCH_UP_BACKEND_URL` / `CATCH_UP_BACKEND_SECRET`).

## Automated checks

From the CRM source directory, inside the repo's nix shell (`nix develop path:../../nix#crm`):

```sh
php phpunit.phar custom/Espo/Modules/FeatureCatchUp/Tests
```

From `components/backend/source`:

```sh
node --import tsx --experimental-test-module-mocks --test 'source/modules/hatchet/workflows/$catchUpSummary.test.ts'
```
