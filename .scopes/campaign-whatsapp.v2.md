# WhatsApp Campaign Feature - Scope v2

**Scope Version:** v2  
**Previous Version:** v1 (see campaign-whatsapp.v1.audit.md for audit findings)  
**Created:** 2026-03-03  
**Status:** Ready for Implementation  

---

## Overview

This scope defines the implementation of a WhatsApp Campaign feature for EspoCRM, enabling users to create, manage, and execute bulk WhatsApp messaging campaigns using Meta Business API templates delivered through Chatwoot.

---

## Decisions

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | **Rename entity to `WhatsAppCampaign`** | Keep `Campaign` name | Avoids collision with EspoCRM's core Campaign entity (Critical fix from audit) |
| 2 | **Use Target Lists + manual selection** | Segments only, manual only | Target Lists is EspoCRM's native feature for campaigns; manual adds flexibility |
| 3 | **Template validation at creation** | Validation at send time | Early validation prevents campaign failures; better UX |
| 4 | **Reuse existing conversation or create new** | Always create new | Chatwoot's single conversation per contact is the standard pattern |
| 5 | **Rate limiting via chunked job scheduling** | Blocking sleep in single job | Prevents job timeout for large campaigns; scalable (Critical fix from audit) |
| 6 | **Implement opt-out filtering** | Skip opt-out handling | Legal compliance (LGPD/GDPR); TargetList has existing opt-out infrastructure |
| 7 | **Webhook-based delivery tracking** | Polling | Real-time updates, scalable, native to Chatwoot |
| 8 | **Store WABA ID in Campaign entity** | Extract from credential each time | Simpler queries; credential schema may vary |
| 9 | **Use Chatwoot account API key for webhook HMAC** | Meta webhook verification | Chatwoot webhooks use account-level authentication |
| 10 | **Add Campaign cancellation/abort** | Pause/Resume only | Users need ability to stop campaigns completely |
| 11 | **Add extended status values** | Original 6 statuses only | Better tracking for compliance and debugging (OptedOut, Bounced, Blocked) |

---

## Architecture

### Entity Relationship Diagram

```
┌─────────────────────┐     ┌──────────────────────────┐     ┌─────────────────┐
│   WhatsAppCampaign  │────▶│  WhatsAppCampaignContact │◀────│     Contact     │
├─────────────────────┤     ├──────────────────────────┤     ├─────────────────┤
│ name                │     │ campaign (link)          │     │ name            │
│ status              │     │ contact (link)           │     │ phoneNumber     │
│ templateName        │     │ chatwootContact (link)   │     │ ...             │
│ templateLanguage    │     │ chatwootConversationId   │     └─────────────────┘
│ templateCategory    │     │ chatwootMessageId        │              ▲
│ targetLists (link)  │     │ status (enum)            │              │
│ manualContacts(link)│     │ errorMessage             │              │
│ rateLimit           │     │ sentAt                   │     ┌────────┴────────┐
│ totalRecipients     │     │ deliveredAt              │     │   TargetList    │
│ sentCount           │     │ readAt                   │     │ (existing)      │
│ deliveredCount      │     │ retryCount               │     └─────────────────┘
│ readCount           │     │ processedParams          │              │
│ failedCount         │     └──────────────────────────┘              │
│ chatwootAccount(link)│                                              │
│ chatwootInbox(link) │◀──────────────────────────────────────────────┘
│ credential (link)   │         (targetLists relationship)
│ wabaId              │
│ processedParamsConfig│
└─────────────────────┘
```

---

## File Manifest

### Files to CREATE (ordered by complexity/risk, highest first)

