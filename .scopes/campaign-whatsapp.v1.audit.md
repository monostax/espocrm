# WhatsApp Campaign Scope Audit Report

**Scope Version:** v1  
**Audited On:** 2026-03-03  
**Auditor:** Review Agent  
**Scope File:** `campaign-whatsapp.v1.md`

---

## Audit Summary

**Risk Level:** Critical  
**Files Reviewed:** 15+  
**Findings:** Critical: 4 | Warnings: 5 | Suggestions: 3

The scope document demonstrates solid understanding of the business requirements and data flow, but contains several **critical design-level issues** that must be resolved before implementation. Most notably: entity naming collision with core EspoCRM, missing Chatwoot API methods for template messages, and incorrect file path references.

---

## Readiness Assessment

**Verdict:** BLOCKED

The scope is **NOT ready for implementation** due to critical architectural issues that would cause breaking conflicts with existing EspoCRM functionality. The following design-level issues must be resolved:

1. **Entity naming collision** - The new "Campaign" entity conflicts with EspoCRM's core Campaign entity
2. **Missing Chatwoot API methods** - Template message sending methods do not exist in the current API client
3. **Incorrect file references** - Batch processing reference path is wrong
4. **Database migration strategy** - No migration files specified for new entities

Once these issues are resolved, the scope can proceed to implementation.

---

## Circular Rework Detection

N/A - First audit cycle

---

## Critical Findings (MUST address before implementation)

### 1. Entity Naming Collision with Core EspoCRM Campaign
- **Location:** `entityDefs/Campaign.json`
- **Evidence:** 
  - File exists: `application/Espo/Modules/Crm/Resources/metadata/entityDefs/Campaign.json`
  - TargetList already has link: `"campaigns": {"entity": "Campaign", "foreign": "targetLists"}`
- **Assumption:** The scope assumes it can create a new entity named "Campaign" without conflict
- **Risk:** EspoCRM will fail to load, metadata conflicts, broken existing Campaign functionality
- **Remedy:** Rename the entity to `WhatsAppCampaign` or `WACampaign` throughout all metadata, controllers, services, and frontend code

---

### 2. Missing Chatwoot Template Message API Methods
- **Location:** `Services/ChatwootCampaignApiClient.php`
- **Evidence:** 
  - Existing `ChatwootApiClient.php` has 2294 lines but NO methods for:
    - `sendTemplateMessage()`
    - `findOrCreateConversation()`
    - `findOrCreateContact()`
    - Template message API endpoints
  - Search results: `grep -r "sendMessage\|template" Services/` returns only unrelated matches
- **Assumption:** The scope assumes Chatwoot API client supports WhatsApp Business template messages
- **Risk:** The core campaign execution feature cannot be implemented as designed
- **Remedy:** 
  - Add `sendTemplateMessage()` method using Chatwoot's `/api/v1/accounts/{id}/conversations/{id}/messages` endpoint with template_params
  - Add conversation creation using Chatwoot's conversation API
  - Verify Chatwoot supports WhatsApp template message format: `{content, template_params: {name, category, language, processed_params}}`

---

### 3. Incorrect File Path Reference for Batch Processing Pattern
- **Location:** Scope lines 21, 394
- **Evidence:** 
  - Scope references: `custom/Espo/Modules/SimplesAgenda/Jobs/SyncContactsFromSimplesAgenda.php`
  - Actual path: `custom/Espo/Modules/FeatureIntegrationSimplesAgenda/Jobs/SyncContactsFromSimplesAgenda.php`
- **Assumption:** The referenced file exists at the specified path
- **Risk:** Implementation developer will waste time searching for non-existent file
- **Remedy:** Update scope to reference correct path: `FeatureIntegrationSimplesAgenda/Jobs/SyncContactsFromSimplesAgenda.php`

---

### 4. Missing Database Migration Files
- **Location:** Not listed in manifest
- **Evidence:** 
  - New entities defined: `Campaign`, `CampaignContact`
  - Entity fields with relationships to existing entities
  - No migration files in manifest
- **Assumption:** EspoCRM's metadata rebuild will auto-create tables (insufficient for linkMultiple relationships)
- **Risk:** Database schema won't match entity definitions, foreign key constraints missing
- **Remedy:** Add migration files to manifest:
  ```
  custom/Espo/Modules/FeatureWhatsAppCampaign/Rebuild/CreateCampaignTables.php
  ```
  Or document that standard Espo rebuild is sufficient after entityDefs are created

---

## Warnings (SHOULD address)

### 1. CampaignContact Entity Status Field Values Don't Match Data Flow
- **Location:** `entityDefs/CampaignContact.json` line 155
- **Evidence:** Status options: `[Pending, Processing, Sent, Delivered, Read, Failed]`
- **Concern:** The data flow diagram shows status progression but doesn't account for:
  - Contacts who opt-out mid-campaign
  - Invalid phone numbers (hard bounce vs soft bounce)
  - Messages blocked by Meta policies
- **Suggestion:** Add statuses: `OptedOut`, `Bounced`, `Blocked`

---

