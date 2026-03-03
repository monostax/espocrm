Updated Decisions
| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Use Target Lists + manual selection | Segments only, manual only | Target Lists is EspoCRM's native feature for campaigns; manual adds flexibility |
| 2 | Template validation at creation | Validation at send time | Early validation prevents campaign failures; better UX |
| 3 | Reuse existing conversation or create new | Always create new | Chatwoot's single conversation per contact is the standard pattern |
| 4 | Rate limiting configuration per campaign | Global rate limit | Different campaigns may have different urgency/priority levels |
| 5 | Skip opt-out handling | Implement opt-out list | Simpler MVP; can add later |
| 6 | Webhook-based delivery tracking | Polling | Real-time updates, scalable, native to Chatwoot |

---

Updated File Manifest
Files to CREATE (ordered by complexity/risk, highest first)

1. Campaign Service - Core Business Logic
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Services/CampaignService.php
   Purpose: Orchestrates campaign execution - the most complex piece coordinating multiple systems.
   Key Patterns to Follow:

- Reference SyncContactsFromSimplesAgenda.php for batch processing patterns (BATCH_SIZE = 200)
- Reference MetaGraphApiClient.php for Meta API error handling
- Use entityManager->getRepository()->find() patterns from existing services
  Key Logic:
  class CampaignService
  {
  private const BATCH_SIZE = 200;
  private const DEFAULT_RATE_LIMIT = 30; // messages per second
        public function executeCampaign(string $campaignId): void
        {
            // 1. Load campaign with template params
            // 2. Resolve audience (TargetList members + manual contacts)
            // 3. Create CampaignContact records for each (status: Pending)
            // 4. Process in batches with rate limiting
            // 5. Update CampaignContact status based on response
        }

        private function resolveAudience(Entity $campaign): array
        {
            // Merge TargetList members + manually selected contacts
            // Remove duplicates by contactId
        }

        private function sendWithRateLimit(
            array $campaignContacts,
            int $messagesPerSecond
        ): void {
            // Use usleep() between sends to respect rate limit
            // Example: 30 msg/sec = 33,333 microseconds between sends
        }
    }

---

2. Chatwoot API Client
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Services/ChatwootCampaignApiClient.php
   Purpose: Wrapper for Chatwoot's message and conversation endpoints.
   Key Methods:
   public function sendTemplateMessage(
   string $conversationId,
   string $templateName,
   string $language,
   array $processedParams,
   ?int $campaignId = null
   ): array;
   public function findOrCreateConversation(
   string $contactId,
   string $inboxId
   ): array;
   public function findOrCreateContact(
   array $contactData,
   string $accountId
   ): array;

---

3. Meta Template Validator Service
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Services/TemplateValidator.php
   Purpose: Validates template parameters against Meta API before campaign creation.
   Key Methods:
   public function validateTemplate(
   string $templateName,
   string $language,
   array $sampleParams,
   string $wabaId
   ): ValidationResult;
   public function getTemplateSchema(
   string $templateName,
   string $language,
   string $wabaId
   ): array; // Returns parameter structure for UI rendering
   Complexity: Medium - needs to parse Meta's template JSON structure and map to EspoCRM fields.

---

4. Delivery Webhook Handler
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Controllers/DeliveryWebhook.php
   Purpose: Receives Chatwoot delivery status webhooks and updates CampaignContact.
   Key Logic:
   public function postActionReceive(Request $request, Response $response): stdClass
   {
   // 1. Validate webhook signature (HMAC)
   // 2. Parse delivery event (sent, delivered, read, failed)
   // 3. Find CampaignContact by messageId or conversationId
   // 4. Update status and timestamps
   // 5. Update Campaign aggregate stats (counters)
   }
   Reference: Follow WahaLabelWebhook.php pattern for HMAC validation and async processing.

---

