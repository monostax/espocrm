# FeatureSelfService

Self-service signup and workspace provisioning on the CRM login page. Plan:
`theworkspace/work/01 __ this-week/Self-Service PLG/`.

## Signup and sign-in

The login view is replaced through `clientDefs.App.loginView`
(`client/custom/modules/feature-self-service/src/views/login.js`, a subclass
of the stock view). Its template (`res/templates/login.tpl`) and styles
(`css/login.css`, added through `app/client.json` `cssList`) restyle the page
for every deployment after Bueno's design language
(`components/bueno/ui/DESIGN.md`). The template keeps the ids and actions the
stock view uses. While the policy reports `enabled`, the view adds:
- "Testar grátis por 7 dias", which can be opened directly with `?signup`;
- "Continuar com Google / Microsoft" for the providers that are configured.
  Their brand marks are inline SVG, since the theme has no Font Awesome brand
  glyphs.

Failures in a signup step show in the step's error line under its button and
clear on the next edit; failures elsewhere, such as a provider sign-in from
the login page, show as a notice.

The public, no-auth API is `/api/v1/SelfService/...`. Errors are `{"code": ...}`.

| Route | Does |
| --- | --- |
| `GET config` | `{state, providers}` for the login page. |
| `POST signup` | Validates the form, emails a 6-digit code, returns `{signupId, token}`. |
| `POST signup/:id/resend` | New code. 60 s cooldown, at most 5 per signup. |
| `POST signup/:id/verify` | Right code: creates the owner (regular user, login = email), starts and runs provisioning. Returns `{status}` and, when ready, a `ticket`. |
| `POST signup/:id/status` | Progress while provisioning finishes. Returns a fresh ticket when ready, for one hour after verification. |
| `POST oauth/start` | Provider authorization URL with a signed `state` bound to the browser's nonce. |
| `POST oauth/finish` | Exchanges the code. An existing regular user gets a `ticket`; a new email gets a signed `identity`. |
| `POST oauth/complete` | `identity` + WhatsApp + company + terms: the signup, without an email code. |

Rules:
- **What is stored:** only hashes of the signup token, the code and the password. No CRM user exists until the email is proven.
- **Limits:**
  - 10 signups per IP per hour (`CF-Connecting-IP`, else `REMOTE_ADDR`) and 5 per email;
  - 5 code attempts, and a code expires after 15 min.
