# FeatureJourney

Multi-tenant **customer-engagement journeys** and lightweight **business-process control** for Monostax CRM.

Enroll Contacts, Accounts, Leads, or Opportunities into staged journeys; move them with signals, timers, entity changes, manual or formula rules; run OnEnter/OnExit actions; track goals — all tenant-isolated with a restricted formula sandbox.

## Product docs

| Doc | Audience | Description |
|---|---|---|
| [**PRODUCT_ANNOUNCEMENT.md**](./docs/PRODUCT_ANNOUNCEMENT.md) | Stakeholders / GTM | Launch narrative, value prop, day-one surface map |
| [**TUTORIAL.md**](./docs/TUTORIAL.md) | Tenant admins | Build & activate a 3-stage nurture journey in ~15 minutes |
| [**GUIDE.md**](./docs/GUIDE.md) | Operators / builders | Full reference: lifecycle, triggers, actions, formula, jobs, security |
| [**FEATURES.md**](./docs/FEATURES.md) | Product / QA | Capability matrix and v1 acceptance snapshot |

## Module

| | |
|---|---|
| Version | 1.0.0 |
| Order | 29 |
| Namespace | `Espo\Modules\FeatureJourney` |
| Client | `client/custom/modules/feature-journey/` |
| Soft peers | FeatureTrackingEvent (signals) |

## Quick links (in-app)

- Tenant: **Configurations → Journeys** (`#Journey`)
- Records / kanban: `#JourneyRecord`
- Logs: `#JourneyRecordLog`
- Platform: Administration → Journeys panel

## Chatwoot record relationships

The **Relationships → Journeys** group shows `JourneyRecord` enrollment cycles for
Contacts, Accounts, Leads, and Opportunities. Its link picker lists active journeys
whose `targetEntityType` matches the current record, excluding live enrollments and
previous cycles when re-enrollment is disabled. Linking calls the enrollment engine
immediately; it does not change the journey's manual audience. Unlinking exits a
cycle without deleting it or running stage OnExit actions. Historical cycles remain
visible, and bulk unlink exits each selected eligible cycle independently.

The Chatwoot module serves `JourneyRelationships/:type/:targetId` (GET list / POST
enroll), `/options` (GET), and `/:id` (GET details / DELETE exit), with `accountId`
identifying the Chatwoot workspace. Each request resolves authenticated workspace
membership and target tenancy/read access. Queries enforce tenant and record ACL;
enrollment additionally requires target edit and JourneyRecord create/link-field
permissions. Exit requires target edit, enrollment edit, and status-field edit.
Read responses respect field-level restrictions and never accept client-provided
tenant, target overrides, stage, status, or run-as identity.

After deploying the new routes, clear the CRM cache. Focused security coverage:
`php phpunit.phar --do-not-cache-result tests/unit/Espo/Modules/Chatwoot/Services/JourneyRelationshipsTest.php`.

## Engine at a glance

```
Audience → JourneyRecord (Entry)
        → Transitions (signal | timer | entityChange | manual | formula)
        → OnExit actions → stage move → OnEnter actions
        → JourneyRecordLog + counters + optional Tracking lifecycle events
```

## Run-as User / ACL

Journeys never run as the system user for ACL/authorship. Each enrollment snapshots a **runAsUser**:

| Source | When |
|--------|------|
| Journey.`runAsUser` | Preferred |
| Activating clicker | Review & Publish when no runAs configured and user is eligible |
| Journey.`createdBy` | Fallback if still an active eligible user |

- `JourneyRecord.runAsUserId` is durable for transitions / OnEnter–OnExit jobs.
- Entity filters use `SelectBuilder::forUser` + access-control filter.
- Create-record actions stamp `createdById` from the actor; TenantGuard still applies.

### Who can be selected

The `runAsUser` field tooltip is intentionally one line; the full rule lives here.

| Editing user | Selectable users |
|--------------|------------------|
| Espo admin | Any user |
| Tenant admin | Users in the workspace |
| Regular user | Themselves only |

Required unless `createdBy` is still an active eligible user. Snapshotted onto each
`JourneyRecord` at enroll time. Enforced by `Hooks/Journey/ValidateRunAsUser.php`.

After deploy: `php command.php clear-cache && php command.php rebuild` (seeds jobs, navbar, roles).
