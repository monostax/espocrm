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

namespace Espo\Modules\Global\Tools\TargetList;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Crm\Entities\TargetList;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * "Launch Outreach": bulk-creates one Opportunity per eligible TargetList
 * contact — the outbound batch entering the funnel.
 *
 * Each created Opportunity is stamped with sourceChannel=Outbound and
 * sourceTargetList=this list (immutable first-touch attribution; feeds the
 * TrackStageChange ledger events, the TargetList state metrics loader and
 * the Outreach Flow panel).
 *
 * Eligibility guards (evaluated here, re-checked in the chunk job for
 * race safety):
 *   - list membership not opted out (relation optedOut column);
 *   - Contact.whatsAppOptedOut not true;
 *   - IDEMPOTENCY: no Opportunity already attributed to this list for the
 *     contact (re-launch only picks up newly added contacts);
 *   - DEDUP: no open Opportunity for the contact in the chosen funnel
 *     (the AI agent advances the existing one instead).
 *
 * Creation happens in chunked jobs (ProcessOutreachLaunchChunk), mirroring
 * Chatwoot's WhatsAppCampaignService chunk-scheduling pattern.
 *
 * ACL: requires read access to the TargetList, create access on
 * Opportunity, and read access to the chosen Funnel. New opportunities get
 * the funnel's teams (tenant isolation — also required for tenant
 * resolution in TrackStageChange) and the launching user as assignedUser.
 */
