# WhatsApp Campaign Scope v3 - Implementation Ready

**Scope Version:** v3  
**Previous Version:** v2 (audited, all critical issues resolved)  
**Status:** READY FOR IMPLEMENTATION  
**Complexity:** HIGH - Multi-entity, webhook-based, rate-limited messaging system

---

## Overview

Implement a comprehensive WhatsApp Campaign system that allows marketers to send bulk WhatsApp template messages via Chatwoot API, with real-time delivery tracking, opt-out compliance, and non-blocking rate limiting.

### Core Capabilities

1. **Campaign Management** - Create, schedule, and monitor WhatsApp campaigns
2. **Audience Targeting** - TargetList-based audience with opt-out filtering
3. **Template Validation** - Validate templates against Meta Graph API before sending
4. **Non-blocking Delivery** - Chunked job scheduling to handle rate limits
5. **Real-time Tracking** - Webhook-based delivery status updates
6. **Opt-out Compliance** - Automatic filtering using Espo's existing infrastructure

---

## Decisions

| #   | Decision                                               | Alternatives Considered               | Rationale                                                                                                               |
| --- | ------------------------------------------------------ | ------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| 1   | **Reuse `whatsappCloudApi` credential type**           | Create new `whatsappBusinessApi` type | Existing type already contains accessToken, businessAccountId, and phoneNumberId fields needed for Meta Graph API calls |
| 2   | **Populate `chatwootContact` link AFTER message send** | Link at record creation time          | Contact must exist in Chatwoot before linking; populated via `findOrCreateContact()` API response                       |
| 3   | **Use ORM query builder for opt-out filtering**        | Raw SQL with column names             | TargetList junction table uses `columnAttributeMap` - ORM handles mapping automatically                                 |
| 4   | **Add index on `chatwootMessageId`**                   | No index                              | Webhook lookups need O(1) performance for delivery status updates                                                       |
| 5   | **Store `chatwootAccountId` in junction entity**       | Link only                             | Webhook validation requires account context without extra JOIN                                                          |
| 6   | **Filter templates client-side**                       | Meta API name filter                  | Meta Graph API message_templates endpoint doesn't support name filtering                                                |
| 7   | **Phone normalization for deduplication**              | Exact match only                      | Brazilian numbers may have multiple formats; normalize to E.164 (+55...)                                                |
| 8   | **Separate WhatsAppCampaign entity**                   | Extend core Campaign entity           | Core Campaign entity is tightly coupled to email marketing; avoids collision                                            |

---

## Architecture

### Entity Model

```
┌─────────────────────┐     ┌──────────────────────────┐     ┌─────────────────┐
│   WhatsAppCampaign  │────▶│ WhatsAppCampaignContact  │◄────│     Contact     │
├─────────────────────┤ 1:M ├──────────────────────────┤ M:1 ├─────────────────┤
│ - name              │     │ - status                 │     │ - phoneNumber   │
│ - templateName      │     │ - chatwootMessageId      │     │ - firstName     │
│ - templateLanguage  │     │ - chatwootConversationId │     │ - lastName      │
│ - wabaId            │     │ - processedParams (JSON) │     └─────────────────┘
│ - processedParams   │     │ - sentAt                 │              │
│ - status            │     │ - deliveredAt            │              │
│ - scheduledAt       │     │ - failedAt               │              ▼
│ - sentCount         │     │ - failedReason           │     ┌─────────────────┐
│ - deliveredCount    │     └──────────────────────────┘     │ ChatwootContact │
│ - failedCount       │              │                       └─────────────────┘
└─────────────────────┘              │                                │
         │                           └────────────────────────────────┘
         │                              (populated after send)
         ▼
┌─────────────────────┐
│      TargetList     │
└─────────────────────┘
         │
         ▼
┌─────────────────────┐
│  ChatwootInboxIntegration  │
│  (linked credential) │
└─────────────────────┘
```

### Data Flow

