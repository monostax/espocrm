<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Global\Classes\RecordHooks\Funnel;

use Espo\Core\Acl;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\Hook\CreateHook;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * When a Funnel is created via Duplicate, clone its OpportunityStage rows
 * onto the new funnel (new IDs, same pipeline definition).
 *
 * Must not use recordDefs.duplicateLinkList for opportunityStages — that
 * would re-point existing stages at the new funnel instead of copying them.
 *
 * @implements CreateHook<Entity>
 * @noinspection PhpUnused
 */
class AfterCreateDuplicate implements CreateHook
{
    public function __construct(
        private Acl $acl,
        private EntityManager $entityManager,
        private Metadata $metadata
    ) {}

    public function process(Entity $entity, CreateParams $params): void
    {
        $sourceId = $params->getDuplicateSourceId();

        if (!$sourceId) {
            return;
        }

        $source = $this->entityManager->getEntityById('Funnel', $sourceId);

        if (!$source) {
            return;
        }

        if (!$this->acl->check($source, Acl\Table::ACTION_READ)) {
            return;
        }

        $this->duplicateOpportunityStages($entity, $sourceId);
    }

    private function duplicateOpportunityStages(Entity $newFunnel, string $sourceFunnelId): void
    {
        $stages = $this->entityManager
            ->getRDBRepository('OpportunityStage')
            ->where([
                'funnelId' => $sourceFunnelId,
                'deleted' => false,
            ])
            ->order('order', 'ASC')
            ->find();

        // Stage teams mirror the funnel's; OpportunityStage/InheritFunnelTeams
        // would also fill this in, but setting it here keeps the clone correct
        // even when that hook is skipped.
        $teamsIds = [];

        if ($newFunnel instanceof CoreEntity) {
            $teamsIds = $newFunnel->getLinkMultipleIdList('teams');
        }

        $copyMetaCapi =
            $this->metadata->get('entityDefs.OpportunityStage.fields.metaCapiEventName') !== null;

        foreach ($stages as $stage) {
            $clone = $this->entityManager->getNewEntity('OpportunityStage');

            $clone->set([
                'name' => $stage->get('name'),
                'order' => $stage->get('order'),
                'probability' => $stage->get('probability'),
                'style' => $stage->get('style'),
                'isActive' => $stage->get('isActive'),
                'description' => $stage->get('description'),
                'funnelId' => $newFunnel->getId(),
            ]);

            if ($teamsIds !== []) {
                $clone->set('teamsIds', $teamsIds);
            }

            if ($copyMetaCapi) {
                $clone->set('metaCapiEventName', $stage->get('metaCapiEventName'));
                $clone->set('metaCapiEventNameOnLeave', $stage->get('metaCapiEventNameOnLeave'));
            }

            $this->entityManager->saveEntity($clone);
        }
    }
}
