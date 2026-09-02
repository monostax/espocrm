<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Applies Chatwoot power-dialer outcomes (`dialer_lead_dispositioned`
 * webhook events) to the CRM:
 *
 *  - Updates the CallCampaignContact enrollment (status, disposition,
 *    attempts, notes, last outcome timestamp)
 *  - Backfills the ChatwootContact link from the dialer lead payload
 *  - Enforces Do Not Call (Contact.callOptedOut) on `dnc`
 *  - Attributes Opportunities for positive dispositions
 *  - Links the chat conversation to the attributed Opportunity
 *
 * Enrollment and Chatwoot lead reference each other by the enrollment id
 * (Chatwoot `source_ref`), so no phone/time-window matching is needed.
 */
class CallCampaignOutcomeSync
{
    /** Lead statuses that mark an enrollment finished. */
    private const TERMINAL_LEAD_STATUSES = ['done', 'blocked'];

    public function __construct(
        private EntityManager $entityManager,
        private CallCampaignOpportunityService $opportunityService,
        private Log $log
    ) {}

    public function applyOutcome(object $data, Entity $account): stdClass
    {
        $campaignPayload = $data->dialer_campaign ?? null;
        $leadPayload = $data->dialer_lead ?? null;

        if (!$campaignPayload || !$leadPayload) {
            return (object) ['success' => false, 'message' => 'Missing dialer_campaign/dialer_lead payload.'];
        }

        $campaignSourceRef = $campaignPayload->source_ref ?? null;
        $leadSourceRef = $leadPayload->source_ref ?? null;

        if (!$campaignSourceRef || !$leadSourceRef) {
            $this->log->warning('CallCampaignOutcomeSync: Missing source_ref in dialer outcome payload.');

            return (object) ['success' => false, 'message' => 'Missing source_ref.'];
        }

        $campaign = $this->entityManager
            ->getRDBRepository('CallCampaign')
            ->where(['id' => $campaignSourceRef])
            ->findOne();

        if (!$campaign) {
            $this->log->warning("CallCampaignOutcomeSync: Call campaign {$campaignSourceRef} not found.");

            return (object) ['success' => false, 'message' => 'Campaign not found.'];
        }

        if ($campaign->get('chatwootAccountId') !== $account->getId()) {
            $this->log->warning(sprintf(
                'CallCampaignOutcomeSync: Campaign %s belongs to chat account %s, event arrived for %s.',
                $campaignSourceRef,
                (string) $campaign->get('chatwootAccountId'),
                $account->getId(),
            ));

            return (object) ['success' => false, 'message' => 'Campaign/account mismatch.'];
        }

        $enrollment = $this->entityManager
            ->getRDBRepository('CallCampaignContact')
            ->where([
                'id' => $leadSourceRef,
                'callCampaignId' => $campaign->getId(),
            ])
            ->findOne();

        if (!$enrollment) {
            $this->log->warning("CallCampaignOutcomeSync: Enrollment {$leadSourceRef} not found.");

            return (object) ['success' => false, 'message' => 'Enrollment not found.'];
        }

        $disposition = (string) ($leadPayload->disposition ?? '');
        $leadStatus = (string) ($leadPayload->status ?? '');

        $enrollment->set([
            'status' => in_array($leadStatus, self::TERMINAL_LEAD_STATUSES, true)
                ? ($leadStatus === 'blocked' ? 'Blocked' : 'Done')
                : 'Pending',
            'disposition' => $disposition,
            'attempts' => (int) ($leadPayload->attempts ?? $enrollment->get('attempts')),
            'notes' => $leadPayload->notes ?? $enrollment->get('notes'),
            'lastOutcomeAt' => date('Y-m-d H:i:s'),
        ]);

        $this->backfillChatwootContact($enrollment, $leadPayload, $account);
        $this->entityManager->saveEntity($enrollment);

        if ($disposition === 'dnc') {
            $this->applyDoNotCall($enrollment);
        }

        $opportunity = $this->attributeOpportunity($campaign, $enrollment, $disposition);

        if ($opportunity) {
            $this->linkConversationIfAvailable($opportunity, $leadPayload, $account);
        }

        return (object) ['success' => true, 'enrollmentId' => $enrollment->getId()];
    }

