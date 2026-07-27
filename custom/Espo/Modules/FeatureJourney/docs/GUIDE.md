# Journeys — product & operator guide

Reference for building, running, and securing **FeatureJourney** (Monostax CRM). Companion docs: [Announcement](./PRODUCT_ANNOUNCEMENT.md) · [Tutorial](./TUTORIAL.md) · [Features](./FEATURES.md).

---

## 1. Concepts

```
Journey (definition)
 ├─ Stages (Entry / Normal / Success / Exit)
 │   └─ Stage Actions (OnEnter / OnExit)
 ├─ Transitions (fromStage? → toStage + trigger + conditions)
 └─ Records (runtime enrollment of a target)
      └─ Record Logs (append-only ledger)
```

| Term | Meaning |
|---|---|
| **Journey** | Versioned-in-place definition: audience, goals, lifecycle status |
| **Stage** | A step; types Entry, Normal, Success, Exit |
| **Transition** | Rule that moves a record between stages (or enrolls when `fromStage` is empty) |
| **Stage Action** | Side effect when entering or leaving a stage |
| **Journey Record** | One enrollment cycle of one target in one journey |
| **Journey Record Log** | Immutable history row for a move |
| **Signal** | Named event code (often from TrackingEvent) evaluated against active records |
| **Goal** | Completion criterion (`goalEventCodes` and/or `goalEntityFilter`) |

**Targets (v1 enrollment):** Contact, Account, Lead.  
**Entity-change watch set:** Contact, Account, Lead, Opportunity (Opportunity is watchable but not an enrollment target).

---

## 2. Lifecycle of a Journey

```
Draft ──Activate──► Active ──Pause──► Paused
                      │                  │
                      │◄────Activate─────┘
                      │
                 Stop Enrollment (flag only)
                      │
                   Archive ──► Archived
```

| Status | Authoring | Engine |
|---|---|---|
| **Draft** | Full edit | No enrollment / progress |
| **Active** | Structure locked (server + UI) | Enrolls, timers, signals, actions |
| **Paused** | Structure editable again | No progress |
| **Completed** | Terminal success state (if used) | — |
| **Archived** | Cold storage | — |

**Detail actions** (Journey record UI): Activate · Pause · Stop Enrollment · Archive.  
API shape: `POST /api/v1/Journey/{id}/activate` (and `pause`, `stopEnrollment`, `archive`).

**Activate requirements:** at least one active **Entry** stage.

---

## 3. Enrollment & audience

Resolved like WhatsApp campaigns:

1. Members of **Target Lists** with relation `optedOut = false`
2. Plus **Manual Contacts** (when target type is Contact)
3. Minus **Exclude Target Lists**
4. Deduped per target

| Flag | Behavior |
|---|---|
| **continuousEnrollment** | Job `EnrollJourneyRecords` (* * * * *) picks up new audience members while Active |
| **allowReEnrollment** | After terminal status, a new row may be created with `cycleCount + 1` |

**Uniqueness:** `(journeyId, targetType, targetId, cycleCount, deleteId)` — soft-delete safe.  
**Invariant:** at most one non-terminal (Active/Paused) record per (journey, target).

Formula enrollment: `journey\enroll(JOURNEY_ID, TARGET_TYPE, TARGET_ID)`.

Per-tenant **enrollment rate limit** applies (best-effort fixed window).

---

## 4. Stages

| Field | Notes |
|---|---|
| order | Collection sort; kanban column order |
| stageType | Entry · Normal · Success · Exit |
| style | UI badge style |
| maxDuration | Period string (`3 days`); timers job can treat as SLA |
| isActive | Inactive stages skipped |

**Validation highlights**

- Single active Entry per journey
- Cannot remove/deactivate a stage that still holds active records
- Tenant + teams cascade from parent Journey

**Success / Exit:** executor completes the record; goals may increment **goalCount** when criteria match.

---

## 5. Transitions

| Field | Notes |
|---|---|
| fromStage | Empty = enrollment path |
| toStage | Required |
| priority | Lower wins (first match) |
| conditionsGroup | **Primary UX:** nested AND/OR rules (“Advance when”). Engine `wakeSources` / `eventCodes` / `waitPeriod` are **derived on save** |
| wakeSources | Multi OR wakes (hidden in builder UI; auto from rules + toggles) |
| triggerType | Legacy primary wake; auto-synced |
| eventCodes | Auto from event rules (signal dispatcher OR gate) |
| waitPeriod | Auto from shortest `elapsedInStage` rule |
| conditionsFormula | Restricted **MODE_CONDITION** (wins over group when set) |
| evaluatorClassName | Platform tier only (allow-listed) |

