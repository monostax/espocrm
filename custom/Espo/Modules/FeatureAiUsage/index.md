# AI Usage

- `GET /AiUsage/context` — authorized tenants, current billing month, and timezone.
- `GET /AiUsage` — tenant-month overview, paginated breakdown, or accessible activity.
- `GET /AiUsage/:id` — authorized engagement detail and source-record links.
- `#AiUsage` — shared tenant-administrator page, opened from Chatwoot with the embedded CRM navbar hidden.
- `Attribution/index.md` — delayed conversation-link recovery and permanent billing waivers.
- `TenantAiBillingRate.billingModel` — explicit dated contract model; blank requires configuration.
- `Services/index.md` — tenant authorization, calendar periods, billing ledger, and read models.

All endpoints are authenticated, private/no-store, and authorize the selected tenant on every request.
Direct tenant-admin roles apply to the user's memberships; team-inherited admin roles apply only to that team's tenants.
Prices use existing billing helpers. Model/allowance changes within a month require contract review; totals remain unavailable rather than guessing an allocation.
Runs explicitly marked `modelUsage.run.outcome = failed` or `billingWaived = true` consume no billing units or allowance. Waivers survive attribution repair and event replay. Missing links without an exemption are pending calculation. Cancelled, superseded, and legacy unknown outcomes retain their existing billing treatment.
Existing model assignments must be entered from commercial agreements. No model is inferred from prices or dashboard copies.

Verification: `php phpunit.phar tests/unit/Espo/Modules/FeatureAiUsage` and `node --test tests/unit-js/ai-usage.test.cjs`.
Build: normal CRM custom-module transpilation and rebuild. No dashboard-template deployment is required.
Rollout: rebuild CRM schema/metadata before deploying backend source-ID persistence. `ReconcileAiUsageLinks` retries matching account/tenant conversation links every five minutes. Historical repairs must preserve any agreed waiver explicitly.
Limits: 100,000 runs per tenant-month; overflow rejects the request without partial totals. Read models use streaming database reads and chronological allowance application.