#### 1. WhatsAppCampaign Service - Core Business Logic
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Services/WhatsAppCampaignService.php`

**Purpose:** Orchestrates campaign execution - the most complex piece coordinating multiple systems.

**Key Patterns to Follow:**
- Reference `FeatureIntegrationSimplesAgenda/Jobs/SyncContactsFromSimplesAgenda.php` for batch processing patterns (BATCH_SIZE = 200)
- Reference `MetaGraphApiClient.php` for Meta API error handling
- Use `entityManager->getRepository()->find()` patterns from existing services
- Use `JobSchedulerFactory` for chunked execution to avoid timeout (see `WahaLabelWebhook.php` lines 103-113)

**Key Logic:**
```php
class WhatsAppCampaignService
{
    private const BATCH_SIZE = 200;
    private const DEFAULT_RATE_LIMIT = 30; // messages per second
    private const MAX_RETRIES = 3;
    
    public function executeCampaign(string $campaignId): void
    {
        // 1. Load campaign with template params
        // 2. Resolve audience (TargetList members + manual contacts) with opt-out filtering
        // 3. Create WhatsAppCampaignContact records for each (status: Pending)
        // 4. Schedule chunked jobs for batch processing (non-blocking rate limiting)
        // 5. Update WhatsAppCampaignContact status based on response
    }

    private function resolveAudience(Entity $campaign): array
    {
        // Merge TargetList members + manually selected contacts
        // Filter out opted-out contacts: targetListContacts.optedOut = false
        // Remove duplicates by contactId
    }

    private function scheduleChunkedJobs(
        string $campaignId,
        array $contactIds,
        int $messagesPerSecond
    ): void {
        // Calculate chunk size based on rate limit
        // Schedule separate jobs for each chunk with calculated delay
        // Each job processes BATCH_SIZE contacts
    }
    
    public function abortCampaign(string $campaignId): void
    {
        // Mark campaign as Aborted
        // Update all Pending WhatsAppCampaignContact records to Cancelled
    }
}
```

**Critical Implementation Notes:**
- **Rate Limiting:** Instead of `usleep()` in a single job (which causes timeouts), use chunked job scheduling:
  ```php
  $chunkSize = self::BATCH_SIZE;
  $chunks = array_chunk($contactIds, $chunkSize);
  $delaySeconds = 0;
  $secondsPerChunk = ceil($chunkSize / $messagesPerSecond);
  
  foreach ($chunks as $index => $chunk) {
      $jobScheduler
          ->setClassName('Espo\\Modules\\FeatureWhatsAppCampaign\\Jobs\\ProcessCampaignChunk')
          ->setData([
              'campaignId' => $campaignId,
              'contactIds' => $chunk,
              'chunkIndex' => $index,
          ])
          ->setExecuteTime(date('Y-m-d H:i:s', time() + $delaySeconds))
          ->schedule();
      
      $delaySeconds += $secondsPerChunk;
  }
  ```

---

#### 2. Chatwoot API Client Extension
**Path:** `custom/Espo/Modules/Chatwoot/Services/ChatwootApiClient.php` (extend existing)

**Purpose:** Add WhatsApp template message methods to existing Chatwoot API client.

**Methods to Add:**
```php
/**
 * Send a WhatsApp template message via Chatwoot API.
 * 
 * @param string $accountId Chatwoot account ID
 * @param string $conversationId Chatwoot conversation ID
 * @param string $templateName Meta template name
 * @param string $language Template language code (e.g., pt_BR)
 * @param array $processedParams Processed template parameters
 * @param string|null $campaignId Optional campaign ID for tracking
 * @return array Response with message ID
 * @throws Error
 */
public function sendTemplateMessage(
    string $accountId,
    string $conversationId,
    string $templateName,
    string $language,
    array $processedParams,
    ?string $campaignId = null
): array;

/**
 * Create or retrieve a Chatwoot contact from EspoCRM contact.
 *
 * @param string $accountId Chatwoot account ID
 * @param string $inboxId Chatwoot inbox ID
 * @param array $contactData Contact data (phone, name, email, etc.)
 * @return array Contact data with ID
 * @throws Error
 */
public function findOrCreateContact(
    string $accountId,
    string $inboxId,
    array $contactData
): array;