Matching always starts with **tenantId**. Cross-tenant events never enroll or move foreign records.

### 5.1 Builder UX vs engine

**Builders** only edit **Advance when** (`conditionsGroup`) plus optional checkboxes:
- Also when the person/record is edited → `entityChange` wake
- Also manual / API → `manual` wake

**Engine** still splits **wakes (when to re-check)** vs **conditions (whether to advance)**; `TransitionRulesCompiler` (server) and `feature-journey:helpers/transition-rules` (client) compile the tree.

| Rule leaf (UI) | Compiler effect |
|---|---|
| Event already happened (`eventHistory`) | signal wake + codes + stateful history match |
| This event just arrived (`currentSignal` / `anySignal`) | signal wake + codes + current code match |
| Data inside this event (`payloadPath`) | signal wake + payload match |
| Time in this step (`elapsedInStage`) | timer wake + waitPeriod |
| Person’s fields (`entityFilter`) | entityChange wake + filter |

| Engine wake | Queues eval when |
|---|---|
| **signal** | code in derived `eventCodes` |
| **timer** | `enteredStageAt + waitPeriod <= now`|
| **entityChange** | target afterSave |
| **manual** | operator / API / `journey\moveToStage` |

**Stateful multi-event example (builder):** AND of  
`eventHistory(email_replied)` + `eventHistory(link_clicked)` + `elapsedInStage(3 days)`  
→ signal+timer wakes; fires when all true regardless of order.

### 5.3 Execution path

1. Dispatcher or timer queues `ProcessJourneyTransition` job `{ journeyRecordId, transitionId, signal? }`
2. **Claim** record (conditional status/`claimedAt` update) — lost race = no-op
3. **Evaluate** conditions
4. **OnExit** actions on fromStage → move stage → **OnEnter** on toStage
5. Write **JourneyRecordLog**
6. Success/Exit / **goal** handling · counter increments · lifecycle events
7. Stale claims recovered by timers job

---

## 6. Stage actions

| Field | Notes |
|---|---|
| trigger | OnEnter · OnExit |
| type | Registry `app.journeyActionTypes` |
| params | JSON; type-specific |
| paramFormulas | Map of param → **MODE_CONDITION** formula (never sets `tenantId`). UI: n8n-style **fx** toggle on each setting. Keys may be top-level (`assignedUserId`) or `fields.<attribute>` for updateTarget. Variables: `$journeyRecordId`, `$journeyId`, `$stageId`, `$tenantId` (read). |
| formula | Dedicated field for `executeFormula` (**MODE_ACTION**) |
| order | Ascending within trigger |
| maxRetries | 0–5 in-request retries (does **not** re-queue whole transition) |
| continueOnError | If true, next actions still run after failure |
| isActive | Skip when false |

Actions run **inline** inside the claimed transition (preserve OnExit → move → OnEnter order).

### 6.1 Action catalog

| Type | Tier | Behavior |
|---|---|---|
| **createTask** | tenant | Task stamped with journey `tenantId` + teams |
| **createRecord** | tenant | Allow-listed entity types; stamp tenant + teams; field allow-list (`app.journeyCreateRecord`) |
| **createRelatedRecord** | tenant | Create on target link; same stamp + field allow-list |
| **sendEmail** | tenant | Required Group (`inboundEmailId`) or Personal (`emailAccountId`) SMTP — **never** system SMTP; recipient allow-checks via TenantGuard |
| **sendWhatsAppMessage** | tenant | Free-text via Chatwoot WhatsApp inbox (WAHA QR / Cloud / Coexistence). Optional Cloud **fallback Meta template** when 24h session window is closed. Soft-skips if no phone / WhatsApp opted-out. |
| **sendWhatsAppTemplate** | tenant | Approved Meta template + `parameterMapping` (Handlebars, same as WhatsAppCampaign). Cloud API / Coexistence inboxes only — not WAHA QR. |
| **notifyUser** | tenant | In-app notification (user must be in tenant) |
| **makeFollowed** | tenant | Stream follow for specified tenant users only |
| **updateTarget** | tenant | Field writes filtered by `app.journeyUpdateTarget` allow-list (+ Espo `cCustom*` prefix + Monostax CustomField bag merge) |
| **updateRelatedRecord** | tenant | Same field filter on related rows; skips foreign-tenant relations |
| **linkRecord** / **unlinkRecord** | tenant | Relate only when foreign entity is same-tenant |
| **applyAssignmentRule** | tenant | Round-Robin / Least-Busy inside a tenant team; `listReportId` blocked |
| **executeFormula** | tenant | Restricted formula MODE_ACTION |
| **sendHttpRequest** | tenant | URL must match `app.journeyHttpRequest.allowedUrlPrefixList`; private hosts blocked. Empty list = disabled |
| **recordTrackingEvent** | tenant | Lazy InternalEventRecorder if Tracking present |
| **runScript** | platform | Allow-listed class via InjectableFactory |

