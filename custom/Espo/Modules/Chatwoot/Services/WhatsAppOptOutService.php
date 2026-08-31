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

    /**
     * Substrings that mean "this number is not on WhatsApp" on QR (WAHA)
     * channels, which report failures as prose instead of Meta error codes.
     *
     * Matched case-insensitively against the failure reason. Kept deliberately
     * narrow: a false positive permanently opts a Contact out of WhatsApp.
     *
     * @var list<string>
     */
    private const AUTO_OPTOUT_ERROR_PHRASES = [
        'not registered on whatsapp',
        'phone number is not registered',
        'number is not on whatsapp',
        'not a whatsapp user',
        'no whatsapp account',
        'recipient not found',
        'invalid whatsapp number',
        'wid is not valid',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * Check if an error message indicates a permanently unreachable phone number
     * that should trigger auto-opt-out.
     *
     * Covers both Meta Cloud API numeric codes and the free-form wording used
     * by WAHA / QR sessions.
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

        $normalized = strtolower($errorMessage);

        foreach (self::AUTO_OPTOUT_ERROR_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
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
                // silent=true to avoid triggering Hooks/Contact/SyncToChatwoot
                // for an internal flag flip; the opt-out has no semantic
                // counterpart on Chatwoot side, so a push would be wasted.
                $this->entityManager->saveEntity($contact, ['silent' => true]);

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
