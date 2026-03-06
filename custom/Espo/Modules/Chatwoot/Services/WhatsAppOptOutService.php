<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Shared service for WhatsApp permanent-failure detection and auto-opt-out.
 *
 * Used by ProcessWhatsAppCampaignChunk (sync path) and
 * DeliveryWebhook (async webhook path) so the logic lives in one place.
 */
class WhatsAppOptOutService
{
    /** WhatsApp Cloud API error codes that indicate a permanently unreachable phone. */
    private const AUTO_OPTOUT_ERROR_CODES = ['131026'];

    private const CONTACT_LOOKUP_FAILURE = 'Failed to get Chatwoot contact ID';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * Check if an error message indicates a permanently unreachable phone number
     * that should trigger auto-opt-out.
     */
    public function isPermanentFailure(string $errorMessage): bool
    {
        if (str_contains($errorMessage, self::CONTACT_LOOKUP_FAILURE)) {
            return true;
        }

        foreach (self::AUTO_OPTOUT_ERROR_CODES as $code) {
            if (str_contains($errorMessage, $code . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Auto opt-out a contact after a permanent delivery failure.
     *
     * Sets the global whatsAppOptedOut flag on the Contact entity and
     * marks optedOut on every TargetList junction linked to the campaign.
     */
    public function autoOptOutContact(
        string $contactId,
        string $campaignId,
        string $reason
    ): void {
        if (!$contactId) {
            return;
        }

        try {
            $contact = $this->entityManager->getEntityById('Contact', $contactId);

            if ($contact && !$contact->get('whatsAppOptedOut')) {
                $contact->set('whatsAppOptedOut', true);
                $this->entityManager->saveEntity($contact);

                $this->log->info(
                    "WhatsAppOptOutService: Auto opt-out Contact {$contactId} " .
                    "(whatsAppOptedOut=true) due to: {$reason}"
                );
            }

            $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

            if (!$campaign) {
                return;
            }

            $targetLists = $this->entityManager
                ->getRDBRepository('WhatsAppCampaign')
                ->getRelation($campaign, 'targetLists')
                ->find();

            foreach ($targetLists as $targetList) {
                $this->entityManager
                    ->getRDBRepository('TargetList')
                    ->getRelation($targetList, 'contacts')
                    ->updateColumnsById($contactId, ['optedOut' => true]);
            }
        } catch (\Throwable $e) {
            $this->log->warning(
                "WhatsAppOptOutService: Failed to auto opt-out contact {$contactId}: " .
                $e->getMessage()
            );
        }
    }
}