5. Campaign Entity Definition
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/entityDefs/Campaign.json
   Purpose: Define the Campaign entity fields.
   Key Fields:
   {
   fields: {
   name: {type: varchar, required: true},
   status: {type: enum, options: [Draft, Scheduled, Running, Paused, Completed, Failed], default: Draft},
   templateName: {type: varchar, required: true},
   templateLanguage: {type: varchar, default: pt_BR},
   templateCategory: {type: enum, options: [MARKETING, UTILITY, AUTHENTICATION]},
   templateValidatedAt: {type: datetime, readOnly: true},
   targetLists: {type: linkMultiple, entity: TargetList},
   manualContacts: {type: linkMultiple, entity: Contact},
   scheduledAt: {type: datetime},
   startedAt: {type: datetime, readOnly: true},
   completedAt: {type: datetime, readOnly: true},
   rateLimit: {type: int, default: 30, min: 1, max: 100}, // messages per second
   totalRecipients: {type: int, readOnly: true},
   sentCount: {type: int, readOnly: true},
   deliveredCount: {type: int, readOnly: true},
   readCount: {type: int, readOnly: true},
   failedCount: {type: int, readOnly: true},
   chatwootAccount: {type: link, entity: ChatwootAccount, required: true},
   chatwootInbox: {type: link, entity: ChatwootInbox, required: true},
   credential: {type: link, entity: Credential, required: true},
   processedParamsConfig: {type: jsonObject} // Maps template params to Contact fields
   }
   }

---

6. CampaignContact Junction Entity
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/entityDefs/CampaignContact.json
   Purpose: Many-to-many relationship with status tracking per contact.
   Fields:
   {
   fields: {
   campaign: {type: link, entity: Campaign, required: true},
   contact: {type: link, entity: Contact, required: true},
   chatwootContact: {type: link, entity: ChatwootContact},
   chatwootConversationId: {type: varchar},
   chatwootMessageId: {type: varchar},
   status: {type: enum, options: [Pending, Processing, Sent, Delivered, Read, Failed], default: Pending},
   errorMessage: {type: text},
   sentAt: {type: datetime},
   deliveredAt: {type: datetime},
   readAt: {type: datetime},
   retryCount: {type: int, default: 0},
   processedParams: {type: jsonObject}
   }
   }

---

7. Campaign Execution Job
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Jobs/ExecuteCampaign.php
   Purpose: Background job for campaign execution.
   Key Features:

- Implements Espo\Core\Job\JobDataLess
- Rate limiting with usleep() between sends
- Batch processing (200 contacts at a time)
- Progress tracking via Campaign entity counters
- Error handling with retry logic (max 3 retries)
  public function run(): void
  {
  $campaign = $this->getNextScheduledCampaign();
    if (!$campaign) return;
        $this->campaignService->executeCampaign($campaign->getId());
    }

---

8. Campaign Controller
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Controllers/Campaign.php
   Purpose: REST endpoints for UI interactions.
   Custom Actions:
   public function postActionValidateTemplate(Request $request): stdClass;
   public function postActionExecute(Request $request): stdClass;
   public function getActionStats(Request $request): stdClass;
   public function postActionPause(Request $request): stdClass;
   public function postActionResume(Request $request): stdClass;
   public function getActionPreview(Request $request): stdClass;
   public function getActionGetTemplates(Request $request): stdClass; // Fetch from Meta API

---

9.  Template Parameter Mapper Service
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Services/TemplateParamMapper.php
    Purpose: Maps Contact fields to WhatsApp template parameters.
    public function mapParams(Contact $contact, array $paramConfig): array
    {
    // paramConfig example:
    // [
    // "body" => ["1" => "{{firstName}}", "2" => "{{lastName}}"],
    // "header" => ["media_url" => "{{customAvatarUrl}}"]
    // ]
        // Returns processed_params format for Chatwoot API
    }

---

10. Delivery Status Update Job
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Jobs/ProcessDeliveryWebhook.php
    Purpose: Async processing of delivery webhooks.
    Why async: Webhooks should respond quickly; actual processing can happen in background.

---

11. Campaign Service (Frontend)
    Path: client/modules/feature-whatsapp-campaign/src/services/campaign-service.ts
    Purpose: Frontend service for API calls.
    Methods:
    validateTemplate(templateName: string, language: string, params: object): Promise<ValidationResult>;
    executeCampaign(campaignId: string): Promise<void>;
    getTemplates(): Promise<Template[]>;
    getStats(campaignId: string): Promise<CampaignStats>;
    pauseCampaign(campaignId: string): Promise<void>;
    resumeCampaign(campaignId: string): Promise<void>;

---

12. Campaign List View
    Path: client/modules/feature-whatsapp-campaign/src/views/campaign/list.ts
    Purpose: List campaigns with status indicators.
    Features:

- Status badges (Draft, Scheduled, Running, etc.)
- Progress bars for running campaigns
- Quick actions (Execute, Pause, View Stats)

---

13. Campaign Detail View
    Path: client/modules/feature-whatsapp-campaign/src/views/campaign/detail.ts
    Purpose: Detail view with tabs:

- Overview (stats, template info)
- Recipients (CampaignContact list with filters)
- Logs (errors, retry history)

---

14. Campaign Edit/Create View (Complex)
    Path: client/modules/feature-whatsapp-campaign/src/views/campaign/edit.ts
    Purpose: Multi-step form:
1. Basic Info: Name, schedule, rate limit
1. Template Selection: Dropdown populated from Meta API, with live preview
1. Parameter Mapping: Map template variables to Contact fields
1. Audience: Select Target Lists + manual contact selection
1. Validation: Validate template against Meta API
   Complexity: High - needs to coordinate multiple API calls and dynamic form rendering based on template schema.

---

15. Campaign Contact List View
    Path: client/modules/feature-whatsapp-campaign/src/views/campaign-contact/list.ts
    Purpose: Show recipients with delivery status, filters by status.

---

16. Module Registration
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/module.json
    {
    name: FeatureWhatsAppCampaign,
    version: 1.0.0,
    dependencies: [FeatureMetaWhatsAppBusiness, Chatwoot, FeatureCredential]
    }

---

17. Scheduled Job Registration
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/app/scheduledJobs.json
    {
    executeWhatsAppCampaigns: {
    name: Execute Scheduled WhatsApp Campaigns,
    schedule: _/5 _ \* \* \*,
    isSystem: false
    }
    }

---

18. Webhook Route Registration
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/app/routes.json
    [
    {
    route: /WhatsAppCampaign/DeliveryWebhook,
    method: post,
    params: {
    controller: DeliveryWebhook,
    action: receive
    }
    }
    ]

---

19. ACL Definitions
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/aclDefs/Campaign.json
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/aclDefs/CampaignContact.json

---

20. Language Translations
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/i18n/en_US/Campaign.json
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/i18n/pt_BR/Campaign.json
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/i18n/en_US/CampaignContact.json
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/i18n/pt_BR/CampaignContact.json

---

21. Client Navbar Configuration
    Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/app/clientNavbar.json
    Purpose: Add Campaign menu item under Marketing or as top-level item.

---

Files to EDIT

1. Extend MetaGraphApiClient
   Path: custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Services/MetaGraphApiClient.php
   Changes:
   Add these methods:
   public function getMessageTemplates(string $wabaId): array;
   public function getTemplateByName(string $templateName, string $language, string $wabaId): ?array;

---

