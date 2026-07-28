# Tutorial — your first Journey in 15 minutes

**Goal:** Enroll contacts from a Target List into a 3-stage nurture journey that waits two days, then creates a follow-up task unless a tracking signal arrives earlier.

**You need:** tenant-admin (or equivalent Journey edit rights), at least one Team linked to a Tenant, a Target List with Contacts, and (optional) FeatureTrackingEvent for signal demos.

---

## 1. Open Journeys

1. Sign in as a **tenant** or **tenant-admin** user.
2. Open **Configurations** (navbar).
3. Click **Journeys** — or go directly to `#Journey`.

Platform super-admins also see Journeys under **Administration**.

---

## 2. Create the journey shell

**Create Journey** and set:

| Field | Value |
|---|---|
| **Name** | `Nurture — Trial interest` |
| **Target Entity Type** | `Contact` |
| **Teams** | your tenant team(s) — Tenant is filled automatically |
| **Target Lists** | pick the list with trial leads |
| **Exclude Target Lists** | optional suppress list |
| **Continuous Enrollment** | ✓ if new list members should join while Active |
| **Allow Re-enrollment** | leave off for this tutorial |
| **Goal Event Codes** | e.g. `demo_booked` (only if Tracking is installed) |

Leave **status = Draft**. Save.

> After Draft/Active, structural fields (`targetEntityType`, audience links, many stage/transition edits) lock until you **Pause** (or stay Draft).

---

## 3. Add stages

On the journey detail → **Stages** panel → create three stages:

| Order | Name | Stage type | Style | maxDuration |
|---|---|---|---|---|
| 10 | `New` | **Entry** | primary | — |
| 20 | `Waiting` | Normal | default | `5 days` (SLA warning base) |
| 30 | `Done` | **Success** | success | — |

Rules to remember:

- Exactly **one active Entry** stage per journey.
- Enrollment always lands on Entry.
- **Success** / **Exit** complete the enrollment; goal matches enter the selected Success step.

Return to the journey's **Goal** panel and set **Goal success step** to `Done`. Goal-enabled journeys
cannot be turned on until an active Success destination is selected.

---

## 4. Enrollment transition (into Entry)

Transitions with **Applies to = New enrollment** are enrollment rules. Their From Stage stays empty.

Create transition:

| Field | Value |
|---|---|
| **Name** | `Enroll from audience` |
| **Applies to** | `New enrollment` |
| **From Stage** | *(empty)* |
| **To Stage** | `New` |
| **Trigger Type** | `manual` *(or leave simple — activation enrollment places targets on Entry directly)* |
| **Priority** | `10` |
| **Is Active** | ✓ |

Activation runs audience enrollment into the Entry stage; keep enrollment transitions tidy for formula/`journey\enroll` paths.

---

## 5. Timer: New → Waiting → task

### 5a. Transition after 2 days

| Field | Value |
|---|---|
| **Name** | `Wait 2 days` |
| **From** | `New` |
| **To** | `Waiting` |
| **Trigger** | `timer` |
| **Wait Period** | `2 days` |
| **Priority** | `10` |

Period format examples: `12 hours`, `3 days`, `1 week`.

### 5b. OnEnter action on Waiting

Open stage **Waiting** → **Actions**:

| Field | Value |
|---|---|
| **Trigger** | OnEnter |
| **Type** | `createTask` |
| **Order** | `10` |
| **Max Retries** | `1` |
| **Params** (JSON) | see below |

```json
{
  "name": "Follow up trial lead",
  "priority": "Normal",
  "dateStart": null
}
```

Optional **paramFormulas** (read-only formulas that fill params at runtime). In the action Settings UI, toggle **fx** on any text/link/field value (n8n-style) or use JSON:



```json
{
  "paramFormulas": {
    "description": "string\\concatenate('Auto task for ', entity\\attribute('name'))"
  }
}
```

---

## 6. Optional fast-path: signal to Success

If FeatureTrackingEvent is installed:

1. Ensure a Tracking Source can emit code `demo_booked` for contacts.
2. Add transition:

| Field | Value |
|---|---|
| **Name** | `Demo booked` |
| **Applies to** | `Any active step` for a journey-wide fast path, or `One specific step` |
| **From** | `New` when using one specific step; otherwise empty |
| **To** | `Done` |
| **Trigger** | `signal` |
| **Event Codes** | `demo_booked` |
| **Priority** | `5` *(lower number = earlier match)* |