- **Emails already in use:** refused (`EMAIL_IN_USE`), as are reserved machine-identity addresses.
- **Code email:** goes through the system SMTP account (Cloudflare in production, set up by the container's `apply-runtime-smtp-account.php`). A failure returns `EMAIL_NOT_SENT`.
- **Login tickets** (`LoginTickets`): single use, valid for 5 minutes, regular users only.
  - `Classes/Authentication/TicketLogin` replaces the `Espo` login method. It accepts username `**self-service` with the ticket as password, the way Espo's OIDC sends its code.
  - Everything else goes to Chatwoot's `AgentEmailLogin`.
  - The SPA then runs its normal login, including the Chatwoot redirect. Two-factor still applies.
- **Social sign-in** (`SocialSignIn`, `SocialIdentity`):
  - It reuses the `google-gmail` and `microsoft-365` OAuthProviders and their registered redirect URI, `<siteUrl>/oauth/callback/`, asking only for `openid email profile`.
  - Google needs `email_verified`.
  - Microsoft is trusted for personal accounts, or for work accounts whose token carries `xms_edov` (domain verified). Without that, work account emails can be spoofed by any tenant admin.
  - Instance admins and API users can't sign in this way.

Setup that only the provider consoles can confirm:
- **Google:** the OAuth consent screen must be published (not in "Testing") for unknown users to sign in.
- **Microsoft:** to accept work accounts, add the optional ID-token claim `xms_edov` to the app registration (Token configuration). Until then only personal Microsoft accounts work.

## Switch and pause

`Tools/Policy/SelfServicePolicy`:

```text
enabled = CRM_SELF_SERVICE_ENABLED is exactly "true"  AND  not paused
```

- `CRM_SELF_SERVICE_ENABLED` is read from the process environment on every
  check. It is not copied into `data/config.php`, so removing it can't leave
  an old `true` behind. Gitops sets it on every CRM container from the
  namespace's `crmSelfServiceEnabled` (default `false`).
- The runtime pause is one `SelfServiceControl` row (id `global`). Every web
  and worker process sees it on its next check, without a rollout.
- `assertEnabled()` throws `SelfServiceUnavailable` (`state` = `disabled` or
  `paused`). HTTP surfaces return its stable code `SELF_SERVICE_DISABLED`.
- The policy governs creating new workspaces only. Existing workspaces,
  logins, channels and billing are not affected.

## Provisioning

`Tools/Provisioning/Provisioner` creates, for an existing **regular** CRM user:

1. `tenant`: the Tenant with a DNS-safe slug, its base team and its admin
   team. Teams are keyed by `Team.provisioningKey` (`tenant-base:<id>`,
   `tenant-admin:<id>`), never by name, so same-named businesses never share
   a team.
2. `owner`: the user joins the tenant, its base and admin teams, and gets the
   base team as default team. Instance administrators can't own a workspace.
3. `chatwootAccount`: the ChatwootAccount on the server-chosen platform
   (`selfServiceChatwootPlatformId`, or the only platform there is), shared
   with the base team.
4. `ownerMembership`: the owner's Chatwoot membership as `administrator`.
5. What the workspace starts with (`WorkspaceStarter`, Self-Service PLG
   "Next steps to launch" §7, decided 2026-11-10):
   - `salesFunnel`: the default "Vendas" funnel (Em negociação 50%, Ganho
     100%, Perdido 0%), shared with the base team, AI follow-ups off. Ganho and
     Perdido carry the Monostax default rule (gtm/06). A tenant that already
     has a funnel keeps it.
   - `aiAgent`: a managed AI agent ("IA <workspace>") through
     `Chatwoot/Services/AiAgentProvisioning`, with no inbox, so Chatwoot never
     assigns it a conversation and it never replies. It manages the default
     funnel (`funnelsToManage`), which the opportunity tracker writes through.
     Its operation id is derived from the provisioning id, so a retry resumes
     the same identity. It is not an attendant (`PlanRecommendation` skips AI
     memberships); a future Chatwoot agent limit must skip it too.
   - `emDia`: Em dia on for the Chatwoot account (`catch_up_enabled`, set
     with the concierge key through the Account API).

The owner can enter as soon as step 4 is done (`Provisioner::ownerCanEnter`,
used by the signup's readiness): step 5 never holds the login, and a failure
there retries on the job's backoff while the owner already works.

Contracts:

- `start(ProvisioningRequest)` records the request under its dedupe key and
  creates nothing else. The same key returns the same record; the same key
  with different settings is a `Conflict`.
- The Tenant and ChatwootAccount ids are chosen when the record is created. A
  retry after a crash or lost reply finds what it created. The ChatwootAccount
  hook resumes its Chatwoot account and concierge user for the same id
  (`Chatwoot/Services/ChatwootAccountCreationJournal`).
- `run(id)` takes a 10-minute lease, then runs the remaining steps. Each step
  checks its own result, so repeating one is a no-op. Writes are conditioned
  on the lease token and a revision: a worker whose lease expired stops
  instead of overwriting the one that took over.
- The policy is checked before each step. When off, the record becomes
  `paused` and continues from the same step once self-service is back.
- Failures retry with backoff (1 min doubling, at most 30 min, 8 attempts).
  `ProvisioningFailure` (bad owner, missing configuration) stops without
  retry; an operator reruns it with `self-service run`.
- The `RunSelfServiceProvisionings` job runs every minute. It runs due,
  failed-and-due and paused records, and takes over records whose lease
  expired. It does nothing while self-service is off.

## First read

When a workspace's onboarding opens (`Tenant.onboardingStep` becomes `done`:
its newest history is in, or the number brings none), the
`Hooks/Tenant/ScheduleFirstRead` hook schedules `Jobs/RunFirstRead` for two
minutes later. `Tools/Onboarding/FirstRead` then:

- waits, a minute at a time and 15 minutes at most, until the CRM has the
  imported conversations (no `ChatwootAccount.conversationBackfillPage` left
  and at least one conversation);
- runs `FeatureCatchUp/Services/Prefetch` as the owner (`ActorScope`): Em dia
  analyses the first 15 cards of the owner's deck, deals first, through the
  deck's own feeds, snapshots and analysis. The job only queues the analyses,
  then comes back every 30 seconds (at most 6 times) to store the ones that
  completed, so it never sleeps in one of the daemon's 4 job slots; the console
  (`self-service first-read`) waits in place. The deck finds each verdict
  stored and opens at once; deals found in conversations (`new_deal`) are
  cards that create the deal in one tap, in the funnel and stage the analysis
  picked (`FeatureCatchUp/Services/ProposedDeals`).

It runs on the workspace's credits like any Em dia analysis (the legacy run
budget never charges Em dia; the wallet does). Subjects already judged are
skipped, so running it again costs nothing; a failure only means the deck
analyses its cards when opened.

