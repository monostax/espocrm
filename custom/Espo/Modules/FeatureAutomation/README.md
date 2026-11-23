# FeatureAutomation

Batch + Machine automations with FeatureJourney security spine.

**v0.3.0** — fan-in join, windowed groupBy, action/trigger idempotency, dry-run simulate.

## Entities

- **Automation** — definition (`kind`, `triggerType`, `scheduling`, `definition` JSON, `triggerDebouncePeriod`, `runAsUser`)
- **AutomationRun** — one execution (`parentRun` / `parentRunItemId` for children, `runAsUser` snapshot)
- **AutomationRunItem** — per-target ledger (claim / Waiting / Done / Failed)
- **AutomationActionReceipt** — durable idempotency / debounce keys

## Batch stages (map waves + scope)

Batch definitions are a **linear `stages[]` pipeline**. Map stays a set materializer; actions never nest under map steps.

| `scope` | Role |
|---------|------|
| **`forEach`** | Materialize `map[]` → one run-item per target → run `actions[]` **N×** |
| **`once`** | After prior stage items are terminal, create **one** synthetic item and run `actions[]` **1×** (Slack “done”, digests). Payload gets `_run.{itemCount,doneCount,failedCount}` |

```json
{
  "kind": "batch",
  "stages": [
    {
      "id": "wave1",
      "scope": "forEach",
      "map": [
        { "id": "t", "mode": "primary", "source": "query", "entityType": "Tenant", "where": {} }
      ],
      "actions": [
        { "type": "sendWhatsAppMessage", "params": { "body": "Hi" } }
      ],
      "onFailure": [],
      "itemMode": "allMatching"
    },
    {
      "id": "summary",
      "scope": "once",
      "actions": [
        {
          "type": "notifyUser",
          "paramFormulas": {
            "message": "string\\concatenate('done=', number\\format(object\\get(object\\get($payload, '_run'), 'doneCount')))"
          }
        }
      ]
    }
  ],
  "limits": { "maxItems": 5000, "maxExpandPerParent": 200 }
}
```

**Legacy** flat `{ "map", "actions", "onFailure", "itemMode" }` is still accepted and expands to a single `forEach` stage on validate.

Runtime: `AutomationRun.stageIndex` advances when open items hit zero; multi-wave `forEach` rematerializes with unique items keyed by `(runId, stageId, targetType, targetId)`.

### Batch map (general-purpose loop)

`map[]` is a pipeline. Each step has:

| Field | Meaning |
|-------|---------|
| **mode** | Row geometry: `primary` · `expand`/`loop` · `passThrough` · `groupBy` |
| **source** | Where members come from (independent of reports) |
| **parent** | Prior step id (required for `relation` / `linkMultiple`) |
| **entityType** | Target entity (optional only for pure payload-value loops) |

### Sources

| source | Members |
|--------|---------|
| `query` | RDB `where` (+ optional parent FK) |
| `relation` | Parent relation name (`relation`) |
| `linkMultiple` | Parent link-multiple field (`link`) |
| `ids` | Explicit `ids[]` or `idsPath` into row payload |
| `payload` | Array at `payloadPath` (entities or scalar values) |
| `report` | Advanced List Report rows (`reportId`) — **one optional source**, not the loop model |

Legacy `mode: "report"` still works → rewritten to `source: report`.

### groupBy + time buckets + aggregates

```json
{
  "id": "g",
  "mode": "groupBy",
  "source": "query",
  "entityType": "Opportunity",
  "groupBy": ["assignedUserId"],
  "groupTargetEntityType": "User",
  "timeBucket": { "field": "closeDate", "size": "1 day", "timezone": "America/Sao_Paulo" },
  "aggregates": [
    { "op": "count", "as": "count" },
    { "op": "sum", "field": "amount", "as": "amountSum" },
    { "op": "collectIds", "as": "ids" }
  ]
}
```

Payload step blob: `{ groupKey, bucketStart, bucketEnd, count, ids, …aggs }`.

### Example — fan-out users (relation loop)

```json
{
  "kind": "batch",
  "map": [
    { "id": "t", "mode": "primary", "source": "query", "entityType": "Tenant", "where": {} },
    {
      "id": "u",
      "mode": "expand",
      "source": "relation",
      "parent": "t",
      "entityType": "User",
      "relation": "users",
      "where": { "isActive": true }
    }
  ],
  "actions": [
    {
      "type": "sendWhatsAppMessage",
      "enabled": true,
      "idempotencyKey": "digest|{targetId}|{type}",
      "debounce": "20 hours",
      "params": { "chatwootInboxId": "…", "body": "Resumo" }
    }
  ],
  "limits": { "maxItems": 5000, "maxExpandPerParent": 200 }
}
```

## Item payload bag (variables)

