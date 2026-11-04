# FeatureSelfService

Self-service signup and workspace provisioning on the CRM login page. Plan:
`theworkspace/work/01 __ this-week/Self-Service PLG/`.

## Signup and sign-in

The login view is replaced through `clientDefs.App.loginView`
(`client/custom/modules/feature-self-service/src/views/login.js`, a subclass
of the stock view). While the policy reports `enabled`, it adds:
- "Criar conta grátis", which can be opened directly with `?signup`;
- "Continuar com Google / Microsoft" for the providers that are configured.

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

Not here yet: starter pipeline, trial state, entitlements.

## Operating

```sh
php command.php self-service                       # switch, pause, counts
php command.php self-service pause --reason="..."  # stop new work everywhere
php command.php self-service resume
php command.php self-service provision --key=<key> --owner=<userId> --name="Acme" \
    [--language=pt_BR] [--time-zone=America/Sao_Paulo]
php command.php self-service run --id=<provisioningId>
```

`provision` and `run` also need the switch on and no pause.

## Tests

Unit: `tests/unit/Espo/Modules/FeatureSelfService` (policy, slugs, provisioning
requests, signup form and phones, ID-token rules), plus
`Chatwoot/Hooks/ChatwootAccount/SyncWithChatwootResumeTest` and
`Global/Tools/Tenant/TenantAdminTeamProvisionerTest`.

Integration: `tests/integration/Espo/Modules/FeatureSelfService` runs against a
disposable database. `fake-chatwoot.php` stands in for the Chatwoot Platform
API (with fault injection) and for an OAuth provider (`/oauth/authorize`,
`/oauth/token`, claims set via `/__idtoken`). In an isolated install (MariaDB on
a private Docker network, CRM installed from this checkout with
`bin/command config:populate`, the `database.*` config and `rebuild`):

```sh
FAKE_CHATWOOT_STATE=/tmp/fake.json php -S 0.0.0.0:8080 tests/integration/Espo/Modules/FeatureSelfService/fake-chatwoot.php
SELF_SERVICE_TEST_DATABASE=<db> FAKE_CHATWOOT_URL=http://<fake>:8080 \
    php phpunit.phar tests/integration/Espo/Modules/FeatureSelfService
```

- `ProvisioningTest`: the workspace, rerun no-op, same-named tenants,
  dedupe conflicts, concierge failure and lost reply, pause/resume, switch
  off, instance-admin owner, live leases, deleted team keys, the job.
- `SignupTest`: code signup to a one-time login, wrong-code lockout, token
  required, email in use, IP limit, switch off.
- `SocialSignInTest`: new Google user without a code, existing user ticket,
  foreign or tampered state, unverified email, instance admin, scopes.
