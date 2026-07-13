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

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\WhatsAppCampaignOpportunityService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Scheduled repair sweep for WhatsApp campaign recipients.
 *
 * 1. Stale claim recovery: rows left in Processing by a crashed worker are
 *    marked Failed with an explicit unknown-delivery reason. They are never
 *    resent — the message may already have been accepted remotely, and a
 *    duplicate send is worse than a false negative.
 *
 * 2. Opportunity attribution retry: rows whose message was accepted
 *    (Sent/Delivered/Read/Replied with message and conversation IDs) but
 *    whose attribution is still Pending or Failed are retried, so a crash or
 *    transient database failure can never permanently leave a successful
 *    send without its Opportunity.
 */
class RepairWhatsAppCampaignRecipients implements JobDataLess
{
    /** Minutes after which a Processing claim is considered abandoned. */
    private const STALE_CLAIM_MINUTES = 15;

    /** Maximum attribution retries per run. */
    private const ATTRIBUTION_BATCH_SIZE = 200;

    public function __construct(
        private EntityManager $entityManager,
        private WhatsAppCampaignOpportunityService $opportunityService,
        private Log $log,
    ) {}

    public function run(): void
    {
        $this->recoverStaleClaims();
        $this->retryOpportunityAttribution();
    }

    private function recoverStaleClaims(): void
    {
        $threshold = date('Y-m-d H:i:s', time() - self::STALE_CLAIM_MINUTES * 60);

        $rows = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'status' => 'Processing',
                'OR' => [
                    ['claimedAt<' => $threshold],
                    ['claimedAt' => null],
                ],
            ])
            ->find();

        $affectedCampaignIds = [];

        foreach ($rows as $row) {
            $row->set([
                'status' => 'Failed',
                'failedAt' => date('Y-m-d H:i:s'),
                'failedReason' =>
                    'Send interrupted before completion; delivery state unknown (stale claim recovery). ' .
                    'Not resent to avoid a duplicate message.',
                'opportunityAttributionStatus' => 'NotRequested',
            ]);
            $this->entityManager->saveEntity($row);

            $campaignId = $row->get('whatsAppCampaignId');

            if ($campaignId) {
                $affectedCampaignIds[$campaignId] = true;
            }

            $this->log->warning(
                "RepairWhatsAppCampaignRecipients: Recovered stale claim for recipient {$row->getId()} " .
                "(campaign {$campaignId})."
            );
        }

        foreach (array_keys($affectedCampaignIds) as $campaignId) {
            $this->recalculateCampaignCounters($campaignId);
        }
    }

    private function retryOpportunityAttribution(): void
    {
        $rows = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'status' => ['Sent', 'Delivered', 'Read', 'Replied'],
                'opportunityAttributionStatus' => ['Pending', 'Failed'],
            ])
            ->where(['chatwootMessageId!=' => ''])
            ->where(['chatwootConversationId!=' => ''])
            ->order('sentAt')
            ->limit(0, self::ATTRIBUTION_BATCH_SIZE)
            ->find();

        foreach ($rows as $row) {
            try {
                $opportunity = $this->opportunityService->attributeSuccessfulSend($row);

                if ($opportunity !== null) {
                    $this->log->info(
                        "RepairWhatsAppCampaignRecipients: Repaired Opportunity attribution for " .
                        "recipient {$row->getId()} (opportunity {$opportunity->getId()})."
                    );

                    continue;
                }

                // The campaign does not request Opportunity creation; stop
                // re-selecting the row on every sweep.
                if (!$row->get('opportunityId')) {
                    $row->set(['opportunityAttributionStatus' => 'NotRequested']);
                    $this->entityManager->saveEntity($row);
                }
            } catch (\Throwable $e) {
                $this->log->error(
                    "RepairWhatsAppCampaignRecipients: Opportunity attribution retry failed for " .
                    "recipient {$row->getId()}: {$e->getMessage()}"
                );

                $this->persistAttributionFailure($row, $e->getMessage());
            }
        }
    }

    private function persistAttributionFailure(Entity $row, string $errorMessage): void
    {
        try {
            $fresh = $this->entityManager->getEntityById('WhatsAppCampaignContact', $row->getId());

            if ($fresh) {
                $fresh->set([
                    'opportunityAttributionStatus' => 'Failed',
                    'opportunityAttributionError' => substr($errorMessage, 0, 5000),
                ]);
                $this->entityManager->saveEntity($fresh);
            }
        } catch (\Throwable $persistenceError) {
            $this->log->error(
                "RepairWhatsAppCampaignRecipients: Could not persist attribution failure for " .
                "recipient {$row->getId()}: {$persistenceError->getMessage()}"
            );
        }
    }

    /**
     * Rebuild aggregate counters from recipient rows (absolute values,
     * race-safe with webhooks and chunk jobs).
     */
    private function recalculateCampaignCounters(string $campaignId): void
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            return;
        }

        $repo = $this->entityManager->getRDBRepository('WhatsAppCampaignContact');

        $countForStatuses = function (array $statuses) use ($repo, $campaignId): int {
            $total = 0;

            foreach ($statuses as $status) {
                $total += $repo
                    ->where(['whatsAppCampaignId' => $campaignId, 'status' => $status])
                    ->count();
            }

            return $total;
        };

        $campaign->set([
            'sentCount' => $countForStatuses(['Sent', 'Delivered', 'Read', 'Replied']),
            'deliveredCount' => $countForStatuses(['Delivered', 'Read', 'Replied']),
            'readCount' => $countForStatuses(['Read', 'Replied']),
            'repliedCount' => $countForStatuses(['Replied']),
            'failedCount' => $countForStatuses(['Failed']),
        ]);

        $this->entityManager->saveEntity($campaign);
    }
}