/**
 * Create a conversation for a contact in specified inbox.
 *
 * @param string $accountId Chatwoot account ID
 * @param string $inboxId Chatwoot inbox ID
 * @param string $contactId Chatwoot contact ID
 * @return array Conversation data with ID
 * @throws Error
 */
public function createConversation(
    string $accountId,
    string $inboxId,
    string $contactId
): array;

/**
 * Find existing conversation for contact in inbox.
 *
 * @param string $accountId Chatwoot account ID
 * @param string $inboxId Chatwoot inbox ID
 * @param string $contactId Chatwoot contact ID
 * @return array|null Conversation data or null if not found
 */
public function findConversation(
    string $accountId,
    string $inboxId,
    string $contactId
): ?array;
```

**API Payload Format for Template Messages:**
```php
$payload = [
    'content' => $templatePreviewText, // Human-readable preview
    'template_params' => [
        'name' => $templateName,
        'category' => $category, // MARKETING, UTILITY, AUTHENTICATION
        'language' => $language, // pt_BR, en_US, etc.
        'processed_params' => [
            'body' => [
                '1' => $firstName,
                '2' => $orderNumber,
                // etc.
            ],
            'header' => [
                'media_url' => $imageUrl, // Optional
                'media_type' => 'image', // Optional
            ],
            'buttons' => [ // Optional
                ['type' => 'url', 'parameter' => 'track-123'],
            ],
        ],
    ],
    'private' => false,
];
```

---

#### 3. Meta Template Validator Service
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Services/TemplateValidator.php`

**Purpose:** Validates template parameters against Meta API before campaign creation.

**Key Methods:**
```php
public function validateTemplate(
    string $templateName,
    string $language,
    array $sampleParams,
    string $wabaId,
    string $accessToken
): ValidationResult;

public function getTemplateSchema(
    string $templateName,
    string $language,
    string $wabaId,
    string $accessToken
): array; // Returns parameter structure for UI rendering
```

**Complexity:** Medium - needs to parse Meta's template JSON structure and map to EspoCRM fields.

---

#### 4. Delivery Webhook Handler
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Controllers/DeliveryWebhook.php`

**Purpose:** Receives Chatwoot delivery status webhooks and updates WhatsAppCampaignContact.

**Key Logic:**
```php
public function postActionReceive(Request $request, Response $response): stdClass
{
    // 1. Validate webhook signature using Chatwoot account API key
    // 2. Parse delivery event (sent, delivered, read, failed)
    // 3. Find WhatsAppCampaignContact by messageId or conversationId
    // 4. Update status and timestamps
    // 5. Update WhatsAppCampaign aggregate stats (counters)
}
```

**Webhook Secret Source:** 
- Use `ChatwootAccount.apiKey` (stored in Credential entity) for HMAC validation
- Chatwoot webhooks are signed with the account API key
- Reference: Follow `WahaLabelWebhook.php` pattern for HMAC validation and async processing

---

#### 5. Process Campaign Chunk Job
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Jobs/ProcessCampaignChunk.php`

**Purpose:** Processes a single chunk of campaign contacts (non-blocking rate limiting approach).

**Key Features:**
- Implements `Espo\Core\Job\Job`
- Receives chunk of contact IDs via job data
- Sends messages with rate limiting via `usleep()` within chunk (safe for ~200 contacts)
- Updates WhatsAppCampaignContact records with status
- Handles retries for failed sends
- Updates campaign progress counters

---

#### 6. Campaign Scheduler Job
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Jobs/ExecuteWhatsAppCampaign.php`

**Purpose:** Background job triggered by scheduled task to check and execute scheduled campaigns.

**Key Features:**
- Implements `Espo\Core\Job\JobDataLess`
- Finds campaigns with status "Scheduled" and scheduledAt <= now
- Calls `WhatsAppCampaignService->executeCampaign()`
- Handles campaign state transitions

---

#### 7. Delivery Webhook Processing Job
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Jobs/ProcessDeliveryWebhook.php`

