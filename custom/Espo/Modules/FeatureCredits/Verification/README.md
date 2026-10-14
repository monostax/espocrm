# Phone and email verification

The existing verification operation/request tables, wallet locks, settlement, and
`DispatchPhoneVerification` / `RunPhoneVerification` jobs serve both kinds:

| Kind | Provider | Completed price | Completed outcomes |
| --- | --- | --- | --- |
| `phone_identity` | Twilio Lookup | `8.0000` | `verified`, `not_verified`, `insufficient_data` |
| `email_deliverability` | BounceBan standard single verification | `2.0000` | `deliverable`, `risky`, `undeliverable`, `unknown` |

Errors and unresolved deadlines settle at zero. Email deliverability does not
establish identity or ownership. Email checks never read or save names/DOB.

## Deployment and activation

1. Deploy the additive metadata/schema and compatible accounting code before enabling
   email. Run the normal CRM rebuild. `PhoneVerificationOperation.verificationKind`
   defaults to `phone_identity` for legacy rows; `pollCount` defaults to zero.
   `supplierTaskCiphertext`, `nextPollAt`, and `VerificationProviderWindow` are new.
   Keep existing receipt hashes and request bindings intact.
2. Build/deploy the CRM frontend and Chatwoot assets together with the backend.
3. Set `crmVerificationBounceBanApiKey` in
   `gitops/ai-monostax/ai-monostax-app/secrets.nix` and redeploy. The shared
   `gitops/ai-monostax/:tenant/crm/add.crm.Deployment.nix` template supplies it as
   `VERIFICATION_BOUNCEBAN_API_KEY` to CRM web and daemon containers. The separate
   production daemon inherits that environment. Dev namespaces have the same secret
   entry in their own `secrets.nix`. Init, WebSocket, and live-records containers do
   not receive this key.
4. Ensure the existing `DispatchPhoneVerification` scheduled job runs every minute.
   It dispatches admitted operations, schedules due status reads, closes expired
   holds, removes expired evidence, and records the recovery heartbeat.

A non-empty API key enables email verification for authorized tenants with credits.
An empty key leaves it unavailable. No configuration POST, approval reference, or
tenant allowlist is required. No live provider probe runs on deployment/context reads.
`VERIFICATION_KILL_SWITCH=true` stops new dispatch for both kinds. Already submitted
email tasks may finish through free status retrieval; authorization revocation and
erasure still stop delivery. Shared operational diagnostics are available through
the existing instance-admin `CreditPhoneVerificationConfiguration` GET endpoint.

Both kinds share the limits configured through `CreditPhoneVerificationConfiguration`:
5 outstanding operations, 10 admissions/minute, 100/day per tenant by default, with
a 60-second same-fingerprint cooldown. Email activation does not require phone
activation; its availability ignores phone-specific approval and allowlist settings.

Account-wide submit and status limits each default to 25 requests/second. Set
`VERIFICATION_BOUNCEBAN_SUBMIT_PER_SECOND` and
`VERIFICATION_BOUNCEBAN_STATUS_PER_SECOND` to a validated lower integer (1–25) when
required. Their durable database windows are independent of tenant admission limits.

## Email API and lifecycle

- `POST /CreditEmailVerification/context`: `tenantId`, `contactId`, `sources[]`.
- `POST /CreditEmailVerification`: `tenantId`, `contactId`, `source`, `requestKey`,
  `contextRevision`, optional `refresh`. DOB and arbitrary email inputs are rejected.
- `GET /CreditEmailVerification/status`: tenant/Contact plus `requestKey` or `operationId`.
- `GET /CreditEmailVerification/history`: tenant/Contact, optional `limit`,
  `beforeAt` / `beforeId` cursor.

Sources are `contact_email`, a saved email `channel` (ID or channelType/sourceId),
or a `purchased` email receipt/index. The server resolves the address and checks
Contact, field, source, tenant, and ownership-rejection authorization. Addresses
are trimmed/lowercased; dots and `+tags` remain distinct.

One atomic admission reserves two credits. A durable claim precedes the single
paid GET to `/v1/verify/single` with `mode=regular` and `disable_catchall_verify=0`.
An ambiguous/lost submission response is never resubmitted by the worker.

`queue` / `verifying` persists the encrypted task ID and enters `awaiting`. Subsequent
claims read `/v1/verify/single/status?id=...`, never the paid endpoint. Polls respect
`try_again_at`, wait at least 30 seconds, and are capped at 10 within the original
five-minute admission deadline. The minute sweeper may perform fewer polls. A
crashed status read is recoverable with the persisted task; a crashed initial
submission is waived. Completed `unknown` costs two credits; pending/failed HTTP
responses do not become completed `unknown` results.

Publication and fixed settlement share one transaction. Replays/joined checks reuse
the same operation. Fresh evidence is reusable for 30 days; retained evidence is
removed after 90 days or Contact privacy erasure. Financial receipts survive without
addresses, supplier task IDs, or detailed evidence. Completed results include only
deliverability, score, allowlisted flags, and dates. Supplier credit counts never
determine the platform charge.

Chatwoot exposes confirmation, shared badges, refresh, and dated history on purchased
email rows, the primary Contact email, and saved email channels, in English and
Brazilian Portuguese. The email browser journal is isolated from phone journals and
contains only IDs/revisions; uncertain HTTP submissions retain the same request key.

## Local validation

`EmailVerificationUnitTest` exercises the transport contract and response validation.
`PhoneVerificationDatabaseTest` covers both kinds, including shared pilot limits,
all email outcomes, source reuse, task recovery, deadlines, access revocation,
erasure, retention, and financial reconciliation on MariaDB and PostgreSQL.
`SchemaTest`, customer-boundary tests, and credit operation/reservation tests cover
the shared integration. Chatwoot's verification specs cover two-credit confirmation,
absence of DOB prompts, source sharing, and uncertain-response recovery.

Production activation remains separate from local fixture validation. Rollback
should disable admission while retaining compatible recovery/accounting/schema.
