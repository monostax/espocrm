<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax - Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\FeatureEmailCampaign\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Creates or reuses an Opportunity after a successful email send.
 *
 * Reuse key matches WhatsAppCampaign: open Opportunity for Contact + funnel.
 * Dual-channel campaigns (WA + Email) with the same funnel share one Opportunity.
 */
class EmailCampaignOpportunityService
{
    public function __construct(
        private EntityManager $entityManager,
        private \Espo\Core\Utils\Log $log,
    ) {}

    /**
     * @throws BadRequest
     * @throws Error
     */
    public function assertConfiguration(Entity $campaign): void
    {
        if (!$campaign->get('createOpportunity')) {
            return;
        }

        $funnelId = $this->string($campaign->get('funnelId'));
        $stageId = $this->string($campaign->get('opportunityStageId'));
        $assignedUserId = $this->string($campaign->get('opportunityAssignedUserId'));

        if ($funnelId === null || $stageId === null || $assignedUserId === null) {
            throw new BadRequest(
                'Funnel, initial Opportunity Stage and Opportunity Assigned User are required when Opportunity creation is enabled.'
            );
        }

        $funnel = $this->entityManager->getEntityById('Funnel', $funnelId);

        if (!$funnel || !$funnel->get('isActive')) {
            throw new BadRequest('The configured Opportunity funnel is missing or inactive.');
        }

        $stage = $this->entityManager->getEntityById('OpportunityStage', $stageId);

        if (
            !$stage
            || !$stage->get('isActive')
            || (string) $stage->get('funnelId') !== $funnelId
        ) {
            throw new BadRequest('The configured Opportunity stage is missing, inactive or belongs to another funnel.');
        }

        $probability = (int) $stage->get('probability');

        if ($probability <= 0 || $probability >= 100) {
            throw new BadRequest('The initial Opportunity stage must be open (probability between 1 and 99).');
        }

        $funnelTeamIds = $this->teamIds($funnel);

        if ($funnelTeamIds === []) {
            throw new Error('The configured Opportunity funnel has no teams.');
        }

        $campaignTeamIds = $this->teamIds($campaign);

        if ($campaignTeamIds !== [] && array_intersect($campaignTeamIds, $funnelTeamIds) === []) {
            throw new BadRequest('The Opportunity funnel must share a team with the campaign.');
        }

        $stageTeamIds = $this->teamIds($stage);

        if ($stageTeamIds !== [] && array_intersect($stageTeamIds, $funnelTeamIds) === []) {
            throw new BadRequest('The Opportunity stage must share a team with the configured funnel.');
        }

        $assignedUser = $this->entityManager->getEntityById('User', $assignedUserId);

        if (
            !$assignedUser
            || !$assignedUser->get('isActive')
            || array_intersect($this->teamIds($assignedUser), $funnelTeamIds) === []
        ) {
            throw new BadRequest('The Opportunity assigned user must be active and belong to a funnel team.');
        }
    }