**Purpose:** Async processing of delivery webhooks for better response times.

**Why async:** Webhooks should respond quickly; actual processing can happen in background.

---

#### 8. WhatsAppCampaign Entity Definition
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/entityDefs/WhatsAppCampaign.json`

**Purpose:** Define the WhatsAppCampaign entity fields (renamed from Campaign to avoid collision).

**Key Fields:**
```json
{
  "fields": {
    "name": {"type": "varchar", "required": true},
    "status": {
      "type": "enum",
      "options": ["Draft", "Scheduled", "Running", "Paused", "Completed", "Failed", "Aborted"],
      "default": "Draft"
    },
    "templateName": {"type": "varchar", "required": true},
    "templateLanguage": {"type": "varchar", "default": "pt_BR"},
    "templateCategory": {
      "type": "enum",
      "options": ["MARKETING", "UTILITY", "AUTHENTICATION"]
    },
    "templateValidatedAt": {"type": "datetime", "readOnly": true},
    "targetLists": {"type": "linkMultiple", "entity": "TargetList"},
    "manualContacts": {"type": "linkMultiple", "entity": "Contact"},
    "scheduledAt": {"type": "datetime"},
    "startedAt": {"type": "datetime", "readOnly": true},
    "completedAt": {"type": "datetime", "readOnly": true},
    "rateLimit": {"type": "int", "default": 30, "min": 1, "max": 100},
    "totalRecipients": {"type": "int", "readOnly": true},
    "sentCount": {"type": "int", "readOnly": true},
    "deliveredCount": {"type": "int", "readOnly": true},
    "readCount": {"type": "int", "readOnly": true},
    "failedCount": {"type": "int", "readOnly": true},
    "chatwootAccount": {"type": "link", "entity": "ChatwootAccount", "required": true},
    "chatwootInbox": {"type": "link", "entity": "ChatwootInbox", "required": true},
    "credential": {"type": "link", "entity": "Credential", "required": true},
    "wabaId": {"type": "varchar", "required": true},
    "processedParamsConfig": {"type": "jsonObject"}
  }
}
```

---

#### 9. WhatsAppCampaignContact Junction Entity
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/entityDefs/WhatsAppCampaignContact.json`

**Purpose:** Many-to-many relationship with status tracking per contact.

**Fields:**
```json
{
  "fields": {
    "campaign": {"type": "link", "entity": "WhatsAppCampaign", "required": true},
    "contact": {"type": "link", "entity": "Contact", "required": true},
    "chatwootContact": {"type": "link", "entity": "ChatwootContact"},
    "chatwootConversationId": {"type": "varchar"},
    "chatwootMessageId": {"type": "varchar"},
    "status": {
      "type": "enum",
      "options": [
        "Pending", "Processing", "Sent", "Delivered", "Read", 
        "Failed", "OptedOut", "Bounced", "Blocked", "Cancelled"
      ],
      "default": "Pending"
    },
    "errorMessage": {"type": "text"},
    "sentAt": {"type": "datetime"},
    "deliveredAt": {"type": "datetime"},
    "readAt": {"type": "datetime"},
    "retryCount": {"type": "int", "default": 0},
    "processedParams": {"type": "jsonObject"}
  }
}
```

---

#### 10. WhatsAppCampaign Controller
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Controllers/WhatsAppCampaign.php`

**Purpose:** REST endpoints for UI interactions.

**Custom Actions:**
```php
public function postActionValidateTemplate(Request $request): stdClass;
public function postActionExecute(Request $request): stdClass;
public function postActionAbort(Request $request): stdClass; // NEW: Cancel campaign
public function getActionStats(Request $request): stdClass;
public function postActionPause(Request $request): stdClass;
public function postActionResume(Request $request): stdClass;
public function getActionPreview(Request $request): stdClass;
public function getActionGetTemplates(Request $request): stdClass;
```

---

#### 11. Template Parameter Mapper Service
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Services/TemplateParamMapper.php`