```
1. CREATE CAMPAIGN
   User → WhatsAppCampaign entity → Validate template via MetaGraphApiClient
                                       ↓
                            Template exists and APPROVED?
                                       ↓
                            Save campaign with status "Draft"

2. LAUNCH CAMPAIGN
   User clicks "Send" → WhatsAppCampaignService::launch()
                              ↓
                    resolveAudience() → Filter opt-outs via ORM
                              ↓
                    Create WhatsAppCampaignContact records
                              ↓
                    Schedule ProcessWhatsAppCampaignChunk jobs
                              ↓
                    Update status → "Sending"

3. PROCESS CHUNKS (Async Jobs)
   ProcessWhatsAppCampaignChunk::run()
           ↓
   For each contact in chunk:
     - Call ChatwootApiClient::findOrCreateContact()
     - Call ChatwootApiClient::sendTemplateMessage()
     - Update WhatsAppCampaignContact record
     - Update WhatsAppCampaign counters

4. WEBHOOK UPDATES
   Chatwoot → DeliveryWebhookController::postActionReceive()
                   ↓
            Validate HMAC (Chatwoot account apiKey)
                   ↓
            Find WhatsAppCampaignContact by chatwootMessageId
                   ↓
            Update status (delivered/read/failed)
                   ↓
            Update WhatsAppCampaign counters

5. CAMPAIGN COMPLETION
   When all chunks processed → Status "Completed"
   If abort requested → Status "Cancelled" (stop remaining jobs)
```

---

## File Manifest

### Files to CREATE (ordered by complexity/risk, highest first)

#### 1. `custom/Espo/Modules/Chatwoot/Services/WhatsAppCampaignService.php`

**Purpose:** Core business logic for campaign lifecycle management  
**Complexity:** HIGH - Orchestrates template validation, audience resolution, job scheduling

**Key Patterns:**

- Follow `ChatwootInboxIntegration.php` service pattern with dependency injection
- Use batch processing pattern from `SyncContactsFromSimplesAgenda.php` (BATCH_SIZE = 200)
- Use transaction management for atomic operations

**Key Methods:**

- `validateTemplate(string $templateName, string $language, string $wabaId): bool`
    - Use MetaGraphApiClient::getMessageTemplates() and filter client-side by name
    - Verify template status is "APPROVED"