Missing optional modules → validate-on-save and/or runtime no-op with warning (`class_exists` guards).

Per-tenant **action rate limit** (best-effort).

**Configure HTTP egress:** set `app.journeyHttpRequest.allowedUrlPrefixList` (e.g. `["https://hooks.n8n.example/"]`) via custom metadata before enabling `sendHttpRequest` for tenants.

---

## 7. Restricted formula

`RestrictedFormulaRunner` parses scripts, **allow-lists the AST**, then executes via core Formula Manager (fail-closed).

| Mode | Used for | Side effects |
|---|---|---|
| **MODE_CONDITION** | transition `conditionsFormula`, action `paramFormulas` | None — no `journey\*` |
| **MODE_ACTION** | `executeFormula` action body | Only `journey\enroll`, `journey\moveToStage`, `journey\signal`, `journey\updateTarget` |

**Allowed families (both modes):** `string\`, `number\`/`numeric\`, `datetime\`, `logical\`, `comparison\`, `ifThen`/`ifThenElse`, `entity\attribute` (+ a few read-only entity helpers), `json\retrieve`, `object\get|create|clone`, selected `array\*`, etc.

**Blocked:** `record\*`, `ext\*`, bare `entity\set*`, `email\*`, `workflow\*`, `bpm\*`, `env\*`, `password\*`, arbitrary assignment writes, unknown function names.

### 7.1 Journey formula functions

| Function | Purpose |
|---|---|
| `journey\enroll(journeyId, targetType, targetId)` | Enroll target if journey Active |
| `journey\moveToStage(recordOrTarget…, stageId)` | Move enrollment (tenant-guarded) |
| `journey\signal(code, …)` | Emit signal into dispatcher |
| `journey\updateTarget(…)` | Allow-listed field patch; saves with `skipJourneyDispatch` |

All respect **TenantGuard** and **JourneyEffectDepth** (max nested effect depth **3**) to stop recursive enroll/move/signal storms.

Nested target saves from the engine pass **`skipJourneyDispatch`** so entityChange hooks do not re-enter infinitely.

### 7.2 CustomField bag (Monostax)

Do **not** confuse with Espo Entity Manager columns `cFoo` (`allowCustomFieldPrefix`).

| Use | How |
|---|---|
| Filter / goal | `entityFilter` attribute `customFields.<valueKey>` (e.g. `customFields.billing.plan`) |
| Read in formula | `object\get(entity\attribute('customFields'), 'billing.plan')` |
| Write (`updateTarget` / `journey\updateTarget`) | fields map key `customFields.<valueKey>` **or** nested `{ "customFields": { "billing.plan": "pro" } }` — server **merges** by valueKey (null removes key) |

Bag enabled entity types come from `app.customFields.entityTypeList` (Contact, Lead, Account, Opportunity). Flag: `app.journeyUpdateTarget.allowCustomFieldsBag`.

---

## 8. Goals & lifecycle events

**Goals**

- `goalEventCodes` — cheap set match in the signal dispatcher  
- `goalEntityFilter` — where-clause on target after each executed transition  

On match: complete record, `goalCount++`, emit goal lifecycle.

**Lifecycle codes** (via lazy Tracking `InternalEventRecorder`, never throws if Tracking absent):

- `journey_enrolled`
- `journey_stage_entered`
- `journey_completed`
- `journey_goal_reached`
- `journey_exited`

---

## 9. Journey Records & Logs

### Record

| Status | Meaning |
|---|---|
| Active | In progress |
| Paused | Held |
| Completed | Success path / goal |
| Exited | Exit stage / explicit exit / UI **Exit journey** |
| Failed | Action/transition dead-letter after retries |

Also: `currentStage`, `enteredStageAt`, `claimedAt`, `retryCount`, `cycleCount`, `exitReason`, parent `target` (linkParent).

**Remove a person:** do **not** “unlink” (target/journey are required). Open the **JourneyRecord** → action **Exit journey** (`POST JourneyRecord/{id}/exit`) → status `Exited`, `exitReason` default `manual`, counters + log (`firedBy=user`). Keeps history. Soft-**Delete** is permanent API wipe. Stage OnExit actions are **not** run on manual exit (same as max-duration SLA).

**Kanban:** grouped by `currentStageId`, ordered by stage `order`. **Read-only** — no drag-drop stage changes (use transitions / formula / manual trigger paths).

**List ACL:** access control filter limits rows to Journeys the user can read; primary filters Active / Completed / Failed.

### Log

Append-only (record API BlockWrite / BlockDelete). Engine writes via EntityManager. Fields include from/to stage, transition, `firedBy` (signal/timer/manual/formula/user/system), payload snapshot, actor.

---

## 10. Tenancy, ACL, trust tiers

| Layer | Mechanism |
|---|---|
| Tenant assignment | Teams → `Tenant.baseUserTeamId` (AssignTenantFromTeam); cascade to children |
| Ambiguous teams | Skip resolution with warning — never guess |
| Child ACL | JourneyRecord / JourneyRecordLog delegate to parent Journey |
| Roles | Seeded tenant-base (read) vs tenant-admin (author) scopes |
| Structure lock | ValidateStructure + dynamicLogic when not Draft/Paused |
| Platform tier | Superadmin + `app.journeyPlatformAllowList` for scripts/evaluators |
| Email recipients | TenantGuard allow-list semantics |
| Admin UI | `adminPanel` (platform) vs `adminForUserPanel` (tenant Configurations) |

**Trust model summary**

- **Tenant authors** configure journeys/actions within sandboxes.  
- **Platform operators** extend allow-lists and platform actions.  
- **System jobs** execute as system but always filter by tenant on read paths.

---

## 11. Jobs & operations

| Scheduled job | Cron | Responsibility |
|---|---|---|
| EnrollJourneyRecords | `* * * * *` | Continuous enrollment |
| ProcessJourneyTimers | `* * * * *` | Timers, maxDuration, stale-claim recovery |
| ReconcileJourneyCounters | `0 3 * * *` | Rebuild denormalized active/completed/exited/goal counts |

One-off: **ProcessJourneyTransition** via job scheduler from dispatcher/executor entry points.

Seeded on **rebuild** (`SeedScheduledJobs` + `ConfigureNavbar`). After deploy: `php command.php clear-cache && php command.php rebuild`.

**Counters** on Journey are denormalized (fast UI); trust reconcile job if crash-induced drift appears.

---

## 12. UI map

| Surface | Route / location |
|---|---|
| Journey list/detail | `#Journey` |
| Records | `#JourneyRecord` (+ kanban mode) |
| Logs | `#JourneyRecordLog` |
| Tenant Configurations | Configurations → Journeys group |
| Platform admin | Administration → Journeys |
| Navbar | Seeded via ConfigureNavbar (tenant sidenav) |

