# Em dia no WhatsApp (FeatureWhatsAppNotifications)

Each person works their Em dia cards inside WhatsApp, from our number "Monostax - Avisos" (+55 91 4040-3376, Chatwoot account 6, inbox 74): see the suggested reply and send it, mark the card resolved, snooze it, open the conversation. Plan and decisions: `theworkspace/work/01 __ this-week/Self-Service PLG/2026-11-12 __ Em dia no WhatsApp.md` in the mono repo.

Off until the config says otherwise (see Config).

## Turning it on: a code sent from WhatsApp

Meta wants the recipient's own opt-in, and a card quotes customers, so nothing goes to a number until the person proves they hold it. They send a code FROM that number (`PhoneCodes`): a wa.me link with the text ready ("Ativar Em dia 7KQ2-M9XP"), or its QR code. The sender is the number, nothing typed is trusted, and the message opens Meta's 24h window.

- **Settings › Notificações no WhatsApp** (Chatwoot, every member, workspaces with Em dia on): `POST /WhatsAppNotifications/code` gives the code; the incoming message activates the person's subscription in that workspace and sends a welcome with "👀 Ver agora".
- **Self-service signup** (FeatureSelfService `Signups`): right after the email code, the signup waits in status `phone` until the code arrives (`SelfService/signup/:id/phone` gives a new one). The verified number becomes the owner's phone, and the user and workspace are created then (by the webhook, so a closed tab still finishes; a status poll takes over after 90 s). One free trial per verified number: a number that verified another signup is refused, and the person may send from another one. When the workspace is ready, the owner's subscription is created with that number.
- Codes: 8 characters without look-alikes, valid 15 minutes, used once; a number with 5 wrong codes in an hour is ignored for the rest of it.

One subscription per person and workspace (`WhatsAppNotificationSubscription`). One number belongs to one person per workspace: verifying it for someone else stops the other (`moved`). "PARAR", "SAIR", "STOP" or "🔕 Parar avisos" stops every subscription of the number (`keyword`); only a new code turns it back on.

## The deck

`Deck` reads the person's Em dia as that person (`ActorScope`): the same feeds, order, access rules (Ownership, the CRM ACL, Chatwoot's `conversation_access`) and stored verdicts as the app. A WhatsApp card is a card with a reply: `customer_waiting`, `promise_due`, `follow_up_due`, `next_step_missing`. Found deals and Ganho/Perdido stay in the app. Nothing is analysed here: only subjects already judged at their current revision are offered (settle analyses every conversation), and Summary answers from the stored verdict. Each card shown is saved as a `WhatsAppDeckCard` with its revision.

| Button | What happens |
| --- | --- |
| Ver sugestão | The stored draft, with Enviar / Editar / Próximo. If the conversation moved, the card is rebuilt from the new stored verdict, or the person is told it changed. |
| ✅ Enviar | `Acting::send`: snapshot again as the person, refuse if the revision isn't the card's, the deck's review (marks the verdict acted on), then the message goes into the customer's conversation through Chatwoot's account API with the person's own token (`ChatwootUser.userAccessToken`, or fetched with the platform token). Chatwoot checks their access, blocks a frozen workspace and records them as the author; the message carries `content_attributes.sent_from = whatsapp_avisos`. |
| ✏️ Editar | The person's next text becomes the reply, shown back with Enviar / Cancelar. Typed text never acts otherwise. |
| Abrir conversa | The conversation's link in the app (normal login). |
| Já resolvi | As the deck's: a note "Resolvido por fora: sem detalhes (pelo WhatsApp)" on a deal, the loop kept on a conversation. |
| Me lembre mais tarde | Em 1 hora / Amanhã 8h / Segunda 8h (workspace time): the deck's Adiar, and the card comes back then if it still is one. |
| Próximo / Por hoje é só | The next card; subjects passed over stay out for the day (`Sessions`). |

Every button payload is signed (`Tokens`: kind, id, action, HMAC from `cryptKey`) and acts only from the number of the active subscription it names. Any other text from a subscriber sends their next card ("person asks"); a number in several workspaces gets a choice first. Text from numbers without a subscription stays in account 6 for the Monostax team.

Inside the 24h window a card is a free message with three buttons and the link in its text. Outside it only `monostax_catchup_card` goes out (`Cards`), and only for a card the template can say (the customer waits on us: `customer_waiting`, `promise_due`).

## Pushes

Few, because Meta blocked 247 per-event alerts from this number in Jul–Aug 2026 (131049). Each has a send key, so a retry never sends twice.

- **Morning** (`SendMorning`, every 5 min): once a day at the person's time, within 3 hours of it. The digest template when `digestTemplate` is set (its "👀 Ver agora" starts the deck), else the first card. Nothing when there is nothing.
- **Reminders** (`SendReminders`, every 5 min).
- **Hot leads** (`SendHotLeads`, every 10 min): a reply card on which Em dia found a deal (prompt 15) waiting at least `hotLeadMinutes` on us, inside the person's alert hours, while they show offline in Chatwoot, at most `hotLeadDailyCap` a day, once per customer per day and per customer message.
- A frozen workspace gets nothing.

## Meta's cost in credits

`Inbound` reads Meta's status for each of our messages (Chatwoot passes `status` and `content_attributes.meta_pricing`). A delivered message Meta marked billable is charged once (`Charges`): a `messaging` operation in FeatureCredits, admitted and settled in one wallet transaction at Meta's rate for its category (`rates`, BRL) over `brlPerCredit`, rounded up to 0.0001. With the defaults: utility, authentication and service 0.0702, marketing 0.6447. A workspace without an agreement or credits isn't charged; the log keeps `billable` without `chargedAt`.

## Data

- `WhatsAppNotificationSubscription`: person, workspace, verified phone, morning time, hot leads, alert hours, stop, the Avisos thread, `lastInboundAt` (the window), `session`.
- `WhatsAppNotification`: every message in and out, with its send key, Chatwoot message id, Meta status and error, billing and tap time.
- `WhatsAppDeckCard`, `WhatsAppPhoneCode`.

## Config

`whatsAppNotifications` in data/config.php (`Tools/Settings`): `enabled` (default false), `signupVerification` (default: `enabled`), `inboxId` (CRM ChatwootInbox of Avisos), `number`, `cardTemplate` (`monostax_catchup_card`), `digestTemplate` (unset: the morning push is the first card), `templateLanguage` (`pt_BR`), `hotLeadMinutes` (60), `hotLeadDailyCap` (2), `rates`, `brlPerCredit` (0.499).

## Deployment

1. Chatwoot: tap ids (`reply_payload`), quick-reply payloads on templates, `status` and `meta_pricing` in message webhooks, the Settings page, the credits label.
2. Backend API and Hatchet workers: the Avisos number and the Avisos inbox are system traffic (no AI agent, no Em dia).
3. CRM with `php rebuild.php` (new entities, `SelfServiceSignup` fields, scheduled jobs) and a cache clear.
4. Sync the templates of the Avisos inbox in Chatwoot so the approved `monostax_catchup_card` is there.
5. Set `whatsAppNotifications.enabled` (a prod config write). Signup verification follows it unless `signupVerification` is false.

## Checks

From the CRM source directory: `nix develop "path:../../../nix#crm" --command php phpunit.phar custom/Espo/Modules/FeatureWhatsAppNotifications/Tests`.
