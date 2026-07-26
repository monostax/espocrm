# FeatureJourney

Multi-tenant **customer-engagement journeys** and lightweight **business-process control** for Monostax CRM.

Enroll Contacts, Accounts, or Leads into staged journeys; move them with signals, timers, entity changes, manual or formula rules; run OnEnter/OnExit actions; track goals — all tenant-isolated with a restricted formula sandbox.

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
| Soft peers | FeatureTrackingEvent (signals), Advanced (workflow/BPM actions) |

## Quick links (in-app)

- Tenant: **Configurations → Journeys** (`#Journey`)
- Records / kanban: `#JourneyRecord`
- Logs: `#JourneyRecordLog`
- Platform: Administration → Journeys panel

## Engine at a glance

```
Audience → JourneyRecord (Entry)
        → Transitions (signal | timer | entityChange | manual | formula)
        → OnExit actions → stage move → OnEnter actions
        → JourneyRecordLog + counters + optional Tracking lifecycle events
```

After deploy: `php command.php clear-cache && php command.php rebuild` (seeds jobs, navbar, roles).
