# M5 customer response contracts

Status: enrichment source labels and billing receipt projections implemented locally.
This is **not** completion of the customer-surface audit or a confidentiality guarantee.
M4 hosted checkout remains a release dependency.

## Rules

- Apply customer contracts regardless of customer role, including tenant administrators.
- Verify the full original receipt/snapshot before projecting. Do not persist projected
  responses, recompute acceptance hashes from them, or change provider execution bindings.
- Source numbers are assigned by first appearance in the original quote's routes.
  A supplier can therefore be Source 1 in one quote and Source 2 in another.
  Review/history use the purchased receipt's original quote, including cached purchases.
- Numbers are quote-local, not identifiers for a supplier directory. Clients translate
  `source: 1` as `Source 1` / `Fonte 1`; they must not fall back to provider names.
- Enrichment retry journals contain commands/settings and IDs, not quote responses or
  purchased data. Preserve their keys and recovery stages through this contract change.

## Customer route inventory

Routes are from `Resources/routes.json`, plus the existing FeatureAiUsage controller.
“Pending” below means the surface has not been certified supplier-free.

| Surface | Public contract / status |
| --- | --- |
| CreditEnrichmentPreview | Existing explicit action, availability, neutral reason, starting cost, unit projection. |
| CreditEnrichmentQuotes, /confirm, /admit | `Enrichment/CustomerProjection::quote`: record/quote IDs, products, options, exact budget/maximum, times, admission, source-numbered prices and progress. No provider/version/policy/availability maps. Same projection on replay. |
| CreditEnrichmentReview, CreditEnrichmentHistory | Verified receipt projected through `CustomerProjection::purchase`: IDs, product, exact credits, dates, quote-local source. Existing ACL-filtered values, provenance, application permissions remain; history excludes values. Broader nested contract review pending. |
| CreditEnrichmentFields, CreditEnrichmentApplication | Application receipts/recovery: pending full nested audit. |
| CreditBilling/context, CreditCheckout/context | `Billing/CustomerProjection::context`: account/country/revision, catalog and commercial offers, generic checkout eligibility; checkout history projects every original acceptance. |
| CreditBilling/obligation, /accept | Obligation state and allowlisted acceptance: original command/catalog/terms IDs, country, revision, dates, exact commercial terms. No provider/integration IDs. |
| CreditBilling/country, /cancel | Allowlisted change receipt and before/after account state, including replay. |
| CreditBilling/customer, /charge, /recoverCreation | Internal creation IDs, tenant/obligation, kind, state, environment, dispatch flag. No external resource IDs or integration bindings. |
| CreditCheckout | Allowlisted original acceptance, creation summaries, subscription status, earliest due date. |
| CreditSubscriptions/cancel, /reconcile | Tenant/obligation IDs, local state, cancellation pending, observed time, bounded neutral status. |
| CreditCheckout/payment | **M4 blocker:** still returns verified processor-hosted URL/status. Existing hosted page, payment disclosures, URL validator and checkout notices identify the processor. No proxy workaround or absolute guarantee. |
| Credits/context, CreditWorkspace, /conversation | Existing explicit context/balance/operation summaries; pending full audit. |
| CreditBalance, CreditHistory, CreditGrants, CreditReservations, CreditRequests, CreditOperations, CreditOperationSource | Pending contract audit of ledger/history/source-reference fields and error paths. |
| AiUsage/context, /summary, /detail | **Open leak:** FeatureAiUsage Service selects `model` and groups analytics by model. Needs neutral grouping and compatible UI contracts. |

## Operator and machine surfaces

- CreditConfiguration, CreditEnrichmentConfiguration, CreditBillingConfiguration and
  CreditBilling/override currently require `User::isAdmin()`. Verify this is exclusively
  platform-operator authority in deployed direct and embedded CRM contexts. Customer
  checkout no longer renders routing/override controls. Previously saved operator
  override commands remain recoverable with the existing instance-admin check.
- CreditMigration, CreditReversals, CreditDispatch, CreditExecution and CreditAsaasEvents
  need a separate authorization review; they must not become customer receipt contracts.
- Generic entity reads, exports and metadata are **not certified** by these custom
  controller projections. `aclStrict`, non-exportable scopes and mutation-denial hooks
  alone do not prove that supplier-bearing entity fields are unreadable to every role.
- Browser-delivered admin assets/translations, notifications, and technical exception
  messages still require review. Customer controllers currently forward some service
  exception messages; neutral error contracts and internal diagnostic logging are pending.

## Verification and release

- Projection tests cover unknown-field exclusion, exact prices, source numbering across
  products/progress/purchases, replay stability, unchanged input hashes, billing history
  and recovery projections.
- CRM tests cover journals, uncertain replies, account isolation and provider-free
  routing presentation. Chatwoot tests cover translated source-number presentation.
- Still required: database-backed receipt/recovery tests, direct/embedded browser network
  inspection, remaining surface corrections, supported white-label checkout verification,
  compatible asset builds, deployment and deployed-response checks.