## Offboarding

`Tools/Offboarding/Offboarder` removes a workspace and what it owns, inside
and outside the CRM. It is the only way to remove a Tenant:
`Hooks/Tenant/RequireOffboarding` refuses any other removal (detail, mass
delete, API), and `Classes/Record/Deleted/TenantRestorer` refuses to restore
an offboarded one. Before, deleting a Tenant soft-deleted that one row and left
its Chatwoot accounts, WhatsApp sessions, users, teams and records running.

An instance admin starts it from the Tenant's menu ("Offboard workspace",
typing the slug) or the console. The `RunSelfServiceOffboardings` job runs it
every minute, whatever the self-service switch says. One
`SelfServiceOffboarding` record per tenant holds the step, progress and
counts; it uses the same lease, revision and backoff as provisioning, and each
run works for up to 4 minutes before leaving the rest to the next.

| Step | Does |
| --- | --- |
| `access` | Users who belong only to this tenant are deactivated (removed in `users`). Everyone else, instance admins included, loses the tenant and its teams. Managed AI agent users are retired with their identity. |
| `channels` | Ends every WAHA session of the tenant's Chatwoot accounts, also those of accounts removed earlier. |
| `chatwootRemote` | Suspends each ChatwootAccount locally (sync jobs skip it), then deletes the Chatwoot account. |
| `chatwootLocal` | Removes the local mirror in batches of 100, conversations first, without remote calls, then the ChatwootAccount row. |
| `records` | Every tenant-scoped record (`TenantDataMap`: any entity with a belongsTo Tenant link), children before parents, in batches. |
| `sharedRecords` | Records with no tenant link whose only teams are this tenant's (tasks, meetings, emails, ...). |
| `teams` | The tenant's teams, unless another tenant uses them. |
| `users` | The users from `access`, and their Chatwoot users (and the concierge) when no other account has them. Their emails can sign up again. |
| `tenant` | The Tenant. Its native `cascadeRemoval` links (contacts, accounts, leads, opportunities, funnels, tags, custom fields) sweep anything added since. |

Rules:
- **Kept:** billing and usage history: the FeatureCredits module, AI billing
  rates, usage reservations, AI runs, reporting events, and retired AI
  identities (`ChatwootMachineIdentity`), so their emails are never reissued.
  Their rows keep the removed tenant's id.
- **Refusals:** a record whose remove hook refuses it (Forbidden, Conflict,
  BadRequest), such as a rejected contact identity kept for outreach
  suppression or a managed campaign summary, is kept and counted under
  `state.kept`. Other errors retry with backoff.
- **Open subscription:** starting refuses (`OPEN_SUBSCRIPTION`) while the
  tenant has a billing obligation in `accepted`, `creating`, `unknown`,
  `pending` or `active`. Cancel it first.
- **Already deleted:** a tenant soft-deleted before offboarding existed can be
  offboarded too: its leftovers go the same way.
- **Not covered:** virtual entities (AgentMode, AgentSkill: OpenCode files),
  records with neither a tenant link nor a team (personal emails of removed
  users), and Meta/Google/Cal.com subscriptions on the customer's side.

