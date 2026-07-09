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

namespace Espo\Modules\Global\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Creates one Opportunity per contact for a "Launch Outreach" chunk —
 * scheduled by Tools\TargetList\LaunchOutreachService.
 *
 * Job data: targetListId, funnelId, stageId, assignedUserId,
 * teamsIds (string[]), contactIds (string[]).
 *
 * Guards are RE-CHECKED per contact (idempotency: an Opportunity already
 * attributed to the list; dedup: an open Opportunity in the funnel), so
 * duplicate scheduling, re-launch races and concurrent chunks are safe.
 *
 * Opportunities are saved through the ORM with regular hooks: Global's
 * SyncFromOpportunityStage derives status, FeatureTrackingEvent's
 * TrackStageChange emits the initial opportunity_stage_changed ledger event
 * (isNew=true, sourceChannel=Outbound, sourceTargetListId=batch) — which is
 * what makes the batch appear in the Outreach Flow metrics.
 *
 * A per-contact failure is logged and skipped; the chunk never aborts
 * midway.
 */
class ProcessOutreachLaunchChunk implements Job
{
    private const CLOSE_DATE_INTERVAL = '+30 days';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $targetListId = (string) $data->get('targetListId');
        $funnelId = (string) $data->get('funnelId');
        $stageId = (string) $data->get('stageId');
        $assignedUserId = (string) $data->get('assignedUserId');
        $teamsIds = (array) ($data->get('teamsIds') ?? []);
        $contactIds = (array) ($data->get('contactIds') ?? []);

        if ($targetListId === '' || $funnelId === '' || $stageId === '' || $contactIds === []) {
            $this->log->warning('ProcessOutreachLaunchChunk: incomplete job data; skipping.');

            return;
        }

        $closeDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(self::CLOSE_DATE_INTERVAL)
            ->format('Y-m-d');

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($contactIds as $contactId) {
            if (!is_string($contactId) || $contactId === '') {
                continue;
            }

            try {
                if ($this->createForContact(
                    $contactId,
                    $targetListId,
                    $funnelId,
                    $stageId,
                    $assignedUserId,
                    $teamsIds,
                    $closeDate,
                )) {
                    $created++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $failed++;

                $this->log->error(sprintf(
                    'ProcessOutreachLaunchChunk: failed for contact=%s list=%s: %s',
                    $contactId,
                    $targetListId,
                    $e->getMessage(),
                ));
            }
        }

        $this->log->info(sprintf(
            'ProcessOutreachLaunchChunk: list=%s funnel=%s — created=%d, skipped=%d, failed=%d.',
            $targetListId,
            $funnelId,
            $created,
            $skipped,
            $failed,
        ));
    }

    /**
     * @param string[] $teamsIds
     */
    private function createForContact(
        string $contactId,
        string $targetListId,
        string $funnelId,
        string $stageId,
        string $assignedUserId,
        array $teamsIds,
        string $closeDate,
    ): bool {
        $contact = $this->entityManager->getEntityById('Contact', $contactId);

        if ($contact === null) {
            return false;
        }

        // Idempotency: already attributed to this batch.
        $existing = $this->entityManager
            ->getRDBRepository('Opportunity')
            ->select(['id'])
            ->where([
                'sourceTargetListId' => $targetListId,
                'contactId' => $contactId,
            ])
            ->findOne();

        if ($existing !== null) {
            return false;
        }

        // Dedup: an open opportunity in the chosen funnel.
        $open = $this->entityManager
            ->getRDBRepository('Opportunity')
            ->select(['id'])
            ->where([
                'funnelId' => $funnelId,
                'contactId' => $contactId,
                'status' => 'Open',
            ])
            ->findOne();

        if ($open !== null) {
            return false;
        }

        $attributes = [
            'name' => $contact->get('name') ?: 'Outreach',
            'contactId' => $contactId,
            'contactsIds' => [$contactId],
            'funnelId' => $funnelId,
            'opportunityStageId' => $stageId,
            'closeDate' => $closeDate,
            'assignedUserId' => $assignedUserId !== '' ? $assignedUserId : null,
            'teamsIds' => $teamsIds,
            'sourceChannel' => 'Outbound',
            'sourceTargetListId' => $targetListId,
        ];

        $accountId = $contact->get('accountId');

        if (is_string($accountId) && $accountId !== '') {
            $attributes['accountId'] = $accountId;
        }

        $this->entityManager->createEntity('Opportunity', $attributes);

        return true;
    }
}
