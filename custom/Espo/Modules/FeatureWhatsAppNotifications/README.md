# Em dia no WhatsApp (FeatureWhatsAppNotifications)

Each person gets their Em dia cards in WhatsApp, from our number "Monostax - Avisos" (+55 91 4040-3376, Chatwoot account 6, inbox 74): the card (as a picture outside the 24h window) and one link that opens Em dia on that card, where they send the suggested reply, resolve or snooze it. **Cards, the welcome and the digest have no buttons** (the user, 2026-11-13); the workspace picker for a number in several workspaces keeps its buttons. Plan and decisions: `theworkspace/work/01 __ this-week/Self-Service PLG/2026-11-12 __ Em dia no WhatsApp.md` in the mono repo.

Off until the config says otherwise (see Config).

## Turning it on: a code sent from WhatsApp

Meta wants the recipient's own opt-in, and a card quotes customers, so nothing goes to a number until the person proves they hold it. They send a code FROM that number (`PhoneCodes`): a wa.me link with the text ready ("Ativar Em dia 7KQ2-M9XP"), or its QR code. The sender is the number, nothing typed is trusted, and the message opens Meta's 24h window.

- **Settings › Notificações no WhatsApp** (Chatwoot, every member, workspaces with Em dia on): `POST /WhatsAppNotifications/code` gives the code; the incoming message activates the person's subscription in that workspace and sends a welcome that says to write *em dia* for the cards.
- **Self-service signup** (FeatureSelfService `Signups`): right after the email code, the signup waits in status `phone` until the code arrives (`SelfService/signup/:id/phone` gives a new one). The verified number becomes the owner's phone, and the user and workspace are created then (by the webhook, so a closed tab still finishes; a status poll takes over after 90 s). One free trial per verified number: a number that verified another signup is refused, and the person may send from another one. When the workspace is ready, the owner's subscription is created with that number.
- Codes: 8 characters without look-alikes, valid 15 minutes, used once; a number with 5 wrong codes in an hour is ignored for the rest of it.

One subscription per person and workspace (`WhatsAppNotificationSubscription`). One number belongs to one person per workspace: verifying it for someone else stops the other (`moved`). "PARAR", "SAIR", "STOP" or "🔕 Parar avisos" stops every subscription of the number (`keyword`); only a new code turns it back on.

## The deck

`Deck` reads the person's Em dia as that person (`ActorScope`): the same feeds, order, access rules (Ownership, the CRM ACL, Chatwoot's `conversation_access`) and stored verdicts as the app. A WhatsApp card is a card with a reply: `customer_waiting`, `promise_due`, `follow_up_due`, `next_step_missing`. Found deals and Ganho/Perdido stay in the app. Nothing is analysed here: only subjects already judged at their current revision are offered (settle analyses every conversation), and Summary answers from the stored verdict. Each card shown is saved as a `WhatsAppDeckCard` with its revision.

Every card is text (or the image template) with **the card link**: `Workspaces::cardLink`, Chatwoot `/app/accounts/{n}/catch-up?card=<lane>:<id>` (normal login). Chatwoot's `CatchUpView` reads `?card`, opens that card's tab on it (pulling up to 5 more pages to find it, else putting it first) and drops the param. Sending the reply, Já resolvi and Adiar happen there, so WhatsApp never acts on a customer conversation by itself.

Buttons of messages sent before 2026-11-13 still act as they did (`Taps`: Enviar sends the stored draft as the person through `Acting::send`, Já resolvi, Me lembre mais tarde), and every answer to them is text with the card link.

Those older payloads are signed (`Tokens`: kind, id, action, HMAC from `cryptKey`) and act only from the number of the active subscription it names. "em dia" or "próximo" sends the next card; any other text goes to their AI (see below). Text from numbers without a subscription stays in account 6 for the Monostax team.

Inside the 24h window a card is a free text with the card link. Outside it only the card template goes out (`Cards`, `cardTemplate`), and only for a card the template can say (the customer waits on us: `customer_waiting`, `promise_due`).

### Card as a picture