Each `AutomationRunItem` carries a JSON **`payload`** shared across map steps and actions (like n8n node data — **not** global admin `$vars`).

Writers:

| Mechanism | What it stores |
|-----------|----------------|
| Map step id | `payload.<stepId>` (entity/group blobs) |
| **`runReport`** | Full List/Grid result at `params.as` (default `report`) |
| **`setPayload` / `assign`** | Arbitrary path e.g. `vars.digest` |
| **`exportToRunBag`** / `stage.exportToRunBag` | Cross-stage **run bag** (`AutomationRun.dataBag`) |

Readers:

- Action `paramFormulas` and `when` get `$payload`, `$tenantId`, `$automationId`, `$runItemId`
- Machine transition `when` uses the same variables (+ `$state` when present)
- Map step `whereFormulas.<field>` resolve filter values at materialize time (`$payload` from prior steps + parent entity as formula target)
- Prefer `object\get($payload, 'report')` / nested `object\get`

### Cross-stage run bag (opt-in)

Payload is **per item** and does **not** flow to the next stage by default. To share data (e.g. runReport → WhatsApp on a later wave):

1. **Export** on the compute stage when it finishes:
   - Stage flag `exportToRunBag: true | ["report","conversations"] | { keys, from: "lastDone"|"firstDone", mode: "merge"|"replace" }`
   - Or action `exportToRunBag` mid-stage
2. **Import** on a later stage when items are created:
   - Stage flag `importRunBag: true | ["report"] | { keys, into: "root"|"runBag", overwrite: false }`
   - Flattened keys land on `$payload` (default `into: root`) plus snapshot `$payload._runBag`

Bag limits: 512 KiB JSON, 64 public keys. Reserved `_…` roots are never exported.

```json
{
  "kind": "batch",
  "stages": [
    {
      "id": "compute",
      "scope": "forEach",
      "map": [
        { "id": "t", "mode": "primary", "source": "query", "entityType": "Tenant", "where": { "name": "Monostax" } }
      ],
      "exportToRunBag": ["report", "conversations", "leadTime", "engagements", "afterHours"],
      "actions": [
        { "type": "runReport", "params": { "reportId": "…", "as": "report", "period": "previousDay" } }
      ]
    },
    {
      "id": "notify",
      "scope": "forEach",
      "importRunBag": true,
      "map": [
        { "id": "t", "mode": "primary", "source": "query", "entityType": "Tenant", "where": {} }
      ],
      "actions": [
        {
          "type": "sendWhatsAppMessage",
          "paramFormulas": {
            "body": "object\\get(object\\get(object\\get($payload, \"report\"), \"totals\"), \"COUNT:id\")"
          }
        }
      ]
    }
  ]
}
```

Example chain (same stage only):

```json
{
  "kind": "batch",
  "map": [
    { "id": "t", "mode": "primary", "source": "query", "entityType": "Tenant", "where": {} }
  ],
  "actions": [
    {
      "type": "runReport",
      "params": {
        "reportId": "6a04cd20e0add8aa9",
        "as": "report",
        "mode": "auto",
        "period": "currentWeek",
        "periodField": "createdAt",
        "timezone": "America/Sao_Paulo"
      }
    },
    {
      "type": "setPayload",
      "params": { "path": "vars.digest" },
      "paramFormulas": {
        "value": "string\\concatenate('Semana total: ', number\\format(object\\get(object\\get(object\\get($payload, 'report'), 'totals'), 'count')))"
      }
    },
    {
      "type": "sendWhatsAppMessage",
      "params": { "chatwootInboxId": "…" },
      "paramFormulas": {
        "body": "object\\get(object\\get($payload, 'vars'), 'digest')",
        "phone": "…"
      }
    }
  ]
}
```

Builder: action text fields, action **when**, Machine transition **when**, and map filter **values** support Journey-style **fx** expression inputs. Action formulas go on `action.paramFormulas`; map value formulas go on `map[].whereFormulas`.

## Fan-in (join)

1. `startChildAutomation` records each child on parent item `payload._pendingJoins[]` and sets `AutomationRun.parentRunItemId`.
2. Await with either:
   - **action** `waitJoin` `{ mode: "waitAll"|"waitAny", timeoutPeriod: "2 hours" }`, or
   - **machine state** `type: "join"` + `joinMode` + `timeoutPeriod`.
3. Child terminal → `JoinCoordinator` wakes parent (`Retry`). Timeout without satisfaction → Failed.

