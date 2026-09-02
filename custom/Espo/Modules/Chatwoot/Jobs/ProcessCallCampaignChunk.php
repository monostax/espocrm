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

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Exceptions\Error;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Pushes a chunk of CallCampaignContact enrollments into the Chatwoot dialer
 * campaign as leads.
 *
 * Chunk jobs carry explicit enrollment ID lists (like the WhatsApp campaign
 * chunk jobs), so late enrollments cannot interfere with earlier chunks.
 * The Chatwoot side resolves contacts by phone at dial time, so the payload
 * is just (source_ref, phone_number, contact_name).
 */
class ProcessCallCampaignChunk implements Job
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $chatwootApiClient,
        private Log $log,
    ) {}

    /**
     * @throws Error
     */
    public function run(Data $data): void
    {
        $campaignId = $data->get('campaignId');
        $enrollmentIds = $data->get('enrollmentIds') ?? [];

        if (!$campaignId || !is_array($enrollmentIds) || $enrollmentIds === []) {
            throw new Error('ProcessCallCampaignChunk: campaignId and enrollmentIds are required.');
        }

        $campaign = $this->entityManager->getEntityById('CallCampaign', $campaignId);

        if (!$campaign) {
            $this->log->warning("ProcessCallCampaignChunk: Campaign {$campaignId} not found, skipping chunk.");

            return;
        }

        if ($campaign->get('status') === 'Cancelled') {
            $this->log->info("ProcessCallCampaignChunk: Campaign {$campaignId} was cancelled, skipping chunk.");

            return;
        }

        $dialerCampaignId = (int) ($campaign->get('dialerCampaignId') ?: 0);

        if (!$dialerCampaignId) {
            throw new Error("ProcessCallCampaignChunk: Campaign {$campaignId} has no Chatwoot dialer campaign id.");
        }

        $context = $this->resolvePlatformContext($campaign);

        $enrollments = $this->entityManager
            ->getRDBRepository('CallCampaignContact')
            ->where([
                'id' => $enrollmentIds,
                'callCampaignId' => $campaignId,
            ])
            ->find();

        $leads = [];

        foreach ($enrollments as $enrollment) {
            $leads[] = [
                'source_ref' => $enrollment->getId(),
                'phone_number' => (string) $enrollment->get('phoneNumber'),
                'contact_name' => (string) ($enrollment->get('contactName') ?: ''),
            ];
        }

        if ($leads === []) {
            $this->log->info("ProcessCallCampaignChunk: No pending enrollments for campaign {$campaignId} chunk.");

            return;
        }

        $this->chatwootApiClient->addDialerLeads(
            $context['platformUrl'],
            $context['apiKey'],
            $context['chatwootAccountId'],
            $dialerCampaignId,
            $leads
        );

        $this->log->info(sprintf(
            'ProcessCallCampaignChunk: Pushed %d leads to Chatwoot dialer campaign %d.',
            count($leads),
            $dialerCampaignId,
        ));
    }

    /**
     * @return array{platformUrl: string, apiKey: string, chatwootAccountId: int}
     * @throws Error
     */
    private function resolvePlatformContext(Entity $campaign): array
    {
        $chatwootAccount = $this->entityManager
            ->getEntityById('ChatwootAccount', (string) $campaign->get('chatwootAccountId'));

        if (!$chatwootAccount) {
            throw new Error("Chat account not found for call campaign {$campaign->getId()}.");
        }

        $platform = $this->entityManager
            ->getEntityById('ChatwootPlatform', (string) $chatwootAccount->get('platformId'));

        if (!$platform) {
            throw new Error('Chatwoot platform not found for the campaign chat account.');
        }

        $platformUrl = $platform->get('backendUrl');
        $apiKey = $chatwootAccount->get('apiKey');
        $chatwootAccountId = (int) $chatwootAccount->get('chatwootAccountId');

        if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
            throw new Error('Missing Chatwoot connection details (URL, API key, or account ID).');
        }

        return [
            'platformUrl' => (string) $platformUrl,
            'apiKey' => (string) $apiKey,
            'chatwootAccountId' => $chatwootAccountId,
        ];
    }
}
