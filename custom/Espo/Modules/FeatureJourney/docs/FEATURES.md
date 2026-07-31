# Journeys — features

Capability matrix for **FeatureJourney** v1.0 (Monostax CRM).  
See also: [Announcement](./PRODUCT_ANNOUNCEMENT.md) · [Tutorial](./TUTORIAL.md) · [Guide](./GUIDE.md).

---

## At a glance

| Area | Highlights |
|---|---|
| **Builder** | Multi-stage journeys with Draft → Active → Pause → Archive lifecycle |
| **Audience** | Target Lists, manual contacts, exclusions, continuous + re-enrollment |
| **Movement** | signal · timer · entityChange · manual · formula transitions |
| **Actions** | OnEnter/OnExit registry (tasks, email, notify, formula, tracking, platform hooks) |
| **Safety** | Multi-tenant isolation, restricted formula, allow-lists, effect depth, rate limits |
| **Visibility** | Records, append-only logs, counters, filters, read-only kanban |
| **Ops** | Enrollment/timer/reconcile jobs, admin + tenant Configurations panels |

---

## 1. Journey definition

| Feature | Details |
|---|---|
| Named journeys | Required name, optional description |
| Status machine | Draft · Active · Paused · Completed · Archived |
| Lifecycle actions | Activate · Pause · Stop Enrollment · Archive (UI + REST) |
| Target entity types | Contact · Account · Lead |
| Teams + Tenant | Required teams; tenant auto-derived and read-only |
| Audience sources | Target Lists, manual Contacts, exclude lists |
| Continuous enrollment | Opt-in minute job while Active |
| Re-enrollment | Opt-in new `cycleCount` after terminal states |
| Goals | Multi-code event goals + entity where-filter goals routed through a selected Success step |
| Live counters | active · completed · exited · goals reached (nightly reconcile) |
| Timestamps | activatedAt · completedAt |
| Structural lock | Server validation when not Draft/Paused (+ client dynamicLogic) |

---

## 2. Stages

| Feature | Details |
|---|---|
| Ordered stages | Integer `order` |
| Stage types | Entry · Normal · Success · Exit |
| Single Entry rule | Validated on save |
| Styles | default / success / danger / warning / info / primary |
| SLA window | Optional `maxDuration` period string |
| Active flag | Soft-disable without delete |
| Cascade tenancy | tenant + teams from Journey |
| Guardrails | Block delete/deactivate when active records present |

---

## 3. Transitions

| Feature | Details |
|---|---|
| Transition scopes | `stage` · `journey` (any active stage) · `enrollment` |
| Graph edges | `stage` requires fromStage; `journey` and `enrollment` keep it empty |
| Enrollment edges | New records only; never reused as an any-stage fallback |
| Journey-wide edges | Existing Active records only; self-targets no-op |
| Priority | First-match-wins (ascending) |
| **wakeSources** | Multi OR: signal · timer · entityChange · manual (BC: empty → triggerType) |
| **signal** | Match eventCodes (OR gate); stateful multi-event AND via eventHistory |
| **timer** | `waitPeriod` from `enteredStageAt` (re-check queue) |
| **entityChange** | Contact, Account, Lead, Opportunity saves |
| **manual** | Operator / API / formula-driven |
| **formula** | Predicate-led (`conditionsFormula`); no automatic wake alone |
| conditionsGroup | AND/OR: payloadPath, currentSignal, entityFilter, eventHistory, elapsedInStage |
| conditionsFormula | Restricted read-only formula (overrides group) |
| Platform evaluator | Allow-listed custom class (superadmin) |
| Idempotent execute | DB claim on record; stale-claim recovery |

---

## 4. Stage actions