```json
{
  "kind": "machine",
  "initial": "Spawn",
  "states": [
    {
      "id": "Spawn",
      "type": "normal",
      "onEnter": [
        { "type": "startChildAutomation", "params": { "automationId": "CHILD_A" } },
        { "type": "startChildAutomation", "params": { "automationId": "CHILD_B" } }
      ]
    },
    { "id": "Await", "type": "join", "joinMode": "waitAll", "timeoutPeriod": "2 hours" },
    { "id": "Done", "type": "final", "onEnter": [{ "type": "notifyUser", "params": { "message": "kids done" } }] }
  ],
  "transitions": [
    { "from": "Spawn", "to": "Await" },
    { "from": "Await", "to": "Done" }
  ]
}
```

Re-entry safe: `startChildAutomation` skips if the same `automationId|entityType|entityId` on `_pendingJoins`.

Depth capped at 3.

## Idempotency & debounce

| Layer | How |
|-------|-----|
| **Action** | `enabled: false` pauses the node (config kept; runtime log `skipped_disabled`). Default `true` when omitted. Builder: Pause / Resume next to Remove. |
| **Action** | `idempotencyKey`: `true` (auto) · template `{type}` `{targetId}` … · or `=formula` · optional `debounce` period |
| **Trigger** | Automation.`triggerDebouncePeriod` e.g. `5 minutes` on entityChange/signal |

Claims stored in `AutomationActionReceipt` (unique `automationId+keyHash`). Failed actions release the claim.

## Simulate (dry-run)

`POST /Automation/:id/simulate` with optional `{ triggerPayload, maxPreview }`.

- Materializes map (or machine subject)
- Evaluates `when` + `paramFormulas`
- **Never** dispatches side-effect actions
- Machine: walks transitions; logs `would_wait` / `would_wait_join`

UI: detail action **Simulate**.

## Machine (states + waits + join)

```json
{
  "kind": "machine",
  "initial": "Notify",
  "states": [
    { "id": "Notify", "type": "normal", "onEnter": [{ "type": "notifyUser", "params": { "message": "Hi" } }] },
    { "id": "Wait", "type": "wait", "waitPeriod": "1 day" },
    {
      "id": "WaitAbsolute",
      "type": "wait",
      "waitUntil": "2026-08-01 09:00:00",
      "waitUntilTimezone": "America/Sao_Paulo"
    },
    { "id": "Done", "type": "final" }
  ],
  "transitions": [
    { "from": "Notify", "to": "Wait" },
    { "from": "Wait", "to": "WaitAbsolute" },
    { "from": "WaitAbsolute", "to": "Done" }
  ]
}
```

Requires trigger `entityType` + `entityId`. Waits → item `Waiting` + `wakeAt`.

What `onEnter` / `onExit` actions write to the item payload (setPayload values, verification
results, `_pendingJoins`, …) stays on it when the state's actions succeed. The machine's own
bookkeeping (`_state`, `_machineLog`, `_machineSteps`, `_onEnterDone`, `_enteredStateAt`,
`_pendingTo`, `_wakeAt`, `_join*`) always comes from the machine.

### Wait modes (machine)

| Spec | Meaning |
|------|---------|
| `waitPeriod` | Relative duration from now (`1 day`, `30 minutes`) |
| `waitUntil` | Absolute datetime (`Y-m-d H:i:s`, ISO-8601, or date-only) |
| `waitUntilTimezone` | IANA zone when `waitUntil` has no offset (default UTC) |
| `waitUntilFormula` | Formula returning a datetime (evaluated at enter / transition) |

Exactly one of `waitPeriod` **or** `waitUntil`/`waitUntilFormula` on wait states. Same pair allowed on transitions. Past `waitUntil` → no park (elapsed).

### Action `waitUntil` (batch + machine onEnter/onExit)

Parks the item until an absolute clock time (reuses wake poll):

```json
{
  "type": "waitUntil",
  "params": { "at": "2026-08-01 14:00:00", "timezone": "America/Sao_Paulo" },
  "paramFormulas": {
    "at": "entity\\attribute('closeDate')"
  }
}
```

Resume re-evaluates; if the datetime is past, the action is a no-op and the list continues.

## Run-as User / ACL

Automations never run as the system user. Each run snapshots a **runAsUser**:

| Source | When |
|--------|------|
| Automation.`runAsUser` | Preferred for schedule / entityChange / signal |
| Active `createdBy` | Fallback if runAs unset |
| Clicking user | Manual Run Now / Simulate when runAs unset |

- Materialize (map queries, reports) and Machine subject filters use that user's ACL via `SelectBuilderFactory::forUser` + access-control filters.
- Action create/update authorship uses `$context->actor` (not `ApplicationUser::setUser`).
- TenantGuard filters still apply alongside ACL.
- `AutomationRun.runAsUserId` is the durable snapshot for chunk jobs.

### Who can be selected

The `runAsUser` field tooltip is intentionally one line; the full rule lives here.

| Editing user | Selectable users |
|--------------|------------------|
| Espo admin | Any user |
| Tenant admin | Users in the workspace |
| Regular user | Themselves only |

