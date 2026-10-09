# Internal usage analytics

`Analytics` summarizes existing run counters; it never changes billing. `Projection::canViewAnalytics()` checks the authenticated EspoCRM `User::isAdmin()`. Tenant-admin roles alone do not qualify. Summary, comparison, breakdown and activity/detail projections omit analytics and token fields for non-admins. Client visibility also checks the current user.

- Summary analytics contain whole-tenant-month totals, all filtered runs (before pagination), daily, model and outcome groups, and a matching previous-period summary on the overview.
- Breakdown analytics cover all matching runs in each displayed bucket. Tool buckets overlap, so their totals must not be added together. Only the requested page of buckets is materialized.
- Total recorded tokens are the sum of known input and output counters. Cache is a subset of input; reasoning is a subset of output. Failed, waived and pending runs still contribute actual recorded consumption.
- Token cache-hit percentage uses paired valid cached/input counters. Uncached input uses that same population. Request cache-hit percentage uses requests with any cache divided by requests with usage, from valid v1 main/search counters. Ratios are calculated from summed counters, not averaged percentages.
- Source counters are already normalized at persistence: output includes reasoning; requests without reported usage do not contribute source tokens. Main includes helpers; search includes isolated search/knowledge requests. A run's model is not necessarily every request's model.
- Missing values remain null. Coverage counts for individual fields accompany totals. Legacy numeric counters may be available without v1 request telemetry; even v1 runs can have unreported requests, so recorded consumption is potentially partial. A known zero is distinct from unknown.
- Average tokens per run uses runs with both input and output; average tokens per request uses measured source requests. Durations use known nonnegative values; P50/P95 use nearest rank. They describe end-to-end runs, not model latency.
- Customer billing rates are not provider token prices. These analytics do not invent provider spend or monetary cache savings.

Tests: `tests/unit/Espo/Modules/FeatureAiUsage` and `node --test tests/unit-js/ai-usage.test.cjs`.
