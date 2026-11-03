# FeatureSelfService

Self-service workspace provisioning. This module has no public HTTP surface
yet; signup, email verification and social sign-in come next and call
`Provisioner`. Plan: `theworkspace/work/01 __ this-week/Self-Service PLG/`.

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

Unit: `tests/unit/Espo/Modules/FeatureSelfService`, plus
`Chatwoot/Hooks/ChatwootAccount/SyncWithChatwootResumeTest` and
`Global/Tools/Tenant/TenantAdminTeamProvisionerTest`.

Integration: `tests/integration/Espo/Modules/FeatureSelfService` runs the
real hooks and schema against a disposable database and
`fake-chatwoot.php`, a fake Chatwoot Platform API with fault injection. In an
isolated install (MariaDB on a private Docker network, CRM installed from this
checkout with `bin/command config:populate`, the `database.*` config and
`rebuild`):

```sh
FAKE_CHATWOOT_STATE=/tmp/fake.json php -S 0.0.0.0:8080 tests/integration/Espo/Modules/FeatureSelfService/fake-chatwoot.php
SELF_SERVICE_TEST_DATABASE=<db> FAKE_CHATWOOT_URL=http://<fake>:8080 \
    php phpunit.phar tests/integration/Espo/Modules/FeatureSelfService/ProvisioningTest.php
```

It covers:
- the full workspace, and a rerun that changes nothing;
- same-named tenants getting separate teams;
- dedupe conflicts;
- resuming after a concierge failure, and after a lost reply;
- pause and resume, and the switch turned off;
- refusing an instance-admin owner;
- leaving another worker's lease alone;
- replacing a deleted team under its key;
- the scheduled job.