With `cardImage` the card template has an IMAGE header and its body takes {{1}} who, {{2}} how long and {{3}} the card link (`Cards::show`). `CardImages` puts a public link in the header, `GET /api/v1/WhatsAppNotifications/cardImage/{card}.{expires}.{hmac}.png` (no login, signed per card with the module secret, valid `CardImages::TTL` = 1 day), and Meta fetches it when it sends. The controller asks the backend to draw it: `POST /internal/catch-up/card-image` on `CATCH_UP_BACKEND_URL`, signed with `CATCH_UP_BACKEND_SECRET` like Em dia's summary requests; Satori + resvg in `components/backend` (`source/modules/card-image`), 1200 px wide. It shows what Em dia's card shows without the composer: photo (`ChatwootConversation.contactAvatarUrl`, else initials), name, "Parado há …", the deal line (name · amount · owner) on deal cards, headline and context. Emoji are left out (no emoji font).

Inside the 24h window the message is the user's format (2026-11-13), the picture and two lines, nothing more: `🔥 *Sara Ferreira* está esperando resposta há mais de *8 horas*.` and the card link with `&utm_source=whatsapp` (`Avisos::image`, a multipart message in Chatwoot). The picture is 1:1 with large type and no suggested reply (the user, 2026-11-13; the reply lives in Em dia). Outside the window it is the UTILITY template `monostax_catchup_card_link` (IMAGE header, the same line, "Abra o card no Em dia: {{3}}" with the link and utm, "A sugestão de resposta já está pronta lá."): Meta filed the bare two-line templates `monostax_emdia_card_v2` and `_v3` as MARKETING (9x the cost, marketing caps), so the user kept the UTILITY one (2026-11-13). Template links pass through untouched (`Avisos::template` strips formatting characters from other values). Earlier tests on the WABA: `monostax_catchup_card_img` (UTILITY), `monostax_catchup_card_opcoes` (MARKETING); `monostax_catchup_card` (text, 4 quick replies) is MARKETING.

## The AI in WhatsApp

