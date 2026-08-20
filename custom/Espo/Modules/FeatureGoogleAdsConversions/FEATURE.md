# Google Ads Conversions

## Purpose

`FeatureGoogleAdsConversions` sends selected EspoCRM Opportunity stage entries
to Google Ads as offline conversions and Enhanced Conversions for Leads.

The module uses the Google Data Manager API:

```text
POST https://datamanager.googleapis.com/v1/events:ingest
OAuth scope: https://www.googleapis.com/auth/datamanager
```

It does not use the Google Ads conversion upload endpoint and does not require a
Google Ads developer token.

## Scope

The MVP contains three records:

| Record | Purpose |
| --- | --- |
| `GoogleAdsDestination` | Tenant-owned OAuth and Google Ads account routing |
| `GoogleAdsConversionMapping` | Maps entry into one Opportunity stage to one conversion action |
| `GoogleAdsConversionUpload` | Immutable encrypted outbox row and delivery status |

The module intentionally does not manage campaigns, create or synchronize
conversion actions, ingest Google Lead Forms, or send stage-leave events.

## Event Flow

1. Google Ads auto-tagging adds `gclid`, `gbraid`, or `wbraid` to the landing URL.
2. The Monostax browser tracker stores the click attribution for up to 30 days.
3. The tracker records the current consent state on browser events.
4. The normal TrackingEvent identity flow stitches browser events to a Contact.
5. An Opportunity enters a configured stage.
6. The Opportunity hook creates an immutable `GoogleAdsConversionUpload` row.
7. The row stores the Data Manager request encrypted with Espo `Crypt`.
8. An asynchronous job sends the request and records only redacted diagnostics.
9. The retry sweeper dispatches due transient failures.

Only stage entry is supported. An initial stage on Opportunity creation is sent
only when the mapping has `includeOnCreate` enabled.

## Google Prerequisites

### Google Cloud

1. Create or select a Google Cloud project.
2. Enable the Data Manager API.
3. Configure the Google OAuth consent screen.
4. Create a Web application OAuth client.
5. Add the Espo OAuth callback URL shown by the OAuth Provider UI.
6. Add the sensitive `https://www.googleapis.com/auth/datamanager` scope.
7. Complete Google OAuth verification before connecting production tenants.

### Google Ads

1. Enable auto-tagging.
2. Install the Google tag on the lead form or landing page.
3. Accept the customer-data terms.
4. Enable Enhanced Conversions for Leads when hashed Contact identifiers will be sent.
5. Create each conversion action as `Website (Import from clicks)`.
6. Record the numeric conversion action ID.

Use separate actions for meaningful outcomes such as submitted lead, qualified
lead, booked demo, and converted lead. Keep observation milestones secondary
and select one reliable primary action for bidding.

## CRM Setup

### 1. Rebuild

From the Espo application root:

```bash
php command.php clear-cache
php command.php rebuild
```

Rebuild creates or refreshes the global `Google Data Manager` OAuth Provider.
It does not overwrite an existing provider's client ID, client secret, name,
active state, or sharing choice.

### 2. Configure OAuth

Open the seeded `Google Data Manager` OAuth Provider and set the Google Cloud
OAuth client ID and client secret. Create an OAuth Account through that provider
and complete the Google authorization flow.

The OAuth Account must be assigned to teams that resolve to exactly one tenant.
The Destination and OAuth Account must resolve to the same tenant.

### 3. Create A Destination

Create `GoogleAdsDestination` with:

| Field | Value |
| --- | --- |
| OAuth Account | The connected Google Data Manager OAuth Account |
| Operating Account ID | The 10-digit Google Ads account that owns the conversion action |
| Login Account ID | Optional 10-digit manager account through which the OAuth user has access |
| Teams | Teams belonging to exactly one tenant |
| Active | Enabled for delivery |

Enter account IDs as digits only, without hyphens. With cross-account conversion
tracking, the operating account is the account that owns the conversion action,
which may be a manager account.

### 4. Create Mappings

Create one `GoogleAdsConversionMapping` per meaningful stage milestone:

| Field | Meaning |
| --- | --- |
| Destination | Google Ads routing and OAuth configuration |
| Funnel | Funnel containing the stage |
| Opportunity Stage | Stage whose entry creates the conversion |
| Conversion Action ID | Numeric Google Ads `UPLOAD_CLICKS` action ID |
| Include on Create | Whether an Opportunity created directly in this stage counts |
| Include User Data | Allows hashed identifiers only when ad-user-data consent is Granted |
| Lookback Days | Click attribution window, 1 through 90 days |
| Value Source | No value, Opportunity amount, or a fixed value |
| Currency Source | Opportunity currency or a fixed supported currency |

The Destination, Mapping, Funnel, Stage, Opportunity, Contact, and OAuth Account
must all resolve to the same tenant. The module fails closed on a mismatch.

