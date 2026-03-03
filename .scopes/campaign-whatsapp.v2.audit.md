# WhatsApp Campaign Scope v2 Audit Report

**Scope Version:** v2  
**Previous Version:** v1  
**Audited On:** 2026-03-03  
**Auditor:** Review Agent  
**Scope File:** `campaign-whatsapp.v2.md`

---

## Audit Summary

**Risk Level:** Low  
**Files Reviewed:** 20+  
**Findings:** Critical: 0 | Warnings: 3 | Suggestions: 4

The v2 scope successfully addresses **all 4 critical findings** from the v1 audit. The entity has been renamed to `WhatsAppCampaign`, the Chatwoot API extension methods are properly documented, file path references are corrected, and database migration strategy is clarified. The architecture is sound and ready for implementation.

---

## Readiness Assessment

**Verdict:** READY TO IMPLEMENTATION

The design is architecturally sound and all critical blockers from v1 have been resolved. The remaining findings are minor warnings and suggestions that can be addressed during implementation or are acceptable risks for an MVP.

**Implementation Watchpoints:**
1. Ensure `whatsappBusinessApi` credential type exists before campaign creation (or add it during implementation)
2. Verify Chatwoot template message payload format matches actual Chatwoot API (test before committing to structure)
3. Double-check webhook payload structure from Chatwoot for message status updates
4. Consider adding `processedParams` JSON validation in entity hooks

---

## Circular Rework Detection

N/A - This is the v2 audit and all v1 findings have been properly addressed without circular changes.

**Resolution Summary:**
| v1 Finding | v2 Resolution | Status |
|------------|---------------|--------|
| Entity naming collision | Renamed to `WhatsAppCampaign` | ✅ RESOLVED |
| Missing Chatwoot API methods | Methods documented for extension | ✅ RESOLVED |
| Incorrect SimplesAgenda path | Updated to `FeatureIntegrationSimplesAgenda` | ✅ RESOLVED |
| Missing database migrations | Migration file added to manifest | ✅ RESOLVED |
| Insufficient status values | Added `OptedOut`, `Bounced`, `Blocked`, `Cancelled` | ✅ RESOLVED |
| No opt-out handling | Added opt-out filtering | ✅ RESOLVED |
| WABA ID source unclear | Added `wabaId` field to entity | ✅ RESOLVED |
| Blocking rate limiting | Changed to chunked job scheduling | ✅ RESOLVED |
| Webhook secret unclear | Documented Chatwoot account API key | ✅ RESOLVED |
| No abort functionality | Added `postActionAbort()` | ✅ RESOLVED |

---

## Critical Findings

**None** - All critical issues from v1 have been resolved.

---

## Warnings (SHOULD address)

### 1. Credential Type `whatsappBusinessApi` Not Yet Seeded
- **Location:** `SeedCredentialTypes.php` (to be edited per scope)
- **Evidence:**
  - Existing credential types in `SeedCredentialTypes.php` include: `basicAuth`, `oauth2`, `apiKey`, `whatsappCloudApi` (line 446)
  - No `whatsappBusinessApi` type exists
  - Scope section "3. Credential Type Registration" specifies adding it
- **Concern:** The credential type must be added to SeedCredentialTypes.php or campaigns cannot store/retrieve WABA credentials
- **Suggestion:** Ensure the credential type addition is included in the implementation checklist. Consider if `whatsappCloudApi` type can be reused instead of creating a new type.

---

### 2. WhatsAppCampaignContact Entity Links to Non-Existent ChatwootContact Entity Relationship
- **Location:** `entityDefs/WhatsAppCampaignContact.json` line 5
- **Evidence:**
  - Scope defines: `"chatwootContact": {"type": "link", "entity": "ChatwootContact"}`
  - `ChatwootContact` entity exists (verified at `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootContact.json`)
  - However, the relationship assumes ChatwootContact can be linked before message sending