3. On the journey, set **Goal Event Codes** = `demo_booked` so completions increment **Goals Reached**.

Conditions — use the **Conditions** builder (AND/OR + “Target matches filter”), or the equivalent **conditionsGroup**:

```json
{
  "and": [
    {
      "type": "entityFilter",
      "where": [
        {
          "type": "isFalse",
          "attribute": "emailAddressIsOptedOut"
        }
      ]
    }
  ]
}
```

---

## 6b. Prefer the Flow panel (recommended)

On the journey detail, open the **Flow** bottom panel:

1. **Add Stage** → set type Entry / Normal / Success / Exit.
2. On a stage card, **Add Action** → pick type (Create Task, Send Email, …). Forms are typed — no JSON required.
3. **Add Transition** → From/To stage pickers (scoped to this journey), trigger fields appear by type (event codes for signal, wait period for timer).
4. Conditions: visual builder for payload / entity filter / event history.

Raw JSON remains under **Advanced JSON** for power users.

## 7. Activate

On the journey detail action menu:

1. **Activate** → confirm.
2. Status becomes **Active**; **Activated At** is set.
3. Audience resolves (Target Lists minus exclusions + manual contacts, deduped).
4. Each contact gets a **Journey Record** on Entry (`cycleCount = 0`, status Active).
5. Counters: **Active** increases.

If activation fails: you need ≥1 Entry stage (and sensible structure). Fix in Draft/Paused and retry.

---

## 8. Watch it run

| Surface | What to check |
|---|---|
| Journey **Records** panel | enrollments, current stage, status |
| **Journey Records** list | primary filters Active / Completed / Failed |
| Record **Kanban** | columns by stage (read-only — no drag) |
| **Journey Record Logs** | append-only moves (from → to, firedBy) |
| Journey counters | Active / Completed / Exited / Goals Reached |
| Tasks | OnEnter `createTask` after the timer job fires |

Background jobs (seeded on rebuild):

| Job | Schedule | Role |
|---|---|---|
| Enroll Journey Records | every minute | continuous enrollment |
| Process Journey Timers | every minute | timers, SLA, stale claims |
| Reconcile Journey Counters | daily 03:00 | fix counter drift |

---

## 9. Operate day-to-day

| Action | Effect |
|---|---|
| **Pause** | Active → Paused; progress stops; structure editable again |
| **Activate** (from Paused) | resumes; re-runs enrollment pass |
| **Stop Enrollment** | turns off continuous enrollment; in-flight records keep running |
| **Archive** | terminal archive (use when the campaign is done) |

---

## 10. Variant exercises (10 more minutes)

### A. Entity-change branch

1. Trigger type **entityChange** from `Waiting` → `Done`.
2. Condition: contact `cLifecyclestage` (or your field) equals `Customer` via `entityFilter`.
3. Edit a contact in the journey and confirm the log + stage move (after save hook + job).

### B. Restricted formula action

OnEnter on `Done`, type **executeFormula**:

```
journey\updateTarget(entity\attribute('id'), 'Contact', object\create('description', 'Completed nurture journey'));
```

Only allow-listed target fields apply; `tenantId` cannot be set via param formulas.

### C. Manual move

Trigger **manual** + record action / formula `journey\moveToStage(...)` for operator overrides (still tenant-guarded).

---

## Troubleshooting cheatsheet

| Symptom | Likely cause |
|---|---|
| Activate rejected | Missing Entry stage |
| Nobody enrolled | Empty/opted-out lists, wrong target entity type, rate limit |
| Timer never fires | Job not running / waitPeriod not elapsed / journey Paused |
| Signal ignored | Code mismatch, wrong tenant, record not on `fromStage`, conditions false |
| Action Failed | See record retryCount / logs; maxRetries exhausted; rate limit |
| Kanban empty | Filter to one journey; no Active records |
| Formula error | Function not on allow-list / wrong mode (condition vs action) |

---

## Next reading

- **[Features](./FEATURES.md)** — full capability list  
- **[Guide](./GUIDE.md)** — triggers, actions, formula, security, jobs  
- **[Product announcement](./PRODUCT_ANNOUNCEMENT.md)** — positioning for stakeholders  