| Feature | Details |
|---|---|
| Triggers | OnEnter · OnExit |
| Execution order | Integer order within trigger |
| Inline runtime | Same job as transition (ordered semantics) |
| Retries | `maxRetries` 0–5 in-request |
| continueOnError | Proceed to next action after failure |
| Conditional guard | Visual `conditionsGroup` with nested AND/OR and fixed/**fx** values; optional `conditionFormula` advanced override |
| paramFormulas | Dynamic params via MODE_CONDITION formulas; n8n-style **fx** UI per setting (`fields.*` paths for updateTarget) |
### Action types

| Action | Tier | Description |
|---|---|---|
| createTask | tenant | Create Task linked/teamed; stamps tenantId + journey teams |
| createRecord | tenant | Generic create (allow-listed entity types); stamps tenant + teams; field allow-list |
| createRelatedRecord | tenant | Create on target relation; same stamp + field allow-list |
| sendEmail | tenant | Send via tenant Group/Personal SMTP account only (no system SMTP); recipient allow-checks; multi-email (all sendable on target) |
| sendWhatsAppMessage | tenant | Free-text WhatsApp via Chatwoot (WAHA QR / Cloud / Coexistence); optional Meta template fallback when 24h window closed; multi-phone |
| sendWhatsAppTemplate | tenant | Meta template + parameter mapping (WhatsAppCampaign style); Cloud/Coexistence only; multi-phone |
| enrollToWhatsAppCampaign | tenant | Enroll Contact into a running WhatsApp Campaign (Sending); multi-phone; schedules campaign chunks |
| notifyUser | tenant | In-app user notification (user must be in tenant) |
| makeFollowed | tenant | Stream follow for specified tenant users only |
| updateTarget | tenant | Patch target fields via allow-list + c* columns + CustomField bag merge |
| updateRelatedRecord | tenant | Patch related records (same-tenant only) via same field allow-list |
| linkRecord | tenant | Relate target ↔ foreign only when foreign is same-tenant |
| unlinkRecord | tenant | Unrelate with same-tenant foreign assert |
| applyAssignmentRule | tenant | Round-Robin / Least-Busy within a tenant team; listReportId blocked |
| executeFormula | tenant | Restricted MODE_ACTION formula |
| sendHttpRequest | tenant | HTTP egress; URL prefix allow-list + SSRF host blocks (empty prefix list = disabled) |
| recordTrackingEvent | tenant | Emit Tracking event when module present |
| runScript | platform | Allow-listed PHP invokable |

---

## 5. Runtime enrollments (Journey Record)

| Feature | Details |
|---|---|
| linkParent target | Contact / Account / Lead |
| Statuses | Active · Completed · Exited · Failed · Paused |
| Stage pointer | currentStage + enteredStageAt |
| Worker claim | claimedAt for exclusive processing |
| retryCount | Failure / action retry accounting |
| cycleCount | Re-enrollment generation |
| exitReason | Human/system reason string |
| Unique per cycle | Soft-delete-safe unique index |
| Primary filters | Active · Completed · Failed |
| List ACL filter | Only records of accessible Journeys |
| **Kanban** | Columns by stage; **read-only** board |
| Relationship panels | On Journey detail |

---

## 6. Audit ledger (Journey Record Log)

| Feature | Details |
|---|---|
| Append-only | UI/API update & delete blocked |
| Stage delta | fromStage · toStage |
| Provenance | transition · firedBy · actor · eventPayload |
| Reporting indexes | by record+time, journey+time |
| Engine write path | EntityManager (hooks do not block jobs) |

---

## 7. Signal & automation engine

| Feature | Details |
|---|---|
| Tenant-first dispatch | All match queries constrained by tenantId |
| Tracking integration | afterSave hook → dispatcher (optional module) |
| Email replied | `email_replied` via inbound Email threaded to journey outbound (Message-ID token) |
| WhatsApp replied | `whatsapp_replied` via inbound Chatwoot message / DeliveryWebhook on journey-stamped conversation |
| Goal fast-path | goalEventCodes short-circuit |
| Entity change cache | O(1) “any listeners?” pre-check before work |
| Loop prevention | `skipJourneyDispatch` on engine saves |
| Effect depth | Max 3 nested journey side-effects |
| Lifecycle emit | Lazy Tracking recorder; never throws |
| Rate limits | Per-tenant actions/min & enrollments/min (best-effort) |

---

## 8. Formula platform

| Feature | Details |
|---|---|
| AST allow-list sandbox | Parse-time fail-closed gate |
| MODE_CONDITION | Pure predicates & param formulas |
| MODE_ACTION | + journey\enroll \| moveToStage \| signal \| updateTarget |
| Global formula map | Functions registered in `app.formula` (unsafe) |
| Blocked dangerous APIs | record\*, ext\*, entity\set\*, workflow\*, bpm\*, … |
| TenantGuard in functions | Same-tenant asserts on every mutation |

---

## 9. Security & multi-tenancy

| Feature | Details |
|---|---|
| Team → Tenant assignment | AssignTenantFromTeam pattern |
| Cascade hooks | Stage, transition, action, record |
| Delegated ACL | Record & Log → parent Journey |
| Role seeding | tenant-base read vs tenant-admin author |
| Platform allow-lists | Scripts + evaluators metadata lists |
| updateTarget allow-list | Per-entity field lists + c* prefix + CustomField bag (`customFields.<valueKey>`) |
| createRecord allow-list | `app.journeyCreateRecord.entityTypeList` + fields; always stamp tenantId + tenant teams |
| Related / link guards | Foreign entities must `entityBelongsToTenant`; skip foreign rows |
| Assignment | Team must be in tenant teams; report-based assignee blocked |
| HTTP egress | `app.journeyHttpRequest` prefix allow-list + blocked hosts/private IPs (fail-closed) |
| Email recipient checks | Tenant-safe destination filtering |
| Ambiguity policy | Skip ambiguous tenant resolution (no cross-tenant guess) |

---

## 10. UI & product surfaces

| Feature | Details |
|---|---|
| Tenant Configurations | `adminForUserPanel` group: Journeys, Records, Logs |
| Platform admin panel | `adminPanel` entries for operators |
| Navbar seeding | ConfigureNavbar rebuild action |
| Detail action handlers | Journey lifecycle + JourneyRecord **Exit journey** (`POST …/exit`) |
| Layouts | list / detail / side panels / kanban layout |
| i18n | en_US · pt_BR (labels, tooltips, messages, Configurations copy) |
| Kanban service wire-up | journeyId extraction in Global KanbanService |

---

## 11. Background jobs

| Job | Schedule | Feature |
|---|---|---|
| EnrollJourneyRecords | every minute | Continuous audience sync |
| ProcessJourneyTimers | every minute | Timers, SLA/maxDuration, stale claims |
| ProcessJourneyTransition | on demand | Evaluate + execute one transition |
| ReconcileJourneyCounters | daily 03:00 | Counter truth-up |

Jobs registered via metadata + `SeedScheduledJobs` rebuild.

---

## 12. Extensibility features

| Feature | Extension point |
|---|---|
| Custom action types | Metadata registry + PHP class |
| Custom client param UIs | `paramsView` per type |
| Additional watched entities | Thin afterSave hook |
| Platform scripts | Allow-list + runScript |
| Custom transition evaluators | Allow-list + evaluatorClassName |
| Cross-module signals | TrackingEvent codes / journey\signal |

---

## 13. Feature flags / deployment shape

| Item | Notes |
|---|---|
| Module order | 29 (after FeatureTrackingEvent 28) |
| jsTranspiled | Client module `feature-journey` |
| Optional hard deps | None — Tracking is soft |
| Rebuild required | clear-cache + rebuild seeds jobs/navbar/roles |

---

## 14. Not in v1 (tracked seams)

| Not included | Seam for later |
|---|---|
| Drag-and-drop kanban moves | Manual/formula transitions |
| Journey versioning | Definition is live-edited under lock |
| Full node canvas (n8n graph) | Flow panel + typed forms + condition builder |
| A/B split & holdout stages | stageType enum extension |
| Quiet hours / frequency caps | ActionRunner gate |
| Opportunity enrollment | targetEntityType enum |
| Per-action async jobs | ActionRunner service boundary |

---

## 15. Acceptance snapshot (v1 done means)

- [x] Six entities with layouts, i18n, scopes  
- [x] Enrollment + continuous job + re-enrollment cycles  
- [x] Signal / timer / entityChange / manual / formula paths  
- [x] Action registry with tenant + platform tiers  
- [x] Restricted formula + journey\* functions  
- [x] Tenant ACL, select filters, append-only logs  
- [x] Admin + adminForUser panels, navbar seed  
- [x] Counter reconcile job  
- [x] Read-only JourneyRecord kanban  
- [x] Unit coverage for core guards/formula/actions (module tests)  