Any other text from a subscriber is a question for their AI (`Assistant`). It becomes the person's own post in their **main AI chat** of that workspace (FeatureAiSession `Primary`), submitted as them through `ActorScope`: their CRM ACL, Chatwoot access (the agent's Chat tools open conversations as the human), agents, model and credits, as if they typed it in the app. The post carries `aiSessionChannel = whatsapp`, so the agent answers briefly without tables or headings, and the app shows it "pelo WhatsApp". Right after the submit, Chatwoot shows "digitando…" on their WhatsApp (`Avisos::typing`, Chatwoot's `toggle_typing_status`, which also marks the message read).

When the agent's reply finishes (completed, failed, or blocked for credits), the Note hook `MirrorAssistantReply` queues `SendAssistantReply`, and `Assistant::reply` sends it as free text inside the 24h window: Markdown turned into WhatsApp formatting (`WhatsAppText`), at most 3 messages of 3,900 characters, the last ending with the chat's link when there is more. Every message ends with a blank line and who answered, in bold: agent / workspace (`*✦ IA - Kibu / Monostax*`); the app post doesn't. It goes only to the active subscription that asked, only while that person still owns the chat in the same workspace, once per reply and part (send key `ai:{reply}:{n}`). A cancelled turn says nothing; a closed window sends nothing (the answer stays in the app). Messages typed in the app never go to WhatsApp.

- "em dia", "próximo" (and "cards") still open the deck; these and "trocar" are the only command words, matched exactly (`Inbound::command`).
- With one number in several workspaces, text goes to the workspace of our last message to the person when it went out in the last 30 minutes (`Inbound::lastMessaged`, from the delivery log), else to the one whose AI they last talked to (`session.aiAt`). The first time, or after "trocar", they choose one and the waiting text (`session.pending`, 1 hour) goes there.
- "Quero falar da outra empresa": in the main chat, on a WhatsApp turn, the agent has `switch_workspace` (backend `code-mode/session-agent.ts`). It calls `POST WhatsAppNotifications/switchWorkspace` as the person (`WhatsAppAssistant`, which checks the turn with `Assistant::turn`). With one other workspace on the number, the message goes straight to that workspace's AI (`Inbound::offerWorkspaces`). With more, the picker of the others goes out and the message waits for the tap. The asking workspace's agent learns only `switched` / `picker_sent` / `single`, never the other workspaces.
- Voice notes and pictures without a caption get "por enquanto só entendo texto".
- A frozen workspace answers that the AI is paused; a person without an AI agent gets `ai.unavailable`.

## Pushes

Few, because Meta blocked 247 per-event alerts from this number in Jul–Aug 2026 (131049). Each has a send key, so a retry never sends twice.

- **Morning** (`SendMorning`, every 5 min): once a day at the person's time, within 3 hours of it. The digest template when `digestTemplate` is set (with the Em dia link, no buttons), else the first card. Nothing when there is nothing.
- **Reminders** (`SendReminders`, every 5 min).
- **Hot leads** (`SendHotLeads`, every 10 min): a reply card on which Em dia found a deal (prompt 15) waiting at least `hotLeadMinutes` on us, inside the person's alert hours, while they show offline in Chatwoot, at most `hotLeadDailyCap` a day, once per customer per day and per customer message.
- A frozen workspace gets nothing.

## Meta's cost in credits

`Inbound` reads Meta's status for each of our messages (Chatwoot passes `status` and `content_attributes.meta_pricing`). A delivered message Meta marked billable is charged once (`Charges`): a `messaging` operation in FeatureCredits, admitted and settled in one wallet transaction at Meta's rate for its category (`rates`, BRL) over `brlPerCredit`, rounded up to 0.0001. With the defaults: utility, authentication and service 0.0702, marketing 0.6447. A workspace without an agreement or credits isn't charged; the log keeps `billable` without `chargedAt`.

## Data

- `WhatsAppNotificationSubscription`: person, workspace, verified phone, morning time, hot leads, alert hours, stop, the Avisos thread, `lastInboundAt` (the window), `session`.
- `WhatsAppNotification`: every message in and out, with its send key, Chatwoot message id, Meta status and error, billing and tap time.
- `WhatsAppDeckCard`, `WhatsAppPhoneCode`.

When a workspace goes: tenant offboarding (FeatureSelfService) removes subscriptions through their `tenant` link like any tenant-scoped record, and a removed subscription takes its deck cards (`Hooks/WhatsAppNotificationSubscription/RemoveCards`: they hold customer context and drafts). `WhatsAppNotification` stays as billing history (its `tenantId` is a plain field on purpose; a link would put it in the offboarding sweep). Inbound passes over a subscription whose workspace is gone (`Workspaces::exists`), so a leftover never blocks the number's other workspaces.

## Config

`whatsAppNotifications` in data/config.php (`Tools/Settings`): `enabled` (default false), `signupVerification` (default: `enabled`), `inboxId` (CRM ChatwootInbox of Avisos), `number`, `cardTemplate` (`monostax_catchup_card`), `cardImage` (default false), `digestTemplate` (create it without buttons) (unset: the morning push is the first card), `templateLanguage` (`pt_BR`), `hotLeadMinutes` (60), `hotLeadDailyCap` (2), `rates`, `brlPerCredit` (0.499).

## Deployment

1. Chatwoot: tap ids (`reply_payload`), quick-reply payloads on templates, `status` and `meta_pricing` in message webhooks, the Settings page, the credits label.
2. Backend API and Hatchet workers: the Avisos number and the Avisos inbox are system traffic (no AI agent, no Em dia).
3. CRM with `php rebuild.php` (new entities, `SelfServiceSignup` fields, scheduled jobs) and a cache clear.
4. Sync the templates of the Avisos inbox in Chatwoot so the approved `monostax_catchup_card` is there (and `monostax_catchup_card_img` / `_opcoes` before turning on `cardImage`; the backend must have `/internal/catch-up/card-image` first).
5. Set `whatsAppNotifications.enabled` (a prod config write). Signup verification follows it unless `signupVerification` is false.
6. The AI in WhatsApp needs FeatureAiSession's `primarySlot` (same CRM rebuild) and the backend's `channel` on stream posts (either order works: unknown fields are dropped on both sides).

## Checks

From the CRM source directory: `nix develop "path:../../../nix#crm" --command php phpunit.phar custom/Espo/Modules/FeatureWhatsAppNotifications/Tests`.