**Purpose:** Maps Contact fields to WhatsApp template parameters.

```php
public function mapParams(Contact $contact, array $paramConfig): array
{
    // paramConfig example:
    // [
    //   "body" => ["1" => "{{firstName}}", "2" => "{{lastName}}"],
    //   "header" => ["media_url" => "{{customAvatarUrl}}"]
    // ]
    // Returns processed_params format for Chatwoot API
}
```

---

#### 12. Database Migration
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Rebuild/CreateWhatsAppCampaignTables.php`

**Purpose:** Create database tables for new entities.

**Key Implementation:**
```php
class CreateWhatsAppCampaignTables implements RebuildAction
{
    public function run(EntityManager $entityManager, SchemaManager $schemaManager): void
    {
        // Tables will be auto-created by Espo ORM from entityDefs
        // This migration ensures proper foreign key constraints
        // and indexes for performance
    }
}
```

**Note:** EspoCRM's metadata rebuild will auto-create tables from entityDefs. This migration file documents the requirement and can add custom indexes if needed.

---

#### 13-17. Frontend Files

**13. Campaign Service (Frontend)**
**Path:** `client/modules/feature-whatsapp-campaign/src/services/campaign-service.ts`

**Methods:**
```typescript
validateTemplate(templateName: string, language: string, params: object): Promise<ValidationResult>;
executeCampaign(campaignId: string): Promise<void>;
abortCampaign(campaignId: string): Promise<void>; // NEW
getTemplates(): Promise<Template[]>;
getStats(campaignId: string): Promise<CampaignStats>;
pauseCampaign(campaignId: string): Promise<void>;
resumeCampaign(campaignId: string): Promise<void>;
```

**14. Campaign List View**
**Path:** `client/modules/feature-whatsapp-campaign/src/views/whats-app-campaign/list.ts`

**Features:**
- Status badges (Draft, Scheduled, Running, Paused, Completed, Failed, Aborted)
- Progress bars for running campaigns
- Quick actions (Execute, Pause, Abort, View Stats)

**15. Campaign Detail View**
**Path:** `client/modules/feature-whatsapp-campaign/src/views/whats-app-campaign/detail.ts`

**Tabs:**
- Overview (stats, template info)
- Recipients (WhatsAppCampaignContact list with filters)
- Logs (errors, retry history)

**16. Campaign Edit/Create View (Complex)**
**Path:** `client/modules/feature-whatsapp-campaign/src/views/whats-app-campaign/edit.ts`

**Multi-step form:**
1. Basic Info: Name, schedule, rate limit
2. Template Selection: Dropdown populated from Meta API, with live preview
3. Parameter Mapping: Map template variables to Contact fields
4. Audience: Select Target Lists + manual contact selection
5. Validation: Validate template against Meta API

**17. Campaign Contact List View**
**Path:** `client/modules/feature-whatsapp-campaign/src/views/whats-app-campaign-contact/list.ts`

**Features:**
- Show recipients with delivery status
- Filters by status (Pending, Sent, Delivered, Read, Failed, etc.)
- Search by contact name/phone

---

#### 18. Module Registration
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/module.json`

```json
{
  "name": "FeatureWhatsAppCampaign",
  "version": "1.0.0",
  "dependencies": ["FeatureMetaWhatsAppBusiness", "Chatwoot", "FeatureCredential"]
}
```

---

#### 19. Scheduled Job Registration
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/app/scheduledJobs.json`

```json
{
  "executeWhatsAppCampaigns": {
    "name": "Execute Scheduled WhatsApp Campaigns",
    "schedule": "*/5 * * * *",
    "isSystem": false
  }
}
```

---

#### 20. Webhook Route Registration
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/app/routes.json`

```json
[
  {
    "route": "/WhatsAppCampaign/DeliveryWebhook",
    "method": "post",
    "params": {
      "controller": "DeliveryWebhook",
      "action": "receive"
    }
  }
]
```