class LaunchOutreachService
{
    private const CHUNK_SIZE = 100;

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private JobSchedulerFactory $jobSchedulerFactory,
        private Log $log,
    ) {}

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function launch(string $targetListId, string $funnelId, string $stageId): stdClass
    {
        $targetList = $this->entityManager->getEntityById(TargetList::ENTITY_TYPE, $targetListId);

        if ($targetList === null) {
            throw new NotFound('Target List not found.');
        }

        if (!$this->acl->checkEntityRead($targetList)) {
            throw new Forbidden('No access to Target List.');
        }

        if (!$this->acl->checkScope('Opportunity', Acl\Table::ACTION_CREATE)) {
            throw new Forbidden('No create access for Opportunity.');
        }

        [$funnel, $stage] = $this->validateFunnelAndStage($funnelId, $stageId);

        $teamsIds = $this->resolveTeamsIds($funnel, $targetList);

        if ($teamsIds === []) {
            throw new Error('The funnel has no teams; cannot create opportunities without team scoping.');
        }

        $audienceIds = $this->fetchEligibleContactIds($targetList);
        $totalAudience = count($audienceIds);

        $skippedAttributed = $this->filterOutAlreadyAttributed($audienceIds, $targetListId);
        $skippedOpenInFunnel = $this->filterOutOpenInFunnel($audienceIds, $funnelId);

        $scheduled = count($audienceIds);

        // $audienceIds is a SET keyed by contact id (values are `true`) —
        // chunk the KEYS, not the values.
        $chunks = array_chunk(array_keys($audienceIds), self::CHUNK_SIZE);

        foreach ($chunks as $contactIdChunk) {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName('Espo\\Modules\\Global\\Jobs\\ProcessOutreachLaunchChunk')
                ->setData([
                    'targetListId' => $targetListId,
                    'funnelId' => $funnelId,
                    'stageId' => $stageId,
                    'assignedUserId' => $this->user->getId(),
                    'teamsIds' => $teamsIds,
                    'contactIds' => $contactIdChunk,
                ])
                ->schedule();
        }

        $this->log->info(sprintf(
            'LaunchOutreach: list=%s funnel=%s stage=%s by user=%s — audience=%d, ' .
            'skippedAttributed=%d, skippedOpenInFunnel=%d, scheduled=%d in %d chunk(s).',
            $targetListId,
            $funnelId,
            $stageId,
            $this->user->getId(),
            $totalAudience,
            $skippedAttributed,
            $skippedOpenInFunnel,
            $scheduled,
            count($chunks),
        ));

        return (object) [
            'audience' => $totalAudience,
            'scheduled' => $scheduled,
            'skippedAlreadyAttributed' => $skippedAttributed,
            'skippedOpenInFunnel' => $skippedOpenInFunnel,
            'chunks' => count($chunks),
        ];
    }

    /**
     * @return array{0: \Espo\ORM\Entity, 1: \Espo\ORM\Entity}
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    private function validateFunnelAndStage(string $funnelId, string $stageId): array
    {
        $funnel = $this->entityManager->getEntityById('Funnel', $funnelId);

        if ($funnel === null) {
            throw new NotFound('Funnel not found.');
        }

        if (!$this->acl->checkEntityRead($funnel)) {
            throw new Forbidden('No access to Funnel.');
        }

        if (!$funnel->get('isActive')) {
            throw new BadRequest('The funnel is not active.');
        }

        $stage = $this->entityManager->getEntityById('OpportunityStage', $stageId);

        if ($stage === null) {
            throw new NotFound('Opportunity Stage not found.');
        }

        if ($stage->get('funnelId') !== $funnelId) {
            throw new BadRequest('The stage does not belong to the chosen funnel.');
        }

        if (!$stage->get('isActive')) {
            throw new BadRequest('The stage is not active.');
        }

        return [$funnel, $stage];
    }

    /**
     * Funnel teams; falls back to the target list's teams.
     *
     * @return string[]
     */
    private function resolveTeamsIds(\Espo\ORM\Entity $funnel, \Espo\ORM\Entity $targetList): array
    {
        foreach (['funnel' => $funnel, 'targetList' => $targetList] as $entity) {
            $ids = [];

            $teams = $this->entityManager
                ->getRDBRepository($entity->getEntityType())
                ->getRelation($entity, 'teams')
                ->select(['id'])
                ->find();

            foreach ($teams as $team) {
                $ids[] = $team->getId();
            }

            if ($ids !== []) {
                return $ids;
            }
        }

        return [];
    }

    /**
     * List members that are sendable: not opted out on the list row and not
     * globally WhatsApp-opted-out.
     *
     * @return array<string, true> contactId => true
     */
    private function fetchEligibleContactIds(TargetList $targetList): array
    {
        $contacts = $this->entityManager
            ->getRDBRepository(TargetList::ENTITY_TYPE)
            ->getRelation($targetList, 'contacts')
            ->select(['id'])
            ->where([
                '@relation.optedOut' => false,
                'whatsAppOptedOut!=' => true,
            ])
            ->sth()
            ->find();

        $ids = [];

        foreach ($contacts as $contact) {
            $ids[$contact->getId()] = true;
        }

        return $ids;
    }

    /**
     * Removes contacts that already have an Opportunity attributed to this
     * list (idempotent re-launch). Returns the number removed.
     *
     * @param array<string, true> $audienceIds
     */
    private function filterOutAlreadyAttributed(array &$audienceIds, string $targetListId): int
    {
        if ($audienceIds === []) {
            return 0;
        }

        $rows = $this->entityManager
            ->getRDBRepository('Opportunity')
            ->select(['contactId'])
            ->where(['sourceTargetListId' => $targetListId])
            ->sth()
            ->find();

        return $this->removeByContactId($audienceIds, $rows);
    }

    /**
     * Removes contacts that already have an OPEN Opportunity in the chosen
     * funnel (the agent advances that one instead). Returns the number
     * removed.
     *
     * @param array<string, true> $audienceIds
     */
    private function filterOutOpenInFunnel(array &$audienceIds, string $funnelId): int
    {
        if ($audienceIds === []) {
            return 0;
        }

        $rows = $this->entityManager
            ->getRDBRepository('Opportunity')
            ->select(['contactId'])
            ->where([
                'funnelId' => $funnelId,
                'status' => 'Open',
                'contactId!=' => null,
            ])
            ->sth()
            ->find();

        return $this->removeByContactId($audienceIds, $rows);
    }

    /**
     * @param array<string, true> $audienceIds
     * @param iterable<\Espo\ORM\Entity> $rows
     */
    private function removeByContactId(array &$audienceIds, iterable $rows): int
    {
        $removed = 0;

        foreach ($rows as $row) {
            $contactId = $row->get('contactId');

            if (is_string($contactId) && isset($audienceIds[$contactId])) {
                unset($audienceIds[$contactId]);
                $removed++;
            }
        }

        return $removed;
    }
}
