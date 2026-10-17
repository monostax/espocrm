# Google Meet import — operations

Implements the locked **Google Meet Import into Meeting** ADR (D1–D38). CRM owns authorization, source identity, jobs, publication and private storage. Chatwoot supplies the three Meeting tabs and the account/session/review/results flow.

## Deployment

1. Deploy CRM and Chatwoot together. Run the normal CRM rebuild (`php command.php rebuild`) to install the four internal entities, indexes, scope additions and `MaintainMeetingGoogleImports` scheduled job. Keep the release switch off until launch checks are complete.
2. Configure the existing Google Meet OAuthProvider `msx_gmeet_01` with the deployment's client ID and encrypted client secret. Enable the Meet, Docs and Drive APIs in its Google project. Rebuild retains the existing Meet scopes and adds only `drive.meet.readonly` and `documents.readonly`; there is no broader Drive fallback.
3. Register **`<CRM siteUrl>/api/v1/MeetingGoogleImport/callback`** as an exact Google redirect URI. `siteUrl` is trusted server configuration, never supplied by the browser.
4. Set these CRM configuration values through the deployment's configuration writer:

   ```php
   'meetingGoogleImportEnabled' => false,
   'meetingGoogleImportOrigins' => ['https://<chatwoot-origin>'],
   ```

   Origins must be exact scheme/host/port values, without paths, trailing slashes or wildcards. Set the switch to `true` after the production gates below pass. Missing configuration defaults to disabled.
5. Ensure the normal CRM job daemon and minutely scheduler run. The scheduled maintenance job repairs missing queued delivery, fails overdue running attempts, checks storage and cleans abandoned files. Failed operations require a fresh user review and manual retry.
6. All CRM web/worker processes must use the **same private `data/upload` volume**, with working cross-process `flock`, atomic same-filesystem rename, and 64-bit PHP. Files use `meeting-stage-<hash>` and `meeting-recording-<hash>` names. Verify the web server denies direct access to `data/`. Keep storage and database backups consistent under the existing retention policy.
7. Verify credentials are allowed on CRM-origin API/video requests, including GET, HEAD and Range. Configure CORS for the exact Chatwoot origin; do not use wildcard credentialed CORS. Verify the production cookie domain, Secure and SameSite behavior, popup callback and actual browser seeking. Ensure proxies stream response bodies instead of buffering an entire clip.

### Initial Google rollout (D38)

Configure audience **External**, publishing status **In production**. Customers connect themselves; there is no application-side test-user allowlist or per-customer approval workflow. Normal CRM role/team eligibility and Google's own organization policies still apply.

Record actual remaining lifetime account quota in Google Cloud Console before enabling onboarding. The nominal unverified-project cap is 100 authorizing Google accounts, not organizations or concurrent connections. Previous authorizations consume capacity; disconnecting does not recover slots. CRM-only viewers do not consume slots. Do not infer remaining capacity from local Credential counts. Testing-mode seven-day authorization expiry is not the expected behavior of an In production app.

The approved rollout defers completed scope verification and applicable annual CASA assessment as internal launch prerequisites. It does not waive Google's production policy. Review quota/usage and verification lead time as adoption grows; Google can restrict authorization before the nominal cap. No fixed verification date, budget or usage threshold is assumed.

## Contracts and storage

- `MeetingGoogleImport`: unique Meeting/source binding and nullable transcript Document reference.
- `MeetingGoogleUnit`: unique source artifact or notes document/tab pair; successes are permanent copy-once markers.
- `MeetingGoogleAttempt`: exact unit set, initiating user, selected Credential, destination precondition, status, deadline and fencing token.
- `MeetingGoogleOAuth`: expiring, single-use browser-session state and encrypted PKCE verifier. Expired rows are removed by maintenance.

These entities deny generic CRUD. Use the authenticated Meeting-specific routes. No import path provisions a new Meeting. Source replacement is rejected. Existing `RecordDocument.overviewDocumentId` remains the overview; transcript reads never provision a Document.

Notes and transcript writes preserve existing Lexical children and publish content, revisions, transcript binding and success markers in the same destination transaction. Each resulting Markdown body is capped at **2,000,000 bytes**, without truncation. Notes fetch the selected tabs at worker time; a missing selected tab or a failed page commits none of that notes batch. First transcript failure leaves no empty transcript Document.

Recordings stream from server-resolved Drive IDs into private staging, enforce **5,368,709,120 bytes** while writing, validate byte count/checksum when supplied, and publish by rename plus fenced database commit. There is no generic Attachment record or alternate Attachment URL for these files. `Files` uses the existing `EspoUploadDir` location directly and does not call its buffering `putStream` method. Playback authenticates and checks current Meeting/recording access on every GET/HEAD/range. Completed copies do not require Google access.

Soft deletion denies playback and soft-deletes both owned Documents. Restoration retains the same Document IDs and recordings. Physical Meeting purge releases recording files through maintenance; owned Documents follow existing retention. The transcript cannot be independently unlinked, shared or deleted; its content remains editable/clearable.

## API reference

Routes have prefix `/api/v1/MeetingGoogleImport`. Pass `accountId` and `meetingId` as query parameters unless the Meeting ID is in the path.