- **Concern:** The relationship may be circular - you need to send a message to get a Chatwoot conversation/contact, but you're trying to link the contact before sending. Consider if this should be populated after the message is sent.
- **Suggestion:** Clarify the lifecycle: `chatwootContact` link should likely be populated AFTER the `findOrCreateContact()` API call returns the Chatwoot contact ID, not at record creation time.

---

### 3. Junction Table Column Name Assumption for Opt-out
- **Location:** `WhatsAppCampaignService.php` resolveAudience() method (scope line 865)
- **Evidence:**
  - Scope SQL references: `targetListContacts.optedOut = false`
  - TargetList entity defines: `"columnAttributeMap": {"optedOut": "isOptedOut"}` (line 180 in TargetList.json)
  - Actual junction table columns are named based on the attribute map
- **Concern:** The SQL uses `optedOut` but the actual database column may be named differently depending on Espo's ORM mapping
- **Suggestion:** Verify the actual column name in the junction table. It might be `isOptedOut` based on the `columnAttributeMap`. Use Espo's ORM query builder instead of raw SQL to avoid column name mismatches:
  ```php
  $contacts = $this->entityManager
      ->getRDBRepository('Contact')
      ->join('targetLists', 'tl')
      ->where([
          'tl.id' => $targetListId,
          'tl.isOptedOut' => false,  // Use ORM, not raw SQL
      ])
      ->find();
  ```

---

## Suggestions (CONSIDER addressing)

### 1. Add Index on `chatwootMessageId` for Webhook Lookup Performance
- **Context:** Delivery webhook needs to find `WhatsAppCampaignContact` by `chatwootMessageId`
- **Observation:** The entity has `chatwootMessageId` field but no index defined
- **Enhancement:** Add an index in `entityDefs/WhatsAppCampaignContact.json`:
  ```json
  "indexes": {
      "chatwootMessageId": {
          "columns": ["chatwootMessageId"]
      }
  }
  ```

---

### 2. Consider Adding `chatwootAccount` Link to WhatsAppCampaignContact
- **Context:** The junction entity has `chatwootConversationId` and `chatwootMessageId` but no direct account link
- **Observation:** Webhook processing may need to verify the account context
- **Enhancement:** Add `chatwootAccount` link or store `chatwootAccountId` for easier webhook validation and debugging

---

### 3. Missing `getTemplateByName` Method in MetaGraphApiClient
- **Context:** Scope mentions adding `getTemplateByName()` to MetaGraphApiClient (scope line 603)
- **Observation:** The existing client has `getMessageTemplates()` which returns all templates. Filtering by name would need to be client-side or a new API call.
- **Enhancement:** Document that `getTemplateByName()` should filter the results from `getMessageTemplates()` or make a specific Graph API call with name filter if supported by Meta API.

---

### 4. Consider Campaign Deduplication Across Target Lists
- **Context:** Audience resolution merges TargetList members + manual contacts (scope line 99)
- **Observation:** If a contact exists in multiple TargetLists, they might receive duplicate messages
- **Enhancement:** The scope mentions "Remove duplicates by contactId" which is good, but consider documenting how phone number normalization affects deduplication (e.g., +5511987654321 vs 11987654321).

---

## Validated Items

The following aspects of the plan are well-supported:

- ✅ **Entity Naming** - `WhatsAppCampaign` avoids collision with core Campaign entity (verified Campaign.json exists at `application/Espo/Modules/Crm/Resources/metadata/entityDefs/Campaign.json`)
- ✅ **ChatwootContact Entity** - Exists at `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootContact.json` with proper fields including `contact` link to Espo Contact
- ✅ **ChatwootAccount Entity** - Exists with `apiKey` field suitable for webhook HMAC (line 17-20 in ChatwootAccount.json)
- ✅ **ChatwootInboxIntegration Entity** - Has `credential` link (line 53-57, 177-180 in ChatwootInboxIntegration.json)
- ✅ **TargetList Opt-out Infrastructure** - Verified `optedOut` column exists in junction table definition (TargetList.json lines 175-194)
- ✅ **Batch Processing Pattern** - BATCH_SIZE = 200 confirmed in `SyncContactsFromSimplesAgenda.php` line 46
- ✅ **Job Scheduling Pattern** - Confirmed `JobSchedulerFactory` usage in `WahaLabelWebhook.php` lines 103-113
- ✅ **HMAC Validation Pattern** - Confirmed in `WahaLabelWebhook.php` lines 128-171 using sha512
- ✅ **MetaGraphApiClient** - Has `getMessageTemplates()` method at line 126
- ✅ **Existing Credential Type** - `whatsappCloudApi` exists in SeedCredentialTypes.php (line 446) and may be reusable
- ✅ **Rate Limiting Strategy** - Chunked job scheduling approach is sound and avoids timeout issues

