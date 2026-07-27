<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Create a related record on the journey target link, stamped to tenant + teams.
 * Params: link, fields{}, optional parentEntityType (belongsToParent filter).
 */
class CreateRelatedRecord implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('CreateRelatedRecord: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $link = (string) ($context->params['link'] ?? '');
        if ($link === '') {
            throw new Error('CreateRelatedRecord: link is required.');
        }

        $target = $context->target;
        if (!$target->hasRelation($link)) {
            throw new Error("CreateRelatedRecord: link '{$link}' missing on {$target->getEntityType()}.");
        }

        $entityType = $this->tenantGuard->resolveLinkForeignEntityType($target, $link);
        $this->tenantGuard->assertEntityTypeCreatable($entityType);

        $fields = $context->params['fields'] ?? [];
        if ($fields instanceof \stdClass) {
            $fields = (array) $fields;
        }
        if (!is_array($fields)) {
            $fields = [];
        }

        $filtered = $this->tenantGuard->filterMutationFields($entityType, $fields, $tenantId, true);

        $entity = $this->entityManager->getNewEntity($entityType);
        if ($filtered !== []) {
            $this->tenantGuard->applyTargetUpdateFields($entity, $filtered);
        }

        $this->wireRelationBeforeSave($target, $entity, $link);

        $teamsIds = $this->tenantGuard->getJourneyTeamsIds($context->journey);
        $this->tenantGuard->stampNewEntity($entity, $tenantId, $teamsIds);

        $createdById = $context->actor?->getId() ?: 'system';

        $this->entityManager->saveEntity($entity, [
            SaveOption::SILENT => true,
            'skipJourneyDispatch' => true,
            SaveOption::CREATED_BY_ID => $createdById,
        ]);

        $this->wireRelationAfterSave($target, $entity, $link);
    }

    private function wireRelationBeforeSave(Entity $target, Entity $entity, string $link): void
    {
        $linkType = $target->getRelationType($link);
        $foreign = $target->getRelationParam($link, 'foreign');

        if (is_string($foreign) && $foreign !== '' && $entity->hasRelation($foreign)) {
            $foreignType = $entity->getRelationType($foreign);
            if ($foreignType === Entity::BELONGS_TO) {
                $entity->set($foreign . 'Id', $target->getId());

                return;
            }
            if ($foreignType === Entity::BELONGS_TO_PARENT) {
                $entity->set($foreign . 'Id', $target->getId());
                $entity->set($foreign . 'Type', $target->getEntityType());

                return;
            }
        }

        if ($linkType === Entity::BELONGS_TO_PARENT || $entity->hasAttribute('parentId')) {
            if ($entity->hasAttribute('parentId') && $entity->hasAttribute('parentType')) {
                $entity->set('parentId', $target->getId());
                $entity->set('parentType', $target->getEntityType());
            }
        }
    }

    private function wireRelationAfterSave(Entity $target, Entity $entity, string $link): void
    {
        $linkType = $target->getRelationType($link);

        if ($linkType === Entity::BELONGS_TO) {
            $target->set($link . 'Id', $entity->getId());
            $this->entityManager->saveEntity($target, [
                SaveOption::SILENT => true,
                'skipJourneyDispatch' => true,
                'skipWorkflow' => true,
            ]);

            return;
        }

        if (in_array($linkType, [Entity::HAS_MANY, Entity::HAS_CHILDREN, Entity::MANY_MANY], true)) {
            $foreign = $target->getRelationParam($link, 'foreign');
            $already = is_string($foreign) && $entity->hasAttribute($foreign . 'Id')
                && (string) $entity->get($foreign . 'Id') === (string) $target->getId();

            if (!$already) {
                $this->entityManager
                    ->getRDBRepository($target->getEntityType())
                    ->getRelation($target, $link)
                    ->relate($entity);
            }
        }
    }
}