    /**
     * @throws BadRequest
     * @throws Error
     */
    public function attributeSuccessfulSend(Entity $campaignContact): ?Entity
    {
        $campaignId = $this->string($campaignContact->get('emailCampaignId'));

        if ($campaignId === null) {
            throw new BadRequest('Campaign recipient has no campaign.');
        }

        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if (!$campaign) {
            throw new BadRequest('Campaign not found for Opportunity attribution.');
        }

        if (!$campaign->get('createOpportunity')) {
            return null;
        }

        $existingOpportunityId = $this->string($campaignContact->get('opportunityId'));

        if ($existingOpportunityId !== null) {
            return $this->entityManager->getEntityById(Opportunity::ENTITY_TYPE, $existingOpportunityId);
        }

        $this->assertConfiguration($campaign);

        $contactId = $this->string($campaignContact->get('contactId'));

        if ($contactId === null) {
            throw new BadRequest('Campaign recipient has no Contact.');
        }

        $targetListIds = $this->linkIds($campaignContact, 'targetLists', true);
        $transactionManager = $this->entityManager->getTransactionManager();
        $transactionManager->start();

        try {
            $contact = $this->entityManager
                ->getRDBRepository('Contact')
                ->forUpdate()
                ->where(['id' => $contactId])
                ->findOne();

            if (!$contact) {
                throw new BadRequest('Contact not found for Opportunity attribution.');
            }

            $freshCampaignContact = $this->entityManager
                ->getRDBRepository('EmailCampaignContact')
                ->forUpdate()
                ->where(['id' => $campaignContact->getId()])
                ->findOne();

            if (!$freshCampaignContact) {
                throw new BadRequest('Campaign recipient no longer exists.');
            }

            $existingOpportunityId = $this->string($freshCampaignContact->get('opportunityId'));

            if ($existingOpportunityId !== null) {
                $opportunity = $this->entityManager
                    ->getEntityById(Opportunity::ENTITY_TYPE, $existingOpportunityId);
                $transactionManager->commit();

                return $opportunity;
            }

            $funnelId = (string) $campaign->get('funnelId');

            // Same contact+funnel open-opp reuse as WhatsAppCampaign so dual
            // channel enrollment shares a single Opportunity.
            $matches = $this->entityManager
                ->getRDBRepository(Opportunity::ENTITY_TYPE)
                ->distinct()
                ->leftJoin('contacts')
                ->where([
                    'funnelId' => $funnelId,
                    'status' => 'Open',
                    'OR' => [
                        ['contactId' => $contactId],
                        ['contacts.id' => $contactId],
                    ],
                ])
                ->order([['createdAt', 'DESC'], ['id', 'DESC']])
                ->find();

            $opportunity = null;
            $matchCount = 0;

            foreach ($matches as $match) {
                $matchCount++;
                $opportunity ??= $match;
            }

            if ($matchCount > 1 && $opportunity) {
                $this->log->warning(sprintf(
                    'EmailCampaign Opportunity attribution found %d open Opportunities for contact=%s funnel=%s; reusing newest=%s.',
                    $matchCount,
                    $contactId,
                    $funnelId,
                    $opportunity->getId(),
                ));
            }

            $action = 'Reused';

            if (!$opportunity) {
                $opportunity = $this->createOpportunity($campaign, $contact, $targetListIds);
                $action = 'Created';
            }

            $this->relateIfMissing($opportunity, 'emailCampaigns', $campaignId);

            foreach ($targetListIds as $targetListId) {
                $this->relateIfMissing($opportunity, 'emailCampaignTargetLists', $targetListId);
            }

            $freshCampaignContact->set([
                'opportunityId' => $opportunity->getId(),
                'opportunityAttributionStatus' => 'Linked',
                'opportunityAttributionAction' => $action,
                'opportunityAttributionError' => null,
                'opportunityAttributionStageId' => $this->string($opportunity->get('opportunityStageId')),
            ]);
            $this->entityManager->saveEntity($freshCampaignContact);

            $transactionManager->commit();

            return $opportunity;
        } catch (Throwable $e) {
            $transactionManager->rollback();
            throw $e;
        }
    }

    /**
     * @param string[] $targetListIds
     */
    private function createOpportunity(
        Entity $campaign,
        Entity $contact,
        array $targetListIds,
    ): Entity {
        $contactName = trim((string) ($contact->get('name') ?: ''));
        $campaignName = trim((string) ($campaign->get('name') ?: 'Email Campaign'));

        $attributes = [
            'name' => $contactName !== ''
                ? sprintf('%s - %s', $contactName, $campaignName)
                : $campaignName,
            'amount' => 0.0,
            'contactId' => $contact->getId(),
            'contactsIds' => [$contact->getId()],
            'funnelId' => $campaign->get('funnelId'),
            'opportunityStageId' => $campaign->get('opportunityStageId'),
            'assignedUserId' => $campaign->get('opportunityAssignedUserId'),
            'teamsIds' => $this->teamIds(
                $this->entityManager->getEntityById('Funnel', (string) $campaign->get('funnelId'))
            ),
            'sourceChannel' => 'Outbound',
        ];

        $accountId = $this->string($contact->get('accountId'));

        if ($accountId !== null) {
            $attributes['accountId'] = $accountId;
        }

        if (count($targetListIds) === 1) {
            $attributes['sourceTargetListId'] = $targetListIds[0];
        }

        return $this->entityManager->createEntity(Opportunity::ENTITY_TYPE, $attributes);
    }

    private function relateIfMissing(Entity $entity, string $link, string $foreignId): void
    {
        $relation = $this->entityManager
            ->getRDBRepository($entity->getEntityType())
            ->getRelation($entity, $link);

        if (!$relation->isRelatedById($foreignId)) {
            $relation->relateById($foreignId);
        }
    }

    /**
     * @return string[]
     */
    private function linkIds(Entity $entity, string $link, bool $strict = false): array
    {
        $ids = [];

        try {
            $related = $this->entityManager
                ->getRDBRepository($entity->getEntityType())
                ->getRelation($entity, $link)
                ->select(['id'])
                ->find();

            foreach ($related as $item) {
                $ids[] = $item->getId();
            }
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }

            $ids = [];
        }

        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        sort($ids);

        return $ids;
    }

    /**
     * @return string[]
     */
    private function teamIds(?Entity $entity): array
    {
        if (!$entity) {
            return [];
        }

        return $this->linkIds($entity, 'teams');
    }

    private function string(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