Removing a ChatwootAccount directly now also ends its WAHA sessions
(`Chatwoot/Hooks/ChatwootAccount/TearDownWahaSessions`): its channels are
removed as cascade children, which skip their own WAHA cleanup, so those
WhatsApp numbers used to stay logged in.

## Operating

```sh
php command.php self-service                       # switch, pause, counts
php command.php self-service pause --reason="..."  # stop new work everywhere
php command.php self-service resume
php command.php self-service provision --key=<key> --owner=<userId> --name="Acme" \
    [--language=pt_BR] [--time-zone=America/Sao_Paulo]
php command.php self-service run --id=<provisioningId>
php command.php self-service offboard --tenant=<tenantId> --confirm=<slug>   # start or retry, run now
php command.php self-service offboarding --tenant=<tenantId>                 # status and counts
php command.php self-service starter --tenant=<tenantId>      # funnel, silent AI agent, Em dia for an existing workspace
php command.php self-service first-read --tenant=<tenantId>   # run the first read now, without waiting
```

`provision` and `run` also need the switch on and no pause. `offboard` does not.

## Tests

Unit: `tests/unit/Espo/Modules/FeatureSelfService` (policy, slugs, provisioning
requests, signup form and phones, ID-token rules, offboarding order and kept
types), plus
`Chatwoot/Hooks/ChatwootAccount/SyncWithChatwootResumeTest`,
`Global/Tools/Tenant/TenantAdminTeamProvisionerTest` and `WorkspaceStarterTest`
(the agent's operation id, its name, when the owner can enter).

Integration: `tests/integration/Espo/Modules/FeatureSelfService` runs against a
disposable database. `fake-chatwoot.php` stands in for the Chatwoot Platform
API (with fault injection) and for an OAuth provider (`/oauth/authorize`,
`/oauth/token`, claims set via `/__idtoken`), and answers machine identities,
account settings (`catch_up` reflects `catch_up_enabled`) and
`conversation_access` (deny some with `/__seed {deniedConversations}`). In an
isolated install (MariaDB on a private Docker network, CRM installed from this
checkout with `bin/command config:populate`, the `database.*` config and
`rebuild`), with `frontendTenantDomain` set (otherwise concierge emails fall in
the reserved `monostax-ext.com` namespace and are refused) and the
`ChatwootPlatform` pointing at the fake:

```sh
FAKE_CHATWOOT_STATE=/tmp/fake.json php -S 0.0.0.0:8080 tests/integration/Espo/Modules/FeatureSelfService/fake-chatwoot.php
SELF_SERVICE_TEST_DATABASE=<db> FAKE_CHATWOOT_URL=http://<fake>:8080 \
    php phpunit.phar tests/integration/Espo/Modules/FeatureSelfService
```

- `ProvisioningTest`: the workspace with its funnel, silent AI agent and Em
  dia, rerun no-op, the owner entering while the starter retries (one agent
  after the retry), an existing funnel kept, same-named tenants, dedupe
  conflicts, concierge failure and lost reply, pause/resume, switch off,
  instance-admin owner, live leases, deleted team keys, the job.
- `FirstReadTest`: onboarding opening schedules the first read once, the
  wait for conversations, the console read as the owner, no read without a
  plan.
- `ProposedDealsTest` ("Criar oportunidade" on a found deal, FeatureCatchUp):
  created as the card shows it (the proposed funnel and won stage), linked and
  resolved, never twice; the reader's changes (another funnel's stage refused,
  then accepted); a card from before funnels in the default funnel's open
  stage; a conversation that moved since is a conflict; the deal goes to the
  person assigned in Chatwoot.
- `SignupTest`: code signup to a one-time login, wrong-code lockout, token
  required, email in use, IP limit, switch off.
- `SocialSignInTest`: new Google user without a code, existing user ticket,
  foreign or tampered state, unverified email, instance admin, scopes.
- `OffboardingTest`: direct Tenant removal refused, slug and open
  subscription checks, a full offboarding (WAHA session ended, Chatwoot
  account and users deleted, data and teams removed, users of other
  workspaces and billing kept, no restore), retry after a failed remote
  delete without repeating done steps, and the ChatwootAccount WAHA teardown.