i18n ships **en_US** and **pt_BR**.

---

## 13. Extensibility

| Seam | How |
|---|---|
| New action type | Metadata `__APPEND__` on `app.journeyActionTypes` + implementation class + optional client `paramsView` |
| New trigger metadata | `app.journeyTransitionTriggers` |
| Update-target fields | `app.journeyUpdateTarget` per entity type |
| Platform classes | `app.journeyPlatformAllowList` |
| Watched entity types | Add thin afterSave hook → `EntityChangeDispatcher` |
| Optional modules | Lazy `class_exists` (Tracking) |

---

## 14. Explicit non-goals (v1)

- Kanban drag-to-move  
- Journey publish snapshots / immutable versions  
- Full drag-drop node canvas (Flow panel + typed action/condition forms ship in product UI)  
- Split/experiment stages, quiet hours, frequency caps (seams exist)  
- Opportunity as enrollment target  

---

## 15. Quick operator checklist

- [ ] Teams correctly map to Tenant  
- [ ] One Entry stage; Success/Exit defined  
- [ ] Audience lists populated; continuous enrollment intentional  
- [ ] Signal codes match TrackingEventType codes  
- [ ] Timer periods use supported period strings  
- [ ] Action types available in this deployment (Tracking)  
- [ ] Scheduled jobs enabled after rebuild  
- [ ] Tenant-admin can see Configurations → Journeys  
- [ ] After go-live, spot-check Logs + counters next morning (reconcile)  