### 2. No Opt-out Handling Despite Existing Infrastructure
- **Location:** Decisions table (Decision #5)
- **Evidence:** 
  - TargetList already has `optedOut` column in junction tables (verified in `entityDefs/TargetList.json` lines 175-194)
  - Decision explicitly says "Skip opt-out handling" for MVP
- **Concern:** Legal compliance risk (LGPD/GDPR), wasted sends to opted-out contacts
- **Suggestion:** 
  - At minimum, filter out contacts where `targetListContacts.optedOut = true`
  - Add TODO comment in CampaignService::resolveAudience() to implement opt-out filtering

---

### 3. Meta Template Validator Assumes WABA ID Availability
- **Location:** `Services/TemplateValidator.php`
- **Evidence:** 
  - Method signature: `validateTemplate(string $templateName, string $language, array $sampleParams, string $wabaId)`
  - Campaign entity has `credential` link to Credential entity
- **Concern:** The WABA ID must be extracted from the credential, but the scope doesn't specify how
- **Suggestion:** Document the credential config structure or add a `wabaId` field to Campaign entity

---

### 4. Rate Limit Implementation Uses Blocking Sleep
- **Location:** Scope line 548-550
- **Evidence:** `usleep($microsecondsBetweenSends)` in synchronous job
- **Concern:** 
  - Job timeout risk (default Espo job timeout is often 5 minutes)
  - Campaign of 10,000 contacts at 30 msg/sec = 5.5 minutes minimum
  - With retries and API latency, job will likely timeout
- **Suggestion:** 
  - Use job queue with delayed execution (schedule next batch with delay)
  - Or implement chunked job scheduling where each batch is a separate job

---

### 5. Webhook HMAC Secret Source Unclear
- **Location:** `Controllers/DeliveryWebhook.php` lines 100-109
- **Evidence:** 
  - References HMAC validation following `WahaLabelWebhook.php` pattern
  - WahaLabelWebhook gets secret from `ChatwootInboxIntegration.wahaWebhookSecret`
- **Concern:** Where does the delivery webhook secret come from? Chatwoot or Meta?
- **Suggestion:** 
  - Clarify if this is a Chatwoot webhook (use account API key) or Meta webhook (use verify token)
  - Specify secret storage location (Credential entity? Campaign entity?)

---

## Suggestions (CONSIDER addressing)

### 1. Add CampaignLogRecord Integration
- **Context:** EspoCRM has existing CampaignLogRecord entity for tracking campaign activities
- **Observation:** The scope creates a separate `CampaignContact` entity for status tracking
- **Enhancement:** Consider logging campaign sends to existing `CampaignLogRecord` entity for unified reporting

---

### 2. Reuse Existing Email Campaign Infrastructure
- **Context:** EspoCRM's Campaign entity has `massEmails` relationship and tracking infrastructure
- **Observation:** The WhatsApp campaign duplicates similar functionality (stats, target lists)
- **Enhancement:** Consider extending the existing Campaign entity with a `massWhatsApp` relationship instead of creating a parallel structure

---

### 3. Add Campaign Cancellation/Abort Functionality
- **Context:** Scope defines Pause/Resume but no Abort
- **Observation:** Once a campaign is "Running", there's no way to stop pending sends
- **Enhancement:** Add `postActionAbort()` method to stop campaign and mark remaining Pending contacts as Cancelled

---

## Validated Items

The following aspects of the plan are well-supported:

- ✅ **TargetList Integration Pattern** - Correctly uses existing `targetLists` relationship with `contacts` link (verified in `entityDefs/TargetList.json` lines 183-195)
- ✅ **Batch Processing Size** - BATCH_SIZE = 200 matches existing SimplesAgenda implementation (verified in `SyncContactsFromSimplesAgenda.php` line 46)
- ✅ **Webhook HMAC Pattern** - Follows established pattern in `WahaLabelWebhook.php` (lines 128-171)
- ✅ **MetaGraphApiClient Extension** - Existing `getMessageTemplates()` method exists at line 126 of `MetaGraphApiClient.php`
- ✅ **Job Scheduling Pattern** - Correctly uses `JobSchedulerFactory` pattern (verified in `WahaLabelWebhook.php` lines 103-113)
- ✅ **Credential Type Registration** - Pattern matches existing SeedCredentialTypes.php structure

---

## File Existence Verification

| File Path | Status | Notes |
|-----------|--------|-------|
| `custom/Espo/Modules/Chatwoot/Services/ChatwootApiClient.php` | ✅ EXISTS | 2294 lines, well-established |
| `custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Services/MetaGraphApiClient.php` | ✅ EXISTS | Has getMessageTemplates() |
| `custom/Espo/Modules/SimplesAgenda/Jobs/SyncContactsFromSimplesAgenda.php` | ❌ WRONG PATH | Actual: FeatureIntegrationSimplesAgenda |
| `custom/Espo/Modules/Chatwoot/Controllers/WahaLabelWebhook.php` | ✅ EXISTS | Good reference pattern |
| `custom/Espo/Modules/FeatureCredential/Rebuild/SeedCredentialTypes.php` | ✅ EXISTS | 533 lines |
| `application/Espo/Modules/Crm/Resources/metadata/entityDefs/TargetList.json` | ✅ EXISTS | Verified contacts relationship |
| `application/Espo/Modules/Crm/Resources/metadata/entityDefs/Campaign.json` | ✅ EXISTS | **COLLISION RISK** |

---

## Recommended Next Steps

1. **Rename Campaign entity** to `WhatsAppCampaign` across all files
2. **Add Chatwoot API methods** for template message sending
3. **Update file path reference** to FeatureIntegrationSimplesAgenda
4. **Document database migration strategy** in scope
5. **Clarify webhook secret source** for delivery tracking
6. **Consider non-blocking rate limiting** approach
7. **Re-audit** after critical issues are resolved

---

*Audit completed. This document should be appended to the scope or referenced during implementation planning.*