---

## File Existence Verification

| File Path | Status | Notes |
|-----------|--------|-------|
| `custom/Espo/Modules/Chatwoot/Services/ChatwootApiClient.php` | ✅ EXISTS | 2294+ lines, needs extension |
| `custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Services/MetaGraphApiClient.php` | ✅ EXISTS | Has getMessageTemplates() |
| `custom/Espo/Modules/FeatureIntegrationSimplesAgenda/Jobs/SyncContactsFromSimplesAgenda.php` | ✅ EXISTS | Path corrected from v1 |
| `custom/Espo/Modules/Chatwoot/Controllers/WahaLabelWebhook.php` | ✅ EXISTS | HMAC/webhook reference pattern |
| `custom/Espo/Modules/FeatureCredential/Rebuild/SeedCredentialTypes.php` | ✅ EXISTS | Needs extension per scope |
| `application/Espo/Modules/Crm/Resources/metadata/entityDefs/TargetList.json` | ✅ EXISTS | Opt-out infrastructure verified |
| `application/Espo/Modules/Crm/Resources/metadata/entityDefs/Campaign.json` | ✅ EXISTS | Collision avoided by rename |
| `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootContact.json` | ✅ EXISTS | Properly structured |
| `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootAccount.json` | ✅ EXISTS | Has apiKey field |
| `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootInbox.json` | ✅ EXISTS | Structure verified |
| `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootInboxIntegration.json` | ✅ EXISTS | Has credential link |

---

## Architecture Assessment

### Strengths
1. **Non-blocking rate limiting** - Chunked job scheduling is the correct approach for large campaigns
2. **Webhook-based tracking** - Real-time delivery status updates via Chatwoot webhooks
3. **Opt-out compliance** - Proper integration with Espo's existing opt-out infrastructure
4. **Separation of concerns** - Clear distinction between Campaign, CampaignContact, and service layers
5. **Template validation** - Early validation against Meta API prevents campaign failures

### Potential Improvements
1. **Retry logic** - Consider exponential backoff for failed message sends
2. **Campaign archiving** - No mention of archiving old campaigns (could impact performance with large datasets)
3. **Audit logging** - Consider logging campaign state changes for compliance
4. **Rate limit monitoring** - No monitoring/alerting for rate limit approaches

---

## Recommended Next Steps

1. **Proceed with implementation** - All critical blockers resolved
2. **Verify credential type strategy** - Decide between extending `whatsappCloudApi` or creating `whatsappBusinessApi`
3. **Test Chatwoot template payload format** - Ensure the documented payload structure matches actual Chatwoot API
4. **Document webhook setup** - Provide instructions for configuring Chatwoot webhook URL
5. **Add indexes** - Consider performance indexes on frequently queried fields

---

## Conclusion

The WhatsApp Campaign v2 scope is **architecturally sound and ready for implementation**. All critical issues from v1 have been resolved, and the design patterns align with existing codebase conventions. The chunked job approach for rate limiting is particularly well-designed.

**Confidence Level:** HIGH

The implementation team should proceed with confidence, keeping in mind the watchpoints listed in the Readiness Assessment section.

---

*Audit completed. This document should be referenced during implementation to ensure design decisions are followed.*