---

#### 21-22. ACL Definitions
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/aclDefs/WhatsAppCampaign.json`  
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/aclDefs/WhatsAppCampaignContact.json`

---

#### 23-26. Language Translations
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/i18n/en_US/WhatsAppCampaign.json`  
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/i18n/pt_BR/WhatsAppCampaign.json`  
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/i18n/en_US/WhatsAppCampaignContact.json`  
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/i18n/pt_BR/WhatsAppCampaignContact.json`

---

#### 27. Client Navbar Configuration
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/app/clientNavbar.json`

**Purpose:** Add WhatsApp Campaign menu item under Marketing section.

---

### Files to EDIT

#### 1. Extend MetaGraphApiClient
**Path:** `custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Services/MetaGraphApiClient.php`

**Changes:**
Add these methods (verify `getMessageTemplates` already exists at line 126):
```php
public function getMessageTemplates(string $accessToken, string $wabaId): array;
public function getTemplateByName(
    string $accessToken, 
    string $templateName, 
    string $language, 
    string $wabaId
): ?array;
```

---

#### 2. Extend ChatwootApiClient
**Path:** `custom/Espo/Modules/Chatwoot/Services/ChatwootApiClient.php`

**Changes:**
Add the methods documented in section "2. Chatwoot API Client Extension" above:
- `sendTemplateMessage()`
- `findOrCreateContact()`
- `createConversation()`
- `findConversation()`

---

#### 3. Credential Type Registration
**Path:** `custom/Espo/Modules/FeatureCredential/Rebuild/SeedCredentialTypes.php`

**Changes:**
Add WhatsApp Business API credential type:
```php
[
    'name' => 'WhatsApp Business API',
    'code' => 'whatsappBusinessApi',
    'category' => 'api_key',
    'schema' => json_encode([
        'properties' => [
            'accessToken' => ['type' => 'string'],
            'wabaId' => ['type' => 'string'],
            'phoneNumberId' => ['type' => 'string'],
        ],
    ]),
    'encryptionFields' => json_encode(['accessToken']),
]
```

---

### Files to CONSIDER (MVP can skip)

#### 1. Campaign Report/Analytics Entity
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/entityDefs/CampaignReport.json`

**Purpose:** Store aggregated analytics for historical analysis.

---

#### 2. Campaign Template Entity (Reusable Templates)
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/entityDefs/CampaignTemplate.json`

**Purpose:** Save frequently used template configurations locally.

---

#### 3. Campaign Scheduler (Advanced)
**Path:** `custom/Espo/Modules/FeatureWhatsAppCampaign/Services/CampaignScheduler.php`

**Purpose:** More complex scheduling (e.g., "send between 9am-6pm only").

---

### Related Files (for reference only, no changes needed)

| Path | Purpose |
|------|---------|
| `custom/Espo/Modules/Chatwoot/Services/ChatwootApiClient.php` | Chatwoot API patterns (2294 lines) |
| `custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Services/MetaGraphApiClient.php` | Meta API patterns |
| `custom/Espo/Modules/FeatureIntegrationSimplesAgenda/Jobs/SyncContactsFromSimplesAgenda.php` | Batch processing (BATCH_SIZE = 200) |
| `custom/Espo/Modules/Chatwoot/Controllers/WahaLabelWebhook.php` | Webhook handling, HMAC validation |
| `custom/Espo/Resources/metadata/entityDefs/TargetList.json` | Target List structure |
| `application/Espo/Modules/Crm/Resources/metadata/entityDefs/Campaign.json` | Core Campaign entity (collision avoidance reference) |

---

## Data Flow Diagram

### Campaign Creation Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    CAMPAIGN CREATION                            │
└─────────────────────────────────────────────────────────────────┘
│
▼
┌─────────────────────┐    ┌─────────────────────┐
│ Select Template     │───▶│ Validate Against    │
│ from Meta API       │    │ Meta API            │
└─────────────────────┘    └─────────────────────┘
│                              │
▼                              ▼
┌─────────────────────┐    ┌─────────────────────┐
│ Map Parameters to   │◀───│ Show Validation     │
│ Contact Fields      │    │ Errors (if any)     │
└─────────────────────┘    └─────────────────────┘
│
▼
┌─────────────────────┐    ┌─────────────────────┐
│ Select Target       │    │ Select Manual       │
│ Lists               │    │ Contacts            │
└─────────────────────┘    └─────────────────────┘
│                              │
└────────────┬─────────────────┘
             ▼
    ┌──────────────┐
    │ Filter out   │
    │ opted-out    │
    │ contacts     │
    └──────────────┘
             │
             ▼
    ┌──────────────┐
    │ Save as      │
    │ DRAFT or     │
    │ SCHEDULED    │
    └──────────────┘
```