### 5. Enable The Retry Sweeper

Create or enable the scheduled job named:

```text
Dispatch Pending Google Ads Conversions
```

Recommended schedule:

```cron
* * * * *
```

The stage hook schedules the initial send. The sweeper recovers scheduling
failures, abandoned processing rows, and due transient retries.

## Tracker Consent

Set consent before calling `page` or `track`:

```html
<script>
  mstx('init', 'https://crm.example.com/api/v1/TrackingEvent/receive/SOURCE_ID');
  mstx('consent', {
    adUserData: 'granted',
    adPersonalization: 'granted'
  });
  mstx('page');
</script>
```

Accepted values are `granted`, `denied`, and `unknown`. The tracker persists the
choice in browser local storage and attaches it to subsequent events. Calling
`reset` clears visitor identity and attribution but retains the browser's privacy
choice.

The CRM never infers Granted. Hashed email and phone identifiers are included
only when the most recent explicit `adUserData` state before the acquisition
anchor is Granted. Unknown or Denied prevents user-data upload.

## Attribution

Attribution is anchored to `Opportunity.createdAt`, not to the later stage-change
time. This prevents a later advertising click from replacing the click that
created the Opportunity.

Within the mapping lookback window, the resolver uses the newest same-tenant
TrackingEvent at or before that anchor containing:

1. `gclid`, if available.
2. One braid identifier, `wbraid` or `gbraid`.
3. `Contact.googleGclid`, `googleGbraid`, or `googleWbraid` only when the Contact
   has a recent `googleAdsClickCapturedAt` value.

`googleGaClientId` is not a Google Ads click ID and is never used here.

## User Data

The MVP sends normalized SHA-256 hex hashes for available Contact email addresses
and E.164 phone numbers. It sends at most ten identifiers.

Email normalization follows Google's Gmail-specific rules. Phone numbers must
already include a leading `+` and country code, for example `+5511999999999`.
The module does not infer a country code.

Raw Contact identifiers are not stored in upload diagnostics. Click identifiers
and the complete request exist only inside the encrypted upload payload.

## Delivery And Retry

Data Manager uses a fast-fail request model. The MVP sends one conversion per
request so a failed request maps directly to one outbox row.

Transient failures are retried after approximately:

```text
1 minute, 5 minutes, 15 minutes, 1 hour, 6 hours, 24 hours
```

The maximum is seven delivery attempts. HTTP 408, 429, 5xx, transport failures,
and temporary Google statuses are retryable. Invalid configuration, permissions,
payloads, or identifiers become permanent failures.

The stable `transactionId` and local unique `idempotencyKey` prevent normal queue
redelivery from creating a new conversion.

## Upload Statuses

| Status | Meaning |
| --- | --- |
| Pending | Ready or queued for the first delivery |
| Processing | A worker is sending the request |
| RetryScheduled | A transient failure is waiting for its due time |
| Sent | Google accepted a production ingestion request |
| Validated | Google accepted a `validateOnly` request without ingesting it |
| FailedPermanent | Delivery cannot succeed without configuration or data changes |
| Skipped | No eligible identity existed or configuration was inactive |

A successful response means Google accepted the ingestion request. Matching and
attribution remain asynchronous. Use the stored Google `requestId` and Google
Data Manager diagnostics when investigating attribution.

## Security

- OAuth access and refresh tokens stay in Espo OAuth Account storage.
- Upload requests are encrypted at rest with Espo `Crypt`.
- UI/API users cannot create, update, or delete upload rows.
- Logs contain no OAuth token, raw Contact PII, raw click ID, or full payload.
- Every configuration and delivery path validates tenant equality at save time
  and again at dispatch time.

## Operational Checks

```bash
php command.php clear-cache
php command.php rebuild
php command.php run-job DispatchPendingGoogleAdsConversions
```

Inspect `GoogleAdsConversionUpload` for status, attempts, request ID, warnings,
and redacted errors. Confirm the OAuth Provider is active, the OAuth Account is
connected, the Destination is active, and the scheduled sweeper is running.

## Source Map

```text
Rebuild/SeedOAuthProviderGoogleDataManager.php  OAuth provider seed
Hooks/Opportunity/QueueGoogleAdsConversions.php Stage-entry trigger
Services/GoogleAdsUploadFactory.php             Immutable outbox creation
Services/GoogleAdsAttributionResolver.php       Click and consent resolution
Services/GoogleAdsUserDataNormalizer.php        Email/phone normalization
Services/GoogleAdsEventBuilder.php              Data Manager event payload
Services/DataManagerClient.php                  OAuth REST transport
Services/GoogleAdsDispatcher.php                State transitions and delivery
Jobs/DispatchPendingGoogleAdsConversions.php    Recovery and retry sweeper
```