- `resolveAudience(string $campaignId): array`
    - Use ORM query builder, NOT raw SQL (see Warning #3 in v2 audit)
    - Filter optedOut via `where(['tl.isOptedOut' => false])`
    - Merge TargetList contacts + manual contacts
    - Remove duplicates by normalized phone number
- `launch(string $campaignId): void`
    - Validate campaign can be launched
    - Resolve audience
    - Create junction records
    - Schedule chunk jobs via JobSchedulerFactory
- `getCampaignStats(string $campaignId): array`
    - Aggregate counts from WhatsAppCampaignContact records
- `abort(string $campaignId): void`
    - Update status to "Cancelled"
    - Remaining jobs will check status and skip processing

**Dependencies:**

- EntityManager, MetaGraphApiClient, ChatwootApiClient, Log, JobSchedulerFactory

---

#### 2. `custom/Espo/Modules/Chatwoot/Jobs/ProcessWhatsAppCampaignChunk.php`

**Purpose:** Async job to process a chunk of campaign contacts  
**Complexity:** HIGH - API rate limiting, error handling, idempotency

**Key Patterns:**

- Implement `JobDataLess` interface
- Follow `ProcessWahaLabelWebhook.php` pattern for job structure
- Use BATCH_SIZE = 50 (smaller than SimplesAgenda due to API rate limits)

**Key Logic:**

```php
public function run(): void
{
    // Check if campaign was cancelled
    $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $this->campaignId);
    if ($campaign->get('status') === 'Cancelled') {
        return; // Skip processing
    }

    // Get contacts for this chunk
    $contacts = $this->getChunkContacts($this->chunkOffset, self::CHUNK_SIZE);

    foreach ($contacts as $campaignContact) {
        try {
            // Rate limiting: sleep between sends
            usleep(self::RATE_LIMIT_DELAY_MS * 1000);

            // Find or create Chatwoot contact
            $chatwootContact = $this->chatwootApiClient->findOrCreateContact(
                $campaignContact->get('phoneNumber'),
                $campaignContact->get('contactName')
            );

            // Update link to ChatwootContact (see Decision #2)
            $campaignContact->set('chatwootContactId', $chatwootContact['id']);

            // Send template message
            $result = $this->chatwootApiClient->sendTemplateMessage(
                $campaignContact->get('chatwootAccountId'),
                $chatwootContact['id'],
                $this->templateName,
                $this->templateLanguage,
                $campaignContact->get('processedParams')
            );

            // Update record
            $campaignContact->set([
                'status' => 'Sent',
                'chatwootMessageId' => $result['message_id'],
                'chatwootConversationId' => $result['conversation_id'],
                'sentAt' => date('Y-m-d H:i:s'),
            ]);

            $this->entityManager->saveEntity($campaignContact);
            $this->incrementCampaignCounter('sentCount');

        } catch (\Exception $e) {
            $campaignContact->set([
                'status' => 'Failed',
                'failedAt' => date('Y-m-d H:i:s'),
                'failedReason' => $e->getMessage(),
            ]);
            $this->entityManager->saveEntity($campaignContact);
            $this->incrementCampaignCounter('failedCount');
        }
    }

    // Check if all chunks complete
    $this->checkCampaignCompletion();
}
```

**Dependencies:**

- EntityManager, ChatwootApiClient, Log

---

#### 3. `custom/Espo/Modules/Chatwoot/Controllers/DeliveryWebhookController.php`

**Purpose:** Receive and validate Chatwoot message status webhooks  
**Complexity:** HIGH - HMAC validation, idempotency, security

**Key Patterns:**

- Follow `WahaLabelWebhook.php` exactly for HMAC validation
- Use sha512 algorithm (confirmed in WahaLabelWebhook line 167)

**Key Logic:**

```php
public function postActionReceive(Request $request, Response $response): stdClass
{
    $accountId = $request->getRouteParam('accountId');
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody);

    // Get account for HMAC validation
    $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
    if (!$account) {
        throw new NotFound();
    }

    // Validate HMAC using account apiKey (see v2 audit verification)
    $signature = $this->getSignatureFromHeaders();
    if (!$this->validateHmacSignature($rawBody, $signature, $account->get('apiKey'))) {
        throw new Forbidden('Invalid signature');
    }

    // Find campaign contact by chatwootMessageId (indexed field, see Decision #4)
    $campaignContact = $this->entityManager
        ->getRDBRepository('WhatsAppCampaignContact')
        ->where(['chatwootMessageId' => $data->message_id])
        ->findOne();

    if (!$campaignContact) {
        return (object) ['success' => true, 'message' => 'Message not found in campaigns'];
    }

    // Update status based on webhook event
    $status = match($data->event) {
        'message_delivered' => 'Delivered',
        'message_read' => 'Read',
        'message_failed' => 'Failed',
        default => null,
    };

    if ($status) {
        $campaignContact->set('status', $status);
        if ($status === 'Delivered') {
            $campaignContact->set('deliveredAt', date('Y-m-d H:i:s'));
        }
        $this->entityManager->saveEntity($campaignContact);

        // Update campaign counters
        $this->updateCampaignCounters($campaignContact->get('whatsAppCampaignId'));
    }

    return (object) ['success' => true];
}
```

**Dependencies:**

- EntityManager, Log

---

#### 4. `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/WhatsAppCampaign.json`

**Purpose:** Main campaign entity definition  
**Complexity:** MEDIUM - Field definitions, relationships, indexes

**Key Fields:**

```json
{
    "fields": {
        "name": { "type": "varchar", "required": true, "maxLength": 255 },
        "status": {
            "type": "enum",
            "options": [
                "Draft",
                "Scheduled",
                "Sending",
                "Completed",
                "Cancelled",
                "Paused"
            ],
            "default": "Draft"
        },
        "templateName": {
            "type": "varchar",
            "required": true,
            "maxLength": 255
        },
        "templateLanguage": {
            "type": "varchar",
            "required": true,
            "maxLength": 10
        },
        "wabaId": {
            "type": "varchar",
            "required": true,
            "maxLength": 64,
            "tooltip": true
        },
        "credential": { "type": "link", "required": true },
        "targetLists": { "type": "linkMultiple", "required": false },
        "manualContacts": { "type": "linkMultiple", "entity": "Contact" },
        "processedParams": { "type": "jsonArray" },
        "scheduledAt": { "type": "datetime", "required": false },
        "startedAt": { "type": "datetime", "readOnly": true },
        "completedAt": { "type": "datetime", "readOnly": true },
        "sentCount": { "type": "int", "readOnly": true, "default": 0 },
        "deliveredCount": { "type": "int", "readOnly": true, "default": 0 },
        "readCount": { "type": "int", "readOnly": true, "default": 0 },
        "failedCount": { "type": "int", "readOnly": true, "default": 0 },
        "totalRecipients": { "type": "int", "readOnly": true, "default": 0 }
    },
    "links": {
        "credential": { "type": "belongsTo", "entity": "Credential" },
        "targetLists": {
            "type": "hasMany",
            "entity": "TargetList",
            "foreign": "whatsAppCampaigns"
        },
        "manualContacts": {
            "type": "hasMany",
            "entity": "Contact",
            "relationName": "whatsAppCampaignManualContact"
        },
        "campaignContacts": {
            "type": "hasMany",
            "entity": "WhatsAppCampaignContact",
            "foreign": "whatsAppCampaign"
        }
    },
    "indexes": {
        "status": { "columns": ["status", "deleted"] },
        "scheduledAt": { "columns": ["scheduledAt", "deleted"] }
    }
}
```

---

#### 5. `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/WhatsAppCampaignContact.json`

**Purpose:** Junction entity between campaign and contacts  
**Complexity:** MEDIUM - Tracks individual message status

**Key Fields:**

```json
{
    "fields": {
        "whatsAppCampaign": { "type": "link", "required": true },
        "contact": { "type": "link", "entity": "Contact", "required": true },
        "chatwootContact": {
            "type": "link",
            "entity": "ChatwootContact",
            "required": false
        },
        "chatwootAccount": {
            "type": "link",
            "entity": "ChatwootAccount",
            "required": true
        },
        "chatwootMessageId": {
            "type": "varchar",
            "maxLength": 64,
            "tooltip": true
        },
        "chatwootConversationId": {
            "type": "varchar",
            "maxLength": 64,
            "tooltip": true
        },
        "phoneNumber": { "type": "varchar", "maxLength": 50, "required": true },
        "contactName": { "type": "varchar", "maxLength": 255 },
        "status": {
            "type": "enum",
            "options": [
                "Pending",
                "Sent",
                "Delivered",
                "Read",
                "Failed",
                "OptedOut",
                "Bounced",
                "Blocked"
            ],
            "default": "Pending"
        },
        "processedParams": { "type": "jsonObject" },
        "sentAt": { "type": "datetime", "readOnly": true },
        "deliveredAt": { "type": "datetime", "readOnly": true },
        "readAt": { "type": "datetime", "readOnly": true },
        "failedAt": { "type": "datetime", "readOnly": true },
        "failedReason": { "type": "text", "readOnly": true }
    },
    "links": {
        "whatsAppCampaign": {
            "type": "belongsTo",
            "entity": "WhatsAppCampaign"
        },
        "contact": { "type": "belongsTo", "entity": "Contact" },
        "chatwootContact": { "type": "belongsTo", "entity": "ChatwootContact" },
        "chatwootAccount": { "type": "belongsTo", "entity": "ChatwootAccount" }
    },
    "indexes": {
        "chatwootMessageId": { "columns": ["chatwootMessageId"] },
        "campaignStatus": { "columns": ["whatsAppCampaignId", "status"] },
        "contactCampaign": {
            "columns": ["contactId", "whatsAppCampaignId"],
            "unique": true
        }
    }
}
```

**Note on Index:** `chatwootMessageId` index added per v2 audit Suggestion #1

---

#### 6. `custom/Espo/Modules/Chatwoot/Services/ChatwootApiClient.php` (EXTENSION)

**Purpose:** Add campaign-specific methods to existing API client  
**Complexity:** MEDIUM - Chatwoot API integration

**Add Methods:**

```php
/**
 * Find existing contact or create new one in Chatwoot.
 *
 * @param int $accountId Chatwoot account ID
 * @param string $phoneNumber Normalized phone number (+55...)
 * @param string|null $name Contact name
 * @return array{id: int, name: string, phone_number: string}
 * @throws Error
 */
public function findOrCreateContact(int $accountId, string $phoneNumber, ?string $name = null): array

/**
 * Send WhatsApp template message via Chatwoot.
 *
 * @param int $accountId Chatwoot account ID
 * @param int $contactId Chatwoot contact ID
 * @param string $templateName Meta template name
 * @param string $language Template language code (e.g., "pt_BR")
 * @param array<string, string> $params Template parameters
 * @return array{message_id: int, conversation_id: int}
 * @throws Error
 */
public function sendTemplateMessage(
    int $accountId,
    int $contactId,
    string $templateName,
    string $language,
    array $params
): array
```

---

#### 7. `custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Services/MetaGraphApiClient.php` (EXTENSION)

**Purpose:** Add template filtering helper  
**Complexity:** LOW

**Add Method:**

```php
/**
 * Get template by name from WABA.
 * Filters results from getMessageTemplates() client-side.
 *
 * @param string $accessToken Meta access token
 * @param string $businessAccountId WABA ID
 * @param string $templateName Template name to find
 * @param string $apiVersion API version
 * @return array<string, mixed>|null Template data or null if not found
 */
public function getTemplateByName(
    string $accessToken,
    string $businessAccountId,
    string $templateName,
    string $apiVersion = self::DEFAULT_API_VERSION
): ?array {
    $templates = $this->getMessageTemplates($accessToken, $businessAccountId, $apiVersion);

    foreach ($templates as $template) {
        if (($template['name'] ?? null) === $templateName) {
            return $template;
        }
    }

    return null;
}
```

---

#### 8. `custom/Espo/Modules/Chatwoot/Resources/metadata/app/controllers.json` (EXTENSION)

**Purpose:** Register webhook controller route  
**Complexity:** LOW

**Add Entry:**

```json
{
    "DeliveryWebhook": {
        "controllerClassName": "Espo\\Modules\\Chatwoot\\Controllers\\DeliveryWebhookController"
    }
}
```

---

#### 9. `custom/Espo/Modules/Chatwoot/Resources/metadata/scopes/WhatsAppCampaign.json`

**Purpose:** Entity scope definition for access control  
**Complexity:** LOW

```json
{
    "entity": true,
    "layouts": true,
    "tab": true,
    "acl": true,
    "aclActionList": ["read", "edit", "delete"],
    "aclLevelList": ["all", "team", "own", "no"],
    "aclFieldLevelList": ["yes", "no"]
}
```

---

#### 10. `custom/Espo/Modules/Chatwoot/Resources/metadata/scopes/WhatsAppCampaignContact.json`

**Purpose:** Junction entity scope (utility entity, hidden from UI)  
**Complexity:** LOW

```json
{
    "entity": true,
    "layouts": false,
    "tab": false,
    "acl": false,
    "object": false
}
```

---

#### 11. `custom/Espo/Modules/Chatwoot/Resources/layouts/WhatsAppCampaign/detail.json`

**Purpose:** Detail view layout  
**Complexity:** LOW - Follow Espo layout patterns

**Structure:**

- Header: name, status, scheduledAt
- Campaign Info: templateName, templateLanguage, wabaId, credential
- Audience: targetLists, manualContacts
- Statistics: sentCount, deliveredCount, readCount, failedCount, totalRecipients
- Params: processedParams (JSON editor)

---

#### 12. `custom/Espo/Modules/Chatwoot/Resources/layouts/WhatsAppCampaign/list.json`

**Purpose:** List view layout  
**Complexity:** LOW

**Columns:** name, status, templateName, scheduledAt, sentCount, deliveredCount, failedCount

---

#### 13. `custom/Espo/Modules/Chatwoot/Resources/metadata/clientDefs/WhatsAppCampaign.json`

**Purpose:** Client-side configuration  
**Complexity:** LOW

```json
{
    "controller": "controllers/record",
    "views": {
        "detail": "chatwoot:views/whatsapp-campaign/detail"
    },
    "menu": {
        "list": {
            "buttons": [
                {
                    "label": "Send Campaign",
                    "action": "sendCampaign",
                    "acl": "edit",
                    "style": "primary"
                }
            ]
        }
    }
}
```

---

#### 14. `custom/Espo/Modules/Chatwoot/Resources/routes.json`

**Purpose:** Define custom webhook route  
**Complexity:** LOW

```json
[
    {
        "route": "/WhatsAppCampaign/:id/send",
        "method": "post",
        "params": {
            "controller": "WhatsAppCampaign",
            "action": "send"
        }
    },
    {
        "route": "/WhatsAppCampaign/:id/abort",
        "method": "post",
        "params": {
            "controller": "WhatsAppCampaign",
            "action": "abort"
        }
    },
    {
        "route": "/WhatsAppDeliveryWebhook/:accountId",
        "method": "post",
        "params": {
            "controller": "DeliveryWebhook",
            "action": "receive"
        }
    }
]
```

---

#### 15. `custom/Espo/Modules/Chatwoot/Controllers/WhatsAppCampaign.php`

**Purpose:** Handle custom campaign actions  
**Complexity:** LOW

**Methods:**

- `postActionSend()` - Launch campaign
- `postActionAbort()` - Cancel campaign
- `postActionValidateTemplate()` - AJAX template validation

---

### Files to EDIT

#### 1. `custom/Espo/Modules/FeatureCredential/Rebuild/SeedCredentialTypes.php`

**Change:** Ensure `whatsappCloudApi` type is suitable for campaign use  
**Why:** Campaigns need WABA credentials with accessToken, businessAccountId, phoneNumberId

**Current state (line 446):**

```php
[
    'name' => 'WhatsApp Cloud API',
    'code' => 'whatsappCloudApi',
    // ... schema includes accessToken, businessAccountId, phoneNumberId
]
```

**Verification needed:** Confirm existing `whatsappCloudApi` schema contains all required fields for Meta Graph API calls.

---

#### 2. `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/TargetList.json` (NEW FILE or EXTENSION)

**Change:** Add reverse link to WhatsAppCampaign  
**Why:** Allow navigating from TargetList to campaigns that use it

**Add to TargetList entityDefs:**

```json
{
    "links": {
        "whatsAppCampaigns": {
            "type": "hasMany",
            "entity": "WhatsAppCampaign",
            "foreign": "targetLists",
            "layoutRelationshipsDisabled": true
        }
    }
}
```

---

#### 3. `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootAccount.json` (EXTENSION)

**Change:** Add reverse link to WhatsAppCampaignContact  
**Why:** Track which campaign messages were sent through which account

**Add:**

```json
{
    "links": {
        "whatsAppCampaignContacts": {
            "type": "hasMany",
            "entity": "WhatsAppCampaignContact",
            "foreign": "chatwootAccount",
            "layoutRelationshipsDisabled": true
        }
    }
}
```

---

### Files to CONSIDER

#### 1. `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootContact.json`

**Consider:** Add reverse link to WhatsAppCampaignContact  
**Why:** Track campaign history per contact

---

#### 2. `custom/Espo/Modules/Chatwoot/Hooks/WhatsAppCampaign/WhatsAppCampaignHook.php`

**Consider:** Pre-save validation hook  
**Why:** Validate processedParams JSON structure before save

---

#### 3. `custom/Espo/Modules/Chatwoot/Acl/WhatsAppCampaign.php`

**Consider:** Custom ACL logic  
**Why:** Only allow editing Draft campaigns; restrict Send action based on permissions

---

### Related Files (for reference only)

- `custom/Espo/Modules/Chatwoot/Services/ChatwootApiClient.php` - Existing API client (2294+ lines)
- `custom/Espo/Modules/Chatwoot/Controllers/WahaLabelWebhook.php` - HMAC validation reference
- `custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Services/MetaGraphApiClient.php` - Template fetching
- `custom/Espo/Modules/FeatureIntegrationSimplesAgenda/Jobs/SyncContactsFromSimplesAgenda.php` - Batch processing pattern
- `application/Espo/Modules/Crm/Resources/metadata/entityDefs/TargetList.json` - Opt-out infrastructure
- `application/Espo/Modules/Crm/Resources/metadata/entityDefs/Campaign.json` - Entity naming collision verification

---

## Implementation Watchpoints

### Critical (Must Verify Before/During Implementation)

1. **Chatwoot Template Message Payload Format**
    - The documented payload structure must be tested against actual Chatwoot API
    - Verify `sendTemplateMessage()` returns message_id and conversation_id as expected

2. **Webhook Payload Structure**
    - Chatwoot webhook event structure for message status updates
    - Verify event names: `message_delivered`, `message_read`, `message_failed`

3. **ORM Column Mapping for Opt-out**
    - Use ORM query builder: `where(['tl.isOptedOut' => false])`
    - DO NOT use raw SQL with column names - Espo uses `columnAttributeMap`

4. **Credential Type Fields**
    - Verify `whatsappCloudApi` credential type contains:
        - accessToken (for Meta Graph API)
        - businessAccountId (WABA ID)
        - phoneNumberId (optional, for future use)

### Important (Address During Implementation)

1. **Rate Limiting**
    - Start with 1 message per second (3600/hour)
    - Monitor Chatwoot API rate limit headers
    - Implement exponential backoff for 429 responses

2. **Phone Number Normalization**
    - Normalize to E.164 format: `+55XXXXXXXXXXX`
    - Handle Brazilian formats: (11) 98765-4321, 11987654321
    - Use same logic as `SyncContactsFromSimplesAgenda::normalizePhone()`

3. **Campaign Deduplication**
    - Remove duplicates by normalized phone number across all TargetLists
    - Log duplicate detection for transparency

4. **Error Handling**
    - Distinguish between retryable (network) and non-retryable (invalid phone) errors
    - Store full error context in `failedReason` field

---

## Testing Strategy

### Integration Tests (Required)

1. **Template Validation Flow**
    - Create campaign with valid template → Should succeed
    - Create campaign with invalid template → Should fail with clear error
2. **Campaign Launch Flow**
    - Launch campaign → Verify WhatsAppCampaignContact records created
    - Verify chunk jobs scheduled with correct offsets
3. **Message Sending Flow**
    - Process chunk → Verify Chatwoot API calls
    - Verify campaign contact status updates
4. **Webhook Flow**
    - Send test webhook → Verify status updates
    - Verify campaign counter increments
5. **Opt-out Compliance**
    - Add contact to TargetList, mark opted out
    - Launch campaign → Verify opted-out contact excluded

### End-to-End Test

1. Create TargetList with 3 test contacts
2. Create WhatsApp Campaign
3. Launch campaign
4. Verify all contacts receive messages
5. Verify webhook updates reflect delivery status
6. Verify final campaign statistics

---

## Post-Deployment Checklist

- [ ] Verify credential type `whatsappCloudApi` exists and has correct schema
- [ ] Run database rebuild to create new entity tables
- [ ] Test template validation against real Meta Graph API
- [ ] Configure Chatwoot webhook URL: `https://{espo-domain}/api/v1/WhatsAppDeliveryWebhook/{accountId}`
- [ ] Test webhook HMAC validation with sample payload
- [ ] Verify index on `chatwootMessageId` created successfully
- [ ] Create sample campaign and verify full flow
- [ ] Document webhook setup for operations team

---

## Changelog from v2

| Change                                                             | Reason                                                                     |
| ------------------------------------------------------------------ | -------------------------------------------------------------------------- |
| Reuse `whatsappCloudApi` instead of new `whatsappBusinessApi` type | Existing type has all required fields (v2 audit Warning #1)                |
| Clarify `chatwootContact` link population timing                   | Must be populated AFTER `findOrCreateContact()` call (v2 audit Warning #2) |
| Add index on `chatwootMessageId`                                   | Webhook lookup performance (v2 audit Suggestion #1)                        |
| Add `chatwootAccount` link to junction entity                      | Easier webhook validation (v2 audit Suggestion #2)                         |
| Document ORM-based opt-out filtering                               | Avoid column name mismatch (v2 audit Warning #3)                           |
| Document client-side template filtering                            | Meta API doesn't support name filter (v2 audit Suggestion #3)              |
| Add phone normalization for deduplication                          | Handle Brazilian number formats (v2 audit Suggestion #4)                   |

---

**End of Scope v3**

_This scope incorporates all findings from the v2 audit and is ready for implementation._