2. ChatwootContact Service (ensure findOrCreate exists)
   Path: custom/Espo/Modules/Chatwoot/Services/ChatwootContactService.php (create if doesn't exist)
   Changes:
   Ensure there's a method:
   public function findOrCreateByContact(Entity $contact, string $accountId): Entity;

---

3. Credential Type Registration
   Path: custom/Espo/Modules/FeatureCredential/Rebuild/SeedCredentialTypes.php
   Changes:
   Add chatwootApi credential type for storing Chatwoot API keys:
   [
   'name' => 'Chatwoot API',
   'code' => 'chatwootApi',
   'category' => 'api_key',
   'schema' => json_encode([
   'properties' => [
   'apiKey' => ['type' => 'string'],
   'baseUrl' => ['type' => 'string', 'default' => 'https://chatwoot.com'],
   ],
   ]),
   'encryptionFields' => json_encode(['apiKey']),
   ]

---

Files to CONSIDER (MVP can skip)

1. Campaign Report/Analytics Entity
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/entityDefs/CampaignReport.json
   Purpose: Store aggregated analytics for historical analysis.

---

2. Campaign Template Entity (Reusable Templates)
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Resources/metadata/entityDefs/CampaignTemplate.json
   Purpose: Save frequently used template configurations locally.

---

3. Campaign Scheduler (Advanced)
   Path: custom/Espo/Modules/FeatureWhatsAppCampaign/Services/CampaignScheduler.php
   Purpose: More complex scheduling (e.g., "send between 9am-6pm only").

---

Related Files (for reference only)
| Path | Purpose |
|------|---------|
| custom/Espo/Modules/Chatwoot/Services/ChatwootApiClient.php | Chatwoot API patterns |
| custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Services/MetaGraphApiClient.php | Meta API patterns |
| custom/Espo/Modules/SimplesAgenda/Jobs/SyncContactsFromSimplesAgenda.php | Batch processing |
| custom/Espo/Modules/Chatwoot/Controllers/WahaLabelWebhook.php | Webhook handling |
| custom/Espo/Resources/metadata/entityDefs/TargetList.json | Target List structure |

---

Data Flow Diagram
┌─────────────────────────────────────────────────────────────────┐
│ CAMPAIGN CREATION │
└─────────────────────────────────────────────────────────────────┘
│
▼
┌─────────────────────┐ ┌─────────────────────┐
│ Select Template │────▶│ Validate Against │
│ from Meta API │ │ Meta API │
└─────────────────────┘ └─────────────────────┘
│ │
▼ ▼
┌─────────────────────┐ ┌─────────────────────┐
│ Map Parameters to │◀────│ Show Validation │
│ Contact Fields │ │ Errors (if any) │
└─────────────────────┘ └─────────────────────┘
│
▼
┌─────────────────────┐ ┌─────────────────────┐
│ Select Target │ │ Select Manual │
│ Lists │ │ Contacts │
└─────────────────────┘ └─────────────────────┘
│ │
└────────────┬───────────────────────┘
▼
┌──────────────┐
│ Save as │
│ DRAFT or │
│ SCHEDULED │
└──────────────┘
┌─────────────────────────────────────────────────────────────────┐
│ CAMPAIGN EXECUTION │
└─────────────────────────────────────────────────────────────────┘
│
▼
┌─────────────────────┐
│ Scheduled Job │
│ (every 5 min) │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Load Campaign │
│ (Scheduled + │
│ time reached) │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Resolve Audience: │
│ - Get Target List │
│ members │
│ - Add manual │
│ contacts │
│ - Remove dupes │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Create Campaign │
│ Contact records │
│ (status: Pending) │
└─────────────────────┘
│
▼
┌─────────────────────┐ ┌─────────────────────┐
│ FOR EACH BATCH │────▶│ Rate Limited Send │
│ (200 contacts) │ │ (X msg/sec) │
└─────────────────────┘ └─────────────────────┘
│ │
▼ ▼
┌─────────────────────┐ ┌─────────────────────┐
│ Ensure Chatwoot │ │ Find/Create │
│ Contact exists │────▶│ Conversation │
└─────────────────────┘ └─────────────────────┘
│ │
▼ ▼
┌─────────────────────┐ ┌─────────────────────┐
│ Send Template Msg │────▶│ Update Campaign │
│ via Chatwoot API │ │ Contact status: │
│ with campaign*id │ │ Sent + messageId │
└─────────────────────┘ └─────────────────────┘
│
▼
┌─────────────────────┐
│ Webhook Delivery │
│ Status Updates: │
│ Sent → Delivered │
│ → Read │
└─────────────────────┘
┌─────────────────────────────────────────────────────────────────┐
│ DELIVERY WEBHOOK FLOW │
└─────────────────────────────────────────────────────────────────┘
│
▼
┌─────────────────────┐
│ Chatwoot sends │
│ webhook (message* │
│ status_update) │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ HMAC Validation │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Queue async job │
│ ProcessDelivery │
│ Webhook │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Find Campaign │
│ Contact by │
│ messageId │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Update status & │
│ timestamp │
│ (deliveredAt/ │
│ readAt) │
└─────────────────────┘
│
▼
┌─────────────────────┐
│ Update Campaign │
│ counters (delivered│
│ count, read count) │
└─────────────────────┘

---

Key Implementation Notes

1. Target List Integration
   EspoCRM's TargetList entity already has a relationship with Contact via TargetListContact. Use this existing junction table to resolve audience:
   $targetListIds = $campaign->get('targetListsIds');
foreach ($targetListIds as $targetListId) {
    $targetList = $this->entityManager->getEntity('TargetList', $targetListId);
    $contacts = $this->entityManager
        ->getRepository('TargetList')
        ->getRelated($targetList, 'contacts');
   // Add to audience array
   }
2. Rate Limiting Implementation
   $messagesPerSecond = $campaign->get('rateLimit');
$microsecondsBetweenSends = (1 / $messagesPerSecond) * 1000000;
foreach ($batch as $campaignContact) {
    $this->sendMessage($campaignContact);
   usleep($microsecondsBetweenSends);
   }
3. Chatwoot Template Message Payload
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
   'campaign_id' => $campaign->get('chatwootCampaignId'), // If Chatwoot campaign exists
   ];
4. Webhook Payload Structure (Expected from Chatwoot)
   Chatwoot webhooks for message status updates typically include:
   {
   event: message_status_updated,
   message: {
   id: 12345,
   status: delivered, // or "sent", "read", "failed"
   conversation_id: 67890,
   contact_id: 54321
   }
   }
