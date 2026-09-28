# AI Usage

- `GET /AiUsage/context` — authorized tenants, current billing month, and timezone.
- `GET /AiUsage` — tenant-month overview, paginated breakdown, or accessible activity.
- `GET /AiUsage/:id` — authorized engagement detail and source-record links.
- `#AiUsage` — shared tenant-administrator page, opened from Chatwoot with the embedded CRM navbar hidden.
- `/app/accounts/:accountId/crm/AiUsage?navbar=none#AiUsage` — Chatwoot administrator sidebar entry, AI Usage / Consumo de IA, below Reports; no automatic CRM sidebar entry.
- `TenantAiBillingRate.billingModel` — explicit dated contract model; blank requires configuration.
- `Services/index.md` — tenant authorization, calendar periods, billing ledger, and read models.

All endpoints are authenticated, private/no-store, and authorize the selected tenant on every request.
Direct tenant-admin roles apply to the user's memberships; team-inherited admin roles apply only to that team's tenants.
Prices use existing billing helpers. Model/allowance changes within a month require contract review; totals remain unavailable rather than guessing an allocation.
Runs explicitly marked `modelUsage.run.outcome = failed` remain in activity/failure counts but consume no billing units or allowance. Cancelled, superseded, and legacy unknown outcomes retain their existing billing treatment. Activity billing badges describe the daily group except for explicitly excluded runs.
Existing model assignments must be entered from commercial agreements. No model is inferred from prices or dashboard copies.

Verification: `php phpunit.phar tests/unit/Espo/Modules/FeatureAiUsage` and `node --test tests/unit-js/ai-usage.test.cjs`.
Build: normal CRM custom-module transpilation and rebuild. No dashboard-template deployment is required.
Rollout: rebuild CRM schema/metadata, publish both frontends, select verified `billingModel` values on dated rate cards, and smoke-check with real tenant administrators.
Limits: 100,000 runs per tenant-month; overflow rejects the request without partial totals. Read models use streaming database reads and chronological allowance application.