### Campaign Execution Flow (Chunked Approach)

```
┌─────────────────────────────────────────────────────────────────┐
│                   CAMPAIGN EXECUTION                            │
└─────────────────────────────────────────────────────────────────┘
│
▼
┌─────────────────────┐
│ Scheduled Job       │
│ (every 5 min)       │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Load Campaign       │
│ (Scheduled +        │
│ time reached)       │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Resolve Audience:   │
│ - Get Target List   │
│   members           │
│ - Add manual        │
│   contacts          │
│ - Remove dupes      │
│ - Filter opted-out  │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Create              │
│ WhatsAppCampaign-   │
│ Contact records     │
│ (status: Pending)   │
└─────────────────────┘
│
▼
┌─────────────────────────────┐
│ Calculate chunks based on   │
│ rate limit (e.g., 30 msg/s) │
│ Chunk size: 200 contacts    │
│ Delay between chunks: ~7s   │
└─────────────────────────────┘
│
▼
┌─────────────────────┐
│ Schedule chunk jobs │
│ with delays         │
└─────────────────────┘
│
▼
┌─────────────────────────────────────────────────────────────────┐
│ FOR EACH CHUNK JOB                                              │
│ ┌─────────────────┐    ┌─────────────────┐                      │
│ │ Load contacts   │───▶│ Process batch   │                      │
│ │ in chunk        │    │ (with usleep)   │                      │
│ └─────────────────┘    └────────┬────────┘                      │
│                                  │                              │
│                    ┌─────────────┼─────────────┐                │
│                    ▼             ▼             ▼                │
│           ┌──────────┐  ┌──────────┐  ┌──────────┐             │
│           │ Ensure   │  │ Send     │  │ Update   │             │
│           │ Chatwoot │─▶│ Template │─▶│ Status   │             │
│           │ Contact  │  │ Message  │  │          │             │
│           └──────────┘  └──────────┘  └──────────┘             │
└─────────────────────────────────────────────────────────────────┘
```