| Method/path | Purpose |
|---|---|
| `GET /connections` | Accessible workspace connections, consent capabilities, reconnect permission and eligible setup teams |
| `POST /connect` | `{teamId}` for new connections, or `{credentialId}` for reconnect; returns popup URL/state, never tokens |
| `GET /callback` | Normal CRM browser authentication plus session/state/PKCE checks; strict `postMessage` reply |
| `GET /sessions` | `credentialId`, optional opaque `cursor`; 25 completed sessions per page |
| `POST /preview` | `{credentialId, conference}`; source artifacts, sizes, notes hierarchy and imported state |
| `POST /start`, `POST /retry` | Durable HTTP 202 acceptance; source, optional kind flags, explicit notes selection and destination preconditions |
| `GET /status` | Authorized unit outcomes, safe error categories, omission warnings and nonterminal indicator |
| `GET /transcript` | Non-provisioning, Meeting-owned Document read |
| `PUT /transcript` | Normal editor body/state with `X-Version-Number` |
| `GET`, `HEAD /:meetingId/recordings/:unitId` | Private completed MP4; single byte ranges, 206/416, no transferable playback token |

Example Start body:

```json
{
  "credentialId": "<accessible-credential>",
  "conference": "conferenceRecords/<session>",
  "transcript": true,
  "recording": true,
  "notes": [{"documentId": "<Meet-discovered-doc>", "tabId": "<explicit-tab>"}],
  "destinations": {
    "notes": {"documentId": "<overview>", "versionNumber": 7},
    "transcript": {"documentId": null, "versionNumber": null}
  }
}
```

Omit notes and its precondition if no tabs are selected; omit transcript's precondition for recording/notes-only requests. Existing transcript imports require its actual ID/version. Chatwoot saves only selected text destinations before submission. Parent-tab selection does not select children. Repeat compatible submissions reuse unfinished active work and never refresh successes.

## Monitoring and incident response

| Signal/default | Response |
|---|---|
| Maintenance heartbeat missing / worker unavailable | Alert the CRM operations owner; inspect scheduler and job daemon before enabling more work |
| Oldest queued attempt exceeds **15 minutes** | Error log; inspect worker health, transfer lock and backlog; check workload against one-transfer capacity |
| Less than **5 GiB** free on upload volume | Error log; restore capacity including retained clips and backup overhead |
| Cleanup failure | Error log; investigate permissions/volume, and restore minutely maintenance |
| Recording exceeds **60 minutes** | Fail fenced attempt; remove staging; user reviews and retries manually |
| Notes/transcript exceeds **5 minutes** | Fail that text attempt; no partial publication; user reviews and retries manually |

Wire alerts to the deployment's monitoring receiver before launch; logging alone is not a configured alert channel. Assign the on-call CRM operations owner, Google project/quota owner and backup/retention owner in the deployment record.

The shared-volume advisory lock permits **one active recording transfer deployment-wide**, independent of duplicate job delivery. Queue groups are per attempt. Maintenance avoids enqueuing an already pending/ready/running delivery. A process death releases the file lock; a running attempt becomes failed at its deadline, and its fence cannot publish thereafter. Do not reset successful markers or manually change attempt fences to retry.

Staging and unreferenced renamed files older than **23 hours** are removed by minutely maintenance, providing a <24-hour cleanup target while maintenance is healthy. Completed referenced files have no age expiry. Never delete all `meeting-recording-*` files to clear staging. Investigate cleanup failures promptly.

Logs include attempt IDs, kind, safe category, duration and byte counts. Do not add tokens, OAuth codes, raw Google responses or document content to logs. Proxy access logs should redact query strings on the OAuth callback.

### Common user-visible outcomes

- `conflict`: open the affected editor, resolve local/saved content, then review again with the new version. The other kind's work remains independent.
- `selectedTabMissing`: review the notes hierarchy again; no selected tab in that attempt was committed.
- `reconnect` / `authorizationDenied`: reconnect; check Google quota, organizational restrictions and consent. Completed copies remain available under CRM permissions.
- `sourceMissing`: source unavailable/expired; Meet transcript entries expire after 30 days. There is no Docs fallback for old transcripts.
- `tooLarge`, `incompleteTransfer`, `unsupportedMedia`: the affected destination/clip was not published. Clips are MP4; no transcoding or codec guarantee is provided.
- `denied`: recheck current workspace, Meeting, destination, Credential and field permissions. Workers use fresh initiating-user authorization before fetch and publication.

## Rollback and production gates

Set `meetingGoogleImportEnabled` to `false` to stop new starts/retries/connections. Accepted work can finish. Retain compatible transcript read/edit, playback, ownership and maintenance paths, schema and files. Do not roll back by deleting Documents, success markers or storage.

Before production activation, record evidence for External/In production consent and actual quota, a real external customer's self-service OAuth and refresh flow, production 5-GiB transfer and authenticated browser seek/HEAD/CORS, shared-volume lock semantics, expected workload/capacity, alert delivery and assigned operational owners. These are deployment checks, not facts established by local fixture validation.
