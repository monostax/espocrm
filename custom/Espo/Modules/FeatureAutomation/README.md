# FeatureAutomation

Batch + Machine automations with FeatureJourney security spine.

**v0.3.0** — fan-in join, windowed groupBy, action/trigger idempotency, dry-run simulate.

## Entities

- **Automation** — definition (`kind`, `triggerType`, `scheduling`, `definition` JSON, `triggerDebouncePeriod`)
- **AutomationRun** — one execution (`parentRun` / `parentRunItemId` for children)
- **AutomationRunItem** — per-target ledger (claim / Waiting / Done / Failed)
- **AutomationActionReceipt** — durable idempotency / debounce keys

## Batch map (general-purpose loop)

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
      "idempotencyKey": "digest|{targetId}|{type}",
      "debounce": "20 hours",
      "params": { "chatwootInboxId": "…", "body": "Resumo" }
    }
  ],
  "limits": { "maxItems": 5000, "maxExpandPerParent": 200 }
}
```

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
    { "id": "Done", "type": "final" }
  ],
  "transitions": [
    { "from": "Notify", "to": "Wait" },
    { "from": "Wait", "to": "Done" }
  ]
}
```

Requires trigger `entityType` + `entityId`. Waits → item `Waiting` + `wakeAt`.

## Triggers

| triggerType | Behavior |
|-------------|----------|
| manual | Run Now |
| schedule | cron / `daily:HH:MM` / `every:Nmin` |
| entityChange | Opportunity, Contact, Lead, Account (+ optional debounce) |
| signal | `AutomationEventDispatcher::dispatchSignal` |

## UI

Definition field: Builder (mode × source map steps, groupBy/timeBucket, action idempotency) + JSON toggle.

## Rebuild

Rebuild CRM after pull (new fields: `wakeAt`, Waiting, `parentRun*`, `triggerDebouncePeriod`, `AutomationActionReceipt`).