### Delivery Webhook Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                 DELIVERY WEBHOOK FLOW                           │
└─────────────────────────────────────────────────────────────────┘
│
▼
┌─────────────────────┐
│ Chatwoot sends      │
│ webhook (message_   │
│ status_update)      │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ HMAC Validation     │
│ (using account      │
│ API key)            │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Queue async job     │
│ ProcessDelivery     │
│ Webhook             │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Find                │
│ WhatsAppCampaign-   │
│ Contact by          │
│ messageId           │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Update status &     │
│ timestamp           │
│ (deliveredAt/       │
│ readAt)             │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Update WhatsApp-    │
│ Campaign counters   │
│ (deliveredCount,    │
│ readCount)          │
└─────────────────────┘
```

---

## Key Implementation Notes

### 1. Opt-out Filtering

EspoCRM's TargetList already has an `optedOut` column in the `targetListContact` junction table. Filter contacts during audience resolution:

```php
$targetListIds = $campaign->get('targetListsIds');
foreach ($targetListIds as $targetListId) {
    $targetList = $this->entityManager->getEntity('TargetList', $targetListId);
    
    // Get contacts from the junction table with optedOut filter
    $sql = "SELECT contactId FROM target_list_contact 
            WHERE targetListId = ? AND deleted = 0 AND optedOut = 0";
    $contactIds = $this->entityManager->getQueryExecutor()->query($sql, [$targetListId]);
    
    // Add to audience array
}
```

### 2. WABA ID Storage

The WABA ID is stored directly in the `WhatsAppCampaign` entity for simpler queries. During campaign creation:

1. User selects a `Credential` (type: `whatsappBusinessApi`)
2. System extracts `wabaId` from the credential config
3. Stores `wabaId` in the campaign entity

### 3. Webhook HMAC Validation

Chatwoot webhooks are signed with the account API key. The webhook handler should:

```php
// Get API key from ChatwootAccount credential
$account = $this->entityManager->getEntity('ChatwootAccount', $accountId);
$credential = $this->entityManager->getEntity('Credential', $account->get('credentialId'));
$apiKey = $credential->getDecrypted('apiKey');

// Validate HMAC
$expectedSignature = hash_hmac('sha256', $rawBody, $apiKey);
if (!hash_equals($expectedSignature, $signature)) {
    throw new Forbidden('Invalid signature');
}
```

### 4. Chatwoot Template Message Payload

```php
$payload = [
    'content' => $templatePreviewText, // Human-readable preview
    'template_params' => [
        'name' => $templateName,
        'category' => $category, // MARKETING, UTILITY, AUTHENTICATION
        'language' => $language, // pt_BR, en_US, etc.
        'processed_params' => [
            'body' => [
                '1' => $firstName,
                '2' => $orderNumber,
            ],
            'header' => [
                'media_url' => $imageUrl,
                'media_type' => 'image',
            ],
        ],
    ],
    'private' => false,
];
```

### 5. Webhook Payload Structure (Expected from Chatwoot)

```json
{
  "event": "message_status_updated",
  "message": {
    "id": 12345,
    "status": "delivered",
    "conversation_id": 67890,
    "contact_id": 54321,
    "content_attributes": {
      "campaign_id": "wa-campaign-123"
    }
  }
}
```

---

## Audit Resolution Summary

This v2 scope addresses all critical findings from the v1 audit:

| Audit Finding | Resolution in v2 |
|---------------|------------------|
| Entity naming collision with Campaign | Renamed to `WhatsAppCampaign` throughout |
| Missing Chatwoot API template methods | Added `sendTemplateMessage()`, `findOrCreateContact()`, `createConversation()` |
| Incorrect SimplesAgenda path | Updated to `FeatureIntegrationSimplesAgenda` |
| Missing database migrations | Added migration file to manifest |
| Insufficient status values | Added `OptedOut`, `Bounced`, `Blocked`, `Cancelled` |
| No opt-out handling | Added opt-out filtering in `resolveAudience()` |
| WABA ID source unclear | Added `wabaId` field to WhatsAppCampaign entity |
| Blocking rate limiting | Changed to chunked job scheduling with delays |
| Webhook secret unclear | Documented using Chatwoot account API key |
| No abort functionality | Added `postActionAbort()` method |

---

## Success Criteria

- [ ] Can create a WhatsAppCampaign with template selection from Meta API
- [ ] Can validate template parameters before campaign creation
- [ ] Can select Target Lists and manual contacts as audience
- [ ] Opted-out contacts are automatically excluded from campaigns
- [ ] Campaign execution uses chunked jobs to avoid timeout
- [ ] Delivery status (Sent → Delivered → Read) is tracked via webhooks
- [ ] Campaign can be paused, resumed, and aborted
- [ ] Rate limiting respects configured messages per second
- [ ] All status changes are persisted in WhatsAppCampaignContact records
- [ ] Frontend shows real-time campaign progress

---

*End of Scope v2*
