# Google Ads Conversions FAQ

## Why does this use Data Manager API instead of Google Ads API?

Google directs new offline conversion and Enhanced Conversions for Leads
integrations to Data Manager API. It also avoids depending on Google Ads API
offline-upload allowlisting for a developer token.

## Do I need a Google Ads developer token?

No. The module uses OAuth with the Data Manager scope and routes each event using
the operating account and conversion action IDs in the request body.

## Does the module create campaigns or conversion actions?

No. Create conversion actions in Google Ads and enter their numeric IDs in CRM
mappings. The module only sends conversion events.

## Which CRM stages should be mapped?

Map only outcomes useful for measurement or bidding, such as qualified lead,
booked demo, trial started, or opportunity won. Do not map every stage.

## Does leaving a stage send an event?

No. The MVP sends only stage-entry conversions. Google Ads conversion actions
are optimization signals, not a complete funnel activity stream.

## Should every mapped conversion be Primary in Google Ads?

No. Keep observation milestones Secondary. Select one reliable business outcome
as Primary once it has enough volume for bidding. Making every stage Primary can
double-count one lead and train bidding toward the wrong outcome.

## Why was no upload row created after a stage change?

Check that:

- The Opportunity has a Funnel, tenant, and destination stage.
- An active Mapping exists for that Funnel and Stage.
- The Mapping and Destination are active.
- The Opportunity was not merely saved without changing stage.
- `includeOnCreate` is enabled when the Opportunity was created in the stage.
- All linked records belong to the same tenant.

Creation errors are logged without blocking the Opportunity save.

## Why is an upload Skipped with `NO_USABLE_IDENTIFIER`?

The module found neither a recent eligible Google click ID nor a permitted hashed
Contact identifier. Confirm TrackingEvent stitching, the lookback window, click
capture, Contact email/phone data, and explicit ad-user-data consent.

## Is user data uploaded when consent is Unknown?

No. Hashed email and phone data require explicit `adUserData: granted`. Unknown
and Denied both suppress user-data upload. A click-ID-only event may still be
sent with its recorded consent state.

## When should the tracker consent command run?

Call it after your consent-management platform resolves the user's choice and
before `mstx('page')`, `mstx('track', ...)`, or form events:

```javascript
mstx('consent', {
  adUserData: 'granted',
  adPersonalization: 'denied'
});
```

Call it again whenever the user changes the choice.

## Does `mstx('reset')` erase consent?

No. It clears visitor identity and advertising attribution but retains the
browser-level privacy choice. Update consent explicitly through `mstx('consent')`.

## Which click identifier is used?

The module can send `gclid` plus one compatible braid identifier from the same
attribution snapshot. It never sends both `gbraid` and `wbraid` together.

## Can a later Google Ads click replace the acquisition click?

No. Attribution is resolved at or before `Opportunity.createdAt`. A later click
does not silently take credit for a conversion on an existing Opportunity.

## Why is a Contact click-ID fallback ignored?

The compatibility fields require `googleAdsClickCapturedAt` within the Mapping's
lookback period. A click ID with no trustworthy capture time is not uploaded.

## What phone format matches Google?

Use full E.164 with a leading plus sign and country code, such as:

```text
+5511999999999
```

The module strips presentation punctuation but does not guess a missing country
code.

## Are raw emails and phone numbers sent?

No. Email and phone values are normalized and SHA-256 hashed before payload
creation. The resulting request is encrypted at rest.

## Are raw click IDs visible in upload logs?

No. The visible record stores only an attribution type and fingerprint. The raw
click ID remains inside the encrypted request payload.

## What does Sent mean?

It means Data Manager accepted the ingestion request. It does not guarantee that
Google matched the event to an ad click or attributed it to a campaign. Matching
is asynchronous and should be checked in Google diagnostics.

## Where is the Google request ID?

Open the `GoogleAdsConversionUpload` record and inspect `requestId`. Include it
when reviewing Data Manager diagnostics or contacting Google support.

## Why is an upload waiting in RetryScheduled?

The last failure was temporary and its backoff time has not arrived. Ensure the
`Dispatch Pending Google Ads Conversions` scheduled job runs every minute.

## Which failures retry automatically?

Transport failures, HTTP 408, HTTP 429, HTTP 5xx, and temporary Google statuses
such as `UNAVAILABLE`, `RESOURCE_EXHAUSTED`, and `DEADLINE_EXCEEDED` retry.
Configuration and validation errors do not.

## Can the same stage change be uploaded twice?

Normal queue duplication is safe. Each materialized stage entry has a stable
local idempotency key and transaction ID. Terminal rows are not sent again.

## Why does OAuth configuration fail with a tenant error?

The Destination and OAuth Account must both resolve to exactly one identical
tenant through their teams. Teamless or multi-tenant OAuth Accounts are rejected
because they cannot prove safe credential ownership.

## What is the difference between Operating and Login Account IDs?

The Operating Account owns the conversion action. The optional Login Account is
the manager account through which the OAuth user reaches the Operating Account.
Both are 10-digit Google Ads customer IDs entered without hyphens.

## Why does Google reject the conversion action?

Confirm that the action:

- Belongs to the configured Operating Account.
- Has source `Website (Import from clicks)`.
- Has type `UPLOAD_CLICKS`.
- Is active and old enough to accept events.
- Uses the exact numeric ID configured in the Mapping.

## How do I test without ingesting a conversion?

Data Manager supports `validateOnly`, and the internal upload model supports a
Validated status. The MVP does not expose a public UI test action; use a
controlled internal invocation or test tooling rather than making the immutable
upload API writable.

## Does this ingest Google Lead Form submissions?

No. Lead Form ingestion is a separate inbound integration. This module only
sends CRM outcomes back to Google Ads.

## How do I pause delivery?

Disable the Destination or individual Mapping. Existing pending rows are marked
Skipped when dispatched against inactive configuration. To preserve pending rows
for later delivery, pause the scheduled job instead and avoid changing the
configuration.

## What should I check after deployment?

1. Run Espo clear-cache and rebuild.
2. Configure the seeded OAuth Provider credentials.
3. Connect a tenant-scoped OAuth Account.
4. Create a Destination and one Mapping.
5. Enable the retry sweeper scheduled job.
6. Confirm the tracker captures click IDs and explicit consent.
7. Move a controlled Opportunity into the mapped stage.
8. Verify the upload reaches Sent and inspect its Google request ID.
