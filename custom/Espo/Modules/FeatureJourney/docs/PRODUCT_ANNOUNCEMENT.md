# Announcing Journeys — multi-stage engagement & process automation

**Ship date:** July 2026 · **Module:** FeatureJourney v1.0 · **Audience:** every Monostax tenant

---

## The headline

You can now design **tenant-owned journeys**: audiences enroll, move through stages on events/timers/rule changes, run OnEnter/OnExit actions, and hit goals — fully isolated per tenant, with a safe formula sandbox and a full audit ledger.

Lifecycle automation and lightweight BPM live in one place, next to Contacts, Leads, Target Lists, and Tracking Events.

---

## Why this matters

Until now, engagement paths were either campaigns (send once) or opaque platform scripts. Tenants needed:

1. **Multi-step journeys** with clear stages (Entry → nurture → Success/Exit)
2. **Signals from the real world** (Tracking Events, CRM field changes, timers)
3. **Actions that stay tenant-safe** (tasks, email, notify, restricted formula — not arbitrary system writes)
4. **Visibility** (who is where, what fired, counters that reconcile)

**Journeys** deliver that stack without giving tenant authors the keys to the whole CRM.

---

## What you get on day one

| Capability | What it means for you |
|---|---|
| **Journey builder** | Draft → Activate → Pause → Stop enrollment → Archive |
| **Stages** | Entry, Normal, Success, Exit — ordered, styled, optional max duration (SLA) |
| **Transitions** | signal · timer · entityChange · manual · formula |
| **Conditions** | Nested AND/OR tree, entity filters, event history, or restricted formula predicates |
| **Stage actions** | OnEnter / OnExit: create task, email, notify, update target, execute formula, record tracking event · platform: run script / workflow / BPM |
| **Enrollment** | Target Lists + manual contacts + exclusions; continuous enrollment; optional re-enrollment cycles |
| **Goals** | Event codes and/or entity filters complete records as *goal reached* |
| **Kanban** | Read-only board of enrollments by current stage |
| **Configurations panel** | Tenant users open Journeys from Configurations (and navbar, when seeded) |
| **Audit** | Append-only Journey Record Logs for every move |

---

## Built for multi-tenant CRM

- Every journey belongs to a **Tenant** (derived from Teams) — cross-tenant signal matching never happens.
- Journey Records and Logs **delegate ACL** to the parent Journey.
- Formula and target updates run behind a **restricted formula runner**, **update-target allow-list**, **tenant guard**, and **effect-depth cap** (no runaway nested effects).
- Platform-only hooks (`runScript`, custom evaluators, Advanced workflow/BPM) require **superadmin + allow-lists**.

---

## Where to find it

| Role | Path |
|---|---|
| **Tenant / tenant-admin** | **Configurations → Journeys** · tabs **Journey** / **Journey Records** · detail **Kanban** on records |
| **Platform admin** | Administration → Journeys panel · scheduled jobs · platform allow-lists |

---

## How it plays with the rest of Monostax

- **FeatureTrackingEvent** — Tracking codes become journey *signals*; lifecycle codes (`journey_enrolled`, `journey_stage_entered`, `journey_completed`, `journey_goal_reached`) flow back into the event ledger when Tracking is installed.
- **Target Lists** — same audience model as WhatsApp / email campaigns.
- **Advanced (optional)** — `triggerWorkflow` / `startBpmnProcess` actions when Advanced is present.
- **Funnels / Opportunity stages** — complementary: Funnels drive sales stages; Journeys orchestrate *who does what next* across Contacts, Accounts, and Leads.

---

## Example journeys you can launch this week

1. **Lead nurture** — enroll new Target List members → wait 2 days → if no reply signal, create a task → success when `form_submitted`.
2. **Onboarding checklist** — entityChange when `status` flips → notify owner → timer SLA if stuck → exit on cancel.
3. **Event-driven upsell** — Tracking `pricing_viewed` → stage “hot” → restricted formula updates lead score → goal on `demo_booked`.

---

## What v1 deliberately leaves out

Visual drag-and-drop stage moves (kanban is **read-only** — use transitions), journey versioning/snapshots, quiet hours, A/B split stages, and a full visual condition builder (JSON conditions + formula in v1). Extension seams are already in the registries.

---

## Get started

1. Read the **[Tutorial](./TUTORIAL.md)** — build and activate your first journey in ~15 minutes.  
2. Skim **[Features](./FEATURES.md)** for the capability matrix.  
3. Keep **[Guide](./GUIDE.md)** open for reference (triggers, actions, formula, jobs, security).

Questions or rollout blockers → your Monostax success channel. We’re excited to see the first tenant journeys go Active.
