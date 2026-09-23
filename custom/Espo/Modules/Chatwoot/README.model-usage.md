# Model requests and cache telemetry

`ChatwootAiAgentRun` remains one engagement. It is **not** one provider request.
The main tool loop and the knowledge-base search tool each generate their own
model requests. `stepCount` retains its existing main-loop meaning; billing
credits and conversation-day arithmetic are unaffected by this instrumentation.

## Capture and persistence

The backend records a versioned `modelUsage` payload containing one entry per
completed generation (`main` or `search`), with input, cached input, output and
thinking tokens. It contains no prompts, search queries or customer content.

- Main generations are captured in `onStepFinish`, including completed steps
  before an attempt fails and tool-free opportunity summaries.
- Every search provider response is captured, even if retrieval found no
  documents. Tool circuit-breaker short-circuits are not provider requests.
- Google omits cache/thinking counters when zero; missing input/output usage is
  retained as **unknown**, not a zero-cost request or a cache miss.
- Output includes thinking once. Cache is a subset of input, not additional
  context. Search input includes the provider's file-search tool-use tokens.
- Completed-generation counters do not count HTTP attempts that fail before a
  provider completion. Runs that never emit an `agent_completed` event are not
  represented by this entity. These reports are not a replacement for provider
  invoice reconciliation or transport-error monitoring.

Both conversation/follow-up and opportunity-stream producers send the payload.
The live consumer and event backfill use the same normalization. MySQL and
PostgreSQL upserts fill only NULL telemetry on duplicate delivery, preserving
already-recorded counters and the existing billing identity.

## Fields and historical coverage

- `usageMetricsVersion`: currently 1; NULL for legacy or malformed telemetry.
- `modelRequestCount = mainRequestCount + searchRequestCount`.
- `mainUsageRequestCount` / `searchUsageRequestCount`: requests whose input,
  cache and output usage are valid; these are the ratio denominators.
- `mainCacheHitRequestCount` / `searchCacheHitRequestCount`: measured requests
  with at least one cached input token.
- Input/cache/output and peak input are stored separately for main and search.
- `modelUsage` retains per-request values for audit and distributions.

All new stored fields are nullable without a zero default. Old records must not
be backfilled from the deduplicated `toolsUsed` list: `search` means at least one
tool invocation, not its count. An event replay can recover the new fields only
when the original event actually contains the versioned payload. No historical
requests or cache hits are invented.

## Reports

Rebuild seeds three globally shared, ACL-strict internal reports:

| ID | Name |
|---|---|
| `chwRptUsageDay` | IA · Requests e Cache / Por Dia |
| `chwRptUsageModel` | IA · Requests e Cache / Por Modelo |
| `chwRptUsageTotal` | IA · Requests e Cache / Total |

All accept `runAt`, `tenant`, `model` and `kind`. Click-through lists the source
engagements. The daily buckets use the system timezone, matching existing date
filters. Relationship filters and team joins are scoped through an ID semi-join
so they cannot multiply counters. Grand totals use an independent aggregate
over the same ACL-filtered cohort.

### Rates

```
token cache rate = 100 × SUM(cached input) / SUM(input)
request cache hit rate = 100 × SUM(requests with cached input > 0)
                            / SUM(requests with known usage)
average input/request = SUM(input for component) / SUM(measured requests for component)
telemetry coverage = 100 × runs with versioned telemetry / all runs
```

Never average per-run percentages. Zero denominators display unknown/null.
Coverage and requests without usage are displayed alongside the hit rates.
The combined **recorded token cache rate** uses the legacy aggregate fields and
is available for historical rows; component/request rates use only the new
telemetry. A mixed historical/new period therefore has explicitly different
coverage for those indicators.

For example, two measured requests with input/cache of 100/100 and 900/0 have
50% request hit rate but 10% token cache rate. The latter is the more direct cost
driver. Gross input savings against a no-cache baseline are:

```
cachedTokens × (inputPrice - cacheReadPrice) / 1,000,000
```

Apply prices per model; explicit-cache storage, audio and other services remain
separate expenses. A cache lookup in Redis is not evidence of a provider token
cache hit; these metrics use the provider-reported usage.

## Rollout and checks

1. Deploy CRM metadata/report classes and run the standard CRM rebuild first.
   This creates the nullable columns and seeds the reports on either SQL dialect.
2. Deploy the backend producers, consumer and database mappings together.
   The consumer inserts the new columns, so the CRM schema must exist first.
3. Inspect a new engagement: main + search counts must equal total requests;
   request details must agree with the per-component fields. Missing provider
   usage must be shown in the report instead of silently reducing the hit rate.
4. Optional event replay uses `scripts/backfill-chatwoot-ai-agent-runs.ts` and
   only restores telemetry already present in the retained events.

Targeted tests cover repeated search calls, missing usage, cache misses,
thinking accounting, opportunity summaries/failures, persistence, weighted
totals, mixed historical coverage, ACL filtering, duplicate relationship joins
and daily grouping.
