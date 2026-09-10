# Agent invitation/login incident — 2026-09-10

## Diagnosis

The invitation/reset link authenticates the user in Chatwoot. On logout, the
Chatwoot frontend sends the browser to the CRM login. Opening Chatwoot's normal
login route also redirects to the CRM. Four independent gaps break that handoff:

1. CRM's Espo authentication looks up `User.userName`, not `emailAddress`.
   These agents have usernames different from their invitation emails.
2. All five reported agents have a CRM User and a Chatwoot User, but no CRM
   `ChatwootUser` identity mapping. The password-sync endpoint consequently
   returns `404 No linked CRM user`; the CRM never receives the chosen password.
3. `SyncAccountUserMembershipsFromChatwoot` skips agents without that mapping,
   so waiting for the scheduled job cannot repair the identity or SSO membership.
4. Chatwoot account 9 is permissible to PlatformApp 1, but none of the five
   Chatwoot users is permissible to a platform app. Even after repairing the CRM
   mapping, the platform `/users/:id/login` endpoint would reject SSO.

Production observations were made in context
`gke_monostax-hot_us-central1-a_genesis`, namespace `ai-monostax-app`.

Representative evidence (UTC):

- 2026-09-10 18:33:24: `ChatwootPasswordSync: No linked CRM user found for Chatwoot user 93`.
- 2026-09-10 18:35:37: CRM AuthLogRecord for Jocilene's email is denied with
  `CREDENTIALS` and no resolved user ID.
- Haiane has successful historical CRM logins under `haiane.izane`, while
  attempts under her email are denied.
- PlatformAppPermissible lookup: account 9 → app 1; users 86, 87, 90, 93, 94 → no rows.

| Email | CRM username | Chatwoot ID | CRM active | Chatwoot confirmed |
| --- | --- | ---: | --- | --- |
| miriangleise79@gmail.com | mirian.gleise | 86 | Yes | Yes |
| laradamasceno686@gmail.com | lara.siane | 87 | Yes | Yes |
| haianeizane093@gmail.com | haiane.izane | 90 | **No** | Yes |
| jocilenelemos22@gmail.com | jocilene.gama | 93 | Yes | Yes |
| pcarv2006@gmail.com | pedro.henrique | 94 | Yes | **No** |

All five CRM users share team `69f798f5567ee32c0` with CRM ChatwootAccount
`69f887d1df9e9e80c` (remote account 9, platform `697b8aa1d101dac35`).
Jocilene's earlier remote ID 92 was replaced by ID 93; use the current identity.

## Prepared changes

### CRM: branch `fix/chatwoot-agent-login`

- `ChatwootAgentIdentity`: import the remote identity for an existing, active
  regular CRM user, requiring a unique primary email and shared account team.
  Conflicting existing links are preserved. Imported identities have no invented
  password; normal email/team field savers still run.
- Membership sync invokes the importer instead of permanently skipping these agents.
- The HMAC-authenticated password-sync handler also performs the import when
  necessary, verifying the sender's installation and actual account membership
  via the authenticated Chatwoot Account API. This covers a reset arriving before
  the scheduled job.
- `AgentEmailLogin`: the invitation email becomes an alias for a linked agent's
  canonical CRM username. Normal username, password, token/password-version,
  active-user, second-factor and tenant checks remain in the Espo authentication flow.
  Unknown/ambiguous/unlinked emails do not become login aliases.

### Chatwoot: branch `fix/agent-invitation-platform-identity`

- `AgentBuilder`: grant account-owning platform apps access to a **newly created**
  user, in the existing creation transaction. This matches Platform API provisioning.
- The existing-user invitation path does not change identity ownership. Review
  and repair existing users explicitly rather than claiming them by email.
- The Enterprise builder delegates to this core builder before applying its
  existing SAML behavior.

## Validation performed

- CRM: 374 existing unit tests, 1,362 assertions passed (Chatwoot module and
  core authentication); PHP syntax checks passed.
- Production-data dry run under a database **read-only transaction**, with all
  proposed inserts replaced by in-memory records: four active identities resolve;
  Haiane is rejected; unrelated team/installation and unknown email are rejected;
  Jocilene's first-reset import verifies her through the Account API.
- CRM email/username resolution exercised against a representative existing
  linked identity using a synthetic bcrypt password on an unsaved User clone.
  Case and surrounding whitespace work; wrong passwords and unlinked emails fail.
- Chatwoot: Ruby 3.4.4 syntax check passed. A standalone read-only Rails runner
  exercised the modified builder with proposed creates captured in memory:
  new agents inherit the account's app grants; existing users and unmanaged
  accounts do not receive new grants.
- Full Chatwoot RSpec execution remains pending: the available local container's
  bundle is incomplete (`ferrum-0.17.2`, `webrick-1.9.2` missing).

No deployed application files, user passwords, permissions or active flags were
changed during this investigation. Diagnostic source files were loaded only by
standalone CLI processes from temporary paths.

## Recovery procedure — still to execute

1. Deploy both branches through the normal release process. Rebuild CRM metadata
   so the Espo authentication implementation override is loaded.
2. Backfill the platform-app ownership of the four active reported users. Verify
   the current identities and account membership before applying the following
   Chatwoot Rails runner snippet. It is idempotent and refuses users belonging to
   any other account. This is a **write operation**, not part of the dry run above.

   ```ruby
   expected = {
     86 => 'miriangleise79@gmail.com',
     87 => 'laradamasceno686@gmail.com',
     93 => 'jocilenelemos22@gmail.com',
     94 => 'pcarv2006@gmail.com'
   }

   User.transaction do
     platform = PlatformApp.find(1)
     account = Account.find(9)
     raise 'Platform does not own account' unless
       platform.platform_app_permissibles.exists?(permissible: account)

     users = User.where(id: expected.keys).lock.to_a
     raise 'Missing users' unless users.size == expected.size

     users.each do |user|
       raise 'Identity changed' unless user.email == expected.fetch(user.id)
       raise 'Account membership changed' unless user.account_users.pluck(:account_id).sort == [account.id]

       platform.platform_app_permissibles.find_or_create_by!(permissible: user)
     end
   end
   ```

3. Let the membership sync job import the CRM identities, or perform the first
   reset after deployment; the signed password-sync callback can now import them
   immediately. Confirm `ChatwootUser.assignedUserId`, platform and membership
   match each expected CRM user and account.
4. Mirian, Lara and Jocilene need one final Chatwoot password reset after the
   repair, because the previously failed callbacks cannot replay their chosen
   plaintext password. Confirm the CRM callback succeeds and updates one user.
   Pedro should complete his invitation; SSO remains unavailable until confirmed.
5. Haiane requires a separate administrator decision about her inactive CRM
   account. If access is restored, include her current remote ID 90 in the same
   verified ownership/link repair and then complete a password reset.
6. User acceptance: set password from the email → attend → log out → log in by
   email and the same password → return to the same Chatwoot account through SSO.
   Repeat logout/login without issuing another reset. Also verify username login.

Until release, agents can use their **CRM username and CRM password** for CRM
login; a Chatwoot password reset currently does not set that CRM password. Their
Chatwoot SSO still requires the identity and platform-permission repair above.
For new invitations, create the CRM User with the correct primary email and
account team before inviting the agent in Chatwoot.
