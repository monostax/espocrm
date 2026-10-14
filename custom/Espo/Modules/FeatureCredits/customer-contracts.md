# M5 customer response contracts

Status: customer-surface corrections implemented; deployment verification is separate.

Checkout scope clarified with the customer: our subscription and credit-package screens
must be provider-neutral. The existing secure external payment page remains available.
Its destination, bank/payment artifacts and processor notifications are external payment
disclosures, not an anonymous-payment guarantee. No redirect/proxy is presented as anonymity.

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
Every customer controller is registered with `CustomerResponse`, which replaces technical
exception messages/bodies/reason headers with neutral error codes and retains diagnostics
in internal logs. Successful responses retain private/no-store caching.

| Surface | Public contract / status |
| --- | --- |
| CreditEnrichmentPreview | Explicit action, availability, neutral reason, starting cost, unit, and recommended waterfall/budget settings. |
| CreditEnrichmentQuotes, /confirm, /admit | `Enrichment/CustomerProjection::quote`: record/quote IDs, products, options, exact budget/maximum, times, admission, source-numbered prices and progress. No provider/version/policy/availability maps. Same projection on replay. |
| CreditEnrichmentReview, CreditEnrichmentHistory | Verified receipt projected through `CustomerProjection::purchase`: IDs, product, exact credits, dates, quote-local source. Review services construct explicit provenance, conflict and application-status fields; normalized purchased values retain current field ACLs. History excludes values. |
| CreditEnrichmentFields, CreditEnrichmentApplication | `CustomerProjection::application`: allowlisted record/purchase/relationship IDs, applied fields, actor and time. Projection occurs after application/replay binding checks. |
| CreditBilling/context, CreditCheckout/context | `Billing/CustomerProjection::context`: account/country/revision, catalog and commercial offers, generic checkout eligibility; checkout history projects every original acceptance. |
| CreditBilling/obligation, /accept | Obligation state and allowlisted acceptance: original command/catalog/terms IDs, country, revision, dates, exact commercial terms. No provider/integration IDs. |
| CreditBilling/country, /cancel | Allowlisted change receipt and before/after account state, including replay. |
| CreditBilling/customer, /charge, /recoverCreation | Internal creation IDs, tenant/obligation, kind, state, environment, dispatch flag. No external resource IDs or integration bindings. |
| CreditCheckout | Allowlisted original acceptance, creation summaries, subscription status, earliest due date. |
| CreditSubscriptions/cancel, /reconcile | Tenant/obligation IDs, local state, cancellation pending, observed time, bounded neutral status. |
| CreditCheckout/payment | Allowlisted tenant/obligation IDs, environment, neutral payment state and verified external payment URL. No raw processor status or integration binding. Both checkout translations use neutral copy. |
| Credits/context, CreditWorkspace, /conversation | Explicit tenant/membership, balance and operation-state summaries; conversation records retain existing ACL checks. |
| CreditBalance, CreditHistory, CreditGrants, CreditReservations, CreditRequests, CreditOperations | Existing explicit numeric/date/state projections. Stored pricing snapshots, evidence, external references and provider bindings are read only for internal checks, never returned. |
| CreditOperationSource | Explicit source scope/ID/name and internal request identity after source-record and tenant checks. No evidence object returned. |
| CreditPhoneVerification, CreditEmailVerification | Explicit context/start/status/history projections after Contact and kind-specific field/source authorization. Email returns `verificationKind: email_deliverability`, canonical email, deliverability outcome, score, allowlisted flags, dates, and fixed credits. Phone retains its existing contract. No supplier/task IDs, raw responses, provider-credit balances, policy hashes, or dispatch claims. Email financial operation/reservation reports include the neutral verification kind. |
| AiUsage/context, /summary, /detail | Model names removed from activity projections for every role. Analytics groups only outcomes/time and customer-selected operational dimensions; model grouping is removed from API and UI. Billing-model enums describe the commercial charging method, not an AI supplier/model. |

## Operator and machine surfaces

- CreditConfiguration, CreditEnrichmentConfiguration, CreditBillingConfiguration and
  CreditBilling/override require instance-administrator `User::isAdmin()`, separate from
  tenant financial-admin access/membership used by customer endpoints. Customer
  checkout no longer renders routing/override controls. Previously saved operator
  override commands remain recoverable with the existing instance-admin check.
- CreditPhoneVerificationConfiguration and CreditEmailVerificationConfiguration are
  independently gated instance-administrator surfaces. Their secrets stay in server
  environment configuration. See [verification operations](Verification/README.md).
- CreditMigration and CreditReversals require instance administrators. CreditDispatch
  and CreditExecution verify timestamped HMAC signatures; payment webhooks verify the
  originating integration's access token. They are not customer receipt contracts.
- `CustomerBoundaries` disables generic record endpoints and exports for every
  FeatureCredits storage entity (including future ones). Output filters also reject
  financial records returned through relationship reads. Frontend metadata hides their
  definitions and the supplier implementation map in `app.enrichment`.
- Generic AI-run record output has an attribute allowlist. Model names, raw model usage,
  workflow/event IDs and internal waiver diagnostics are excluded. Their fields are
  non-exportable, non-filterable, direct-access-disabled and hidden from frontend metadata;
  future non-allowlisted fields inherit these restrictions at metadata build time.
- Enrichment rate-editor supplier-specific availability rules are now returned by its
  operator-only endpoint rather than encoded in browser JavaScript. Customer enrichment
  journals contain no suppliers or responses. Existing operator-only override journals
  retain original commands for recovery.
- FeatureCredits does not dispatch its own customer notification messages. The optional
  payment-processor notifications are part of the external payment flow described above.

## Checkout reference

The processor's [payment documentation](https://docs.asaas.com/docs/payments-overview)
supports both hosted invoices and transparent checkout. It does not establish anonymous
payment artifacts or mandatory-disclosure removal. The approved implementation keeps
verified hosted payments and removes provider names from our checkout screens; it does
not replace payment processing or alter already accepted purchases/subscriptions.

## Verification and release

- Projection tests cover unknown-field exclusion, exact prices, source numbering across
  products/progress/purchases, replay stability, unchanged input hashes, billing history
  and recovery projections.
- CRM tests cover journals, uncertain replies, account isolation and provider-free
  routing presentation. Chatwoot tests cover translated source-number presentation.
- Boundary tests cover merged metadata, generic-read denial before queries, export flags,
  future-field exclusion, technical error sanitization and customer/admin model exclusion.
- Verified locally against MariaDB and PostgreSQL: the 355-test affected PHP run passed
  except for two outdated rate-editor expectations; after correcting those expectations,
  all 14 catalog tests passed (192 assertions). No errors remained in the affected run.
- Checkout, enrichment and AI-usage JavaScript regression tests passed. Production
  transpilation built 10 credit modules and 6 AI-usage modules; changed PHP files passed syntax checks.
- Release requires metadata cache rebuild and compatible frontend assets. Direct/embedded
  deployed browser/network checks must be recorded with the deployed version; local tests
  are not deployment evidence.