    private function backfillChatwootContact(Entity $enrollment, object $leadPayload, Entity $account): void
    {
        if ($enrollment->get('chatwootContactId')) {
            return;
        }

        $chatwootContactId = $leadPayload->contact_id ?? null;

        if (!$chatwootContactId) {
            return;
        }

        $chatwootContact = $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->where([
                'chatwootContactId' => (int) $chatwootContactId,
                'chatwootAccountId' => $account->getId(),
            ])
            ->findOne();

        if ($chatwootContact) {
            $enrollment->set('chatwootContactId', $chatwootContact->getId());
        }
    }

    private function applyDoNotCall(Entity $enrollment): void
    {
        try {
            $contact = $this->entityManager->getEntityById('Contact', (string) $enrollment->get('contactId'));

            if ($contact && !$contact->get('callOptedOut')) {
                $contact->set('callOptedOut', true);
                $this->entityManager->saveEntity($contact);
                $this->log->info(sprintf(
                    'CallCampaignOutcomeSync: Contact %s opted out of calls (DNC).',
                    $contact->getId(),
                ));
            }
        } catch (\Throwable $e) {
            $this->log->error("CallCampaignOutcomeSync: Failed to set callOptedOut: {$e->getMessage()}");
        }
    }

    /**
     * Attribution state and dial state are deliberately independent: a
     * failure here is recorded on the enrollment but never rolls the
     * outcome back.
     */
    private function attributeOpportunity(Entity $campaign, Entity $enrollment, string $disposition): ?Entity
    {
        if (!$campaign->get('createOpportunity') || $disposition === '') {
            return null;
        }

        $attributionDispositions = $campaign->get('attributionDispositions');

        if ($attributionDispositions instanceof stdClass) {
            $attributionDispositions = (array) $attributionDispositions;
        }

        if (!is_array($attributionDispositions) || !in_array($disposition, $attributionDispositions, true)) {
            return null;
        }

        try {
            return $this->opportunityService->attributeDisposition($enrollment);
        } catch (\Throwable $e) {
            $this->log->error(sprintf(
                'CallCampaignOutcomeSync: Opportunity attribution failed for enrollment %s: %s',
                $enrollment->getId(),
                $e->getMessage(),
            ));

            $enrollment->set([
                'opportunityAttributionStatus' => 'Failed',
                'opportunityAttributionError' => $e->getMessage(),
            ]);
            $this->entityManager->saveEntity($enrollment);

            return null;
        }
    }

    private function linkConversationIfAvailable(Entity $opportunity, object $leadPayload, Entity $account): void
    {
        $conversationId = $leadPayload->conversation_id ?? null;

        if (!$conversationId) {
            return;
        }

        try {
            $conversation = $this->entityManager
                ->getRDBRepository('ChatwootConversation')
                ->where([
                    'chatwootConversationId' => (int) $conversationId,
                    'chatwootAccountId' => $account->getId(),
                ])
                ->findOne();

            if ($conversation) {
                $relation = $this->entityManager
                    ->getRDBRepository('Opportunity')
                    ->getRelation($opportunity, 'chatwootConversations');

                if (!$relation->isRelatedById($conversation->getId())) {
                    $relation->relateById($conversation->getId());
                }
            }
        } catch (\Throwable $e) {
            $this->log->warning(sprintf(
                'CallCampaignOutcomeSync: Could not link conversation %s to opportunity %s: %s',
                (string) $conversationId,
                $opportunity->getId(),
                $e->getMessage(),
            ));
        }
    }
}