`runAsUser` is **required** for `schedule` / `entityChange` / `signal` triggers unless
`createdBy` is still an active eligible user. Manual **Run Now** uses `runAsUser` when set,
otherwise the clicking user. Enforced by `Hooks/Automation/ValidateRunAsUser.php`.

## Triggers

| triggerType | Behavior |
|-------------|----------|
| manual | Run Now |
| schedule | cron / `daily:HH:MM` / `every:Nmin` |
| entityChange | Opportunity, Contact, Lead, Account (+ optional debounce) |
| signal | `AutomationEventDispatcher::dispatchSignal` |

entityChange and signal runs only start workspace automations whose workspace is the record's
own. Workspace-less (instance) and `crossTenant` automations still react to every record.

### Signal codes

| code | Subject | Payload (`$payload._trigger`) |
|------|---------|-------------------------------|
| `enrichment.found` | Contact | `enrichment.{attemptId, product: email\|phone, provider, index}` |

`enrichment.found` fires once per email or phone that enrichment found and charged for a Contact
(single-record and bulk), after the purchase commits (`FeatureCredits\Enrichment\FoundResults`).
It never carries the value: actions resolve it from the encrypted receipt as the run-as user.
Nothing is decrypted or dispatched while no Active automation listens to the code.

## Contact verification blocks

Pausing actions throw `ActionWaiting(wakeAt)`. The runner parks the item (`Waiting` + `wakeAt`,
idempotency claim released) and, on wake, runs the action list again from the top. So these
actions are re-entrant: what they started and decided lives in the item payload.

| Action | Does | Writes |
|--------|------|--------|
| `verifyContactChannel` | One paid check as the run-as user: `whatsapp_registration` (2 credits), `phone_identity` (Twilio, 8), `email_deliverability` (2). `source`: `enrichmentResult` (default) or `contactPrimary`. Parks while the check runs (~20 s polls, gives up after 15 min) and on workspace rate limits. No credits → item fails. | `verification.<as>` = the outcome, or `not_applicable` / `unavailable` / `failed`; `verificationInfo.<as>` |
| `addChannelIdentity` | Adds the found value to the Contact through the record service (edit + field ACL, channel-identity hook). `phone` = phone list + WhatsApp identity, `email` = email list + email identity, `whatsapp` = identity only. Never adds a value the person rejected. | `channelIdentity.<as>` = `added` / `already_present` / `not_applicable` / `invalid` / `rejected` / `conflict` / `unavailable` |

Checks reuse fresh evidence for free and join a check already running for the same value, like
the inbox. The run-as user needs the enrichment-credits permission and Contact edit access.

Example: verify every found value, add emails that are deliverable and phones that are on
WhatsApp and pass Twilio (Batch kind, trigger `signal`, codes `["enrichment.found"]`, subject
`Contact`; a Machine with the same actions in one state's `onEnter` works too). This is the live
Monostax automation:

```json
{
  "kind": "batch",
  "limits": { "maxItems": 1, "maxExpandPerParent": 1 },
  "stages": [
    {
      "id": "verify",
      "scope": "forEach",
      "map": [
        {
          "id": "contact", "mode": "primary", "source": "query", "entityType": "Contact",
          "where": { "id": "none" },
          "whereFormulas": { "id": "object\\get(object\\get($payload, '__trigger'), 'entityId')" }
        }
      ],
      "actions": [
        { "type": "verifyContactChannel", "params": { "check": "email_deliverability" } },
        {
          "type": "addChannelIdentity", "params": { "channel": "email" },
          "when": "object\\get(object\\get($payload, 'verification'), 'email_deliverability') == 'deliverable'"
        },
        { "type": "verifyContactChannel", "params": { "check": "whatsapp_registration", "stopWhen": "verified_phone" } },
        {
          "type": "verifyContactChannel", "params": { "check": "phone_identity", "stopWhen": "verified_phone" },
          "when": "object\\get(object\\get($payload, 'verification'), 'whatsapp_registration') == 'registered'"
        },
        {
          "type": "addChannelIdentity", "params": { "channel": "phone" },
          "when": "object\\get(object\\get($payload, 'verification'), 'whatsapp_registration') == 'registered' && object\\get(object\\get($payload, 'verification'), 'phone_identity') == 'verified'"
        }
      ],
      "onFailure": [],
      "itemMode": "allMatching"
    }
  ]
}
```

An email check on a found phone (and the reverse) records `not_applicable` without a charge, so
one automation handles both kinds.

## UI

Definition field: Builder (mode × source map steps, groupBy/timeBucket, action idempotency) + JSON toggle.

## Rebuild

Rebuild CRM after pull (new fields: `wakeAt`, Waiting, `parentRun*`, `triggerDebouncePeriod`, `AutomationActionReceipt`).
