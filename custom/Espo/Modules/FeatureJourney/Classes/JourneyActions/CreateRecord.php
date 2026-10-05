<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\Modules\FeatureJourney\Services\BusinessDaySchedule;
use Espo\Core\Utils\Config;
use Espo\ORM\EntityManager;

/**
 * Tenant-safe generic create. Stamps tenantId + journey teams; field allow-list.
 * Params: entityType, fields{}, optional linkToTarget (parent / belongsTo link name).
 */
class CreateRecord implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
        private BusinessDaySchedule $businessDaySchedule,
        private Config $config,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('CreateRecord: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $entityType = (string) ($context->params['entityType'] ?? $context->params['link'] ?? '');
        if ($entityType === '') {
            throw new Error('CreateRecord: entityType is required.');
        }

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

        // Creating an email is a manual draft, never a send/schedule operation.
        if ($entityType === 'Email') {
            $entity->set('status', 'Draft');
            $entity->set('sendAt', null);
        }
        $this->applySchedule($entity, $context);

        $linkToTarget = (string) ($context->params['linkToTarget'] ?? '');
        if ($linkToTarget !== '') {
            $this->attachTargetLink($entity, $context, $linkToTarget);
        }

        $this->applyContactAccountDefaults($context->target, $entity);

        $teamsIds = $this->tenantGuard->getJourneyTeamsIds($context->journey);
        $this->tenantGuard->stampNewEntity($entity, $tenantId, $teamsIds);

        $createdById = $context->actor?->getId() ?: 'system';

        $this->entityManager->saveEntity($entity, [
            SaveOption::SILENT => true,
            'skipJourneyDispatch' => true,
            SaveOption::CREATED_BY_ID => $createdById,
        ]);

        $context->createdRecord = $entity;
    }

    private function applySchedule(\Espo\ORM\Entity $entity, ActionContext $context): void
    {
        $params = $context->params;
        if (!isset($params['dueInBusinessDays']) || $params['dueInBusinessDays'] === '') {
            return;
        }
        $type = $entity->getEntityType();
        if (!in_array($type, ['Call', 'Email'], true)) {
            throw new Error('Business-day record scheduling supports Call and Email only.');
        }
        $timeZone = (string) ($params['timeZone'] ?? $this->config->get('timeZone') ?? 'UTC');
        $base = ($params['dueDateBase'] ?? 'enrollment') === 'stageEntry'
            ? $context->record->get('enteredStageAt')
            : ($context->record->get('createdAt') ?: $context->record->get('enteredStageAt'));
        if (!$base) {
            throw new Error('Business-day scheduling requires an enrollment date.');
        }
        $date = $this->businessDaySchedule->dueDate((string) $base, $params['dueInBusinessDays'], $timeZone);
        if ($type === 'Email') {
            $entity->set('journeyDueDate', $date);
            return;
        }
        if ($entity->get('dateStart') || $entity->get('dateEnd')) {
            throw new Error('Choose explicit call dates or dueInBusinessDays, not both.');
        }
        // Calls require a time and duration; cadence calls use 09:00 local, five minutes.
        $start = (new \DateTimeImmutable($date . ' 09:00:00', new \DateTimeZone($timeZone)))
            ->setTimezone(new \DateTimeZone('UTC'));
        $entity->set('dateStart', $start->format('Y-m-d H:i:s'));
        $entity->set('dateEnd', $start->modify('+5 minutes')->format('Y-m-d H:i:s'));
    }

    private function attachTargetLink(\Espo\ORM\Entity $entity, ActionContext $context, string $link): void
    {
        $target = $context->target;

        if ($entity->hasRelation($link)) {
            $type = $entity->getRelationType($link);
            if ($type === $entity::BELONGS_TO) {
                if ($entity->getRelationParam($link, 'entity') !== $target->getEntityType()) {
                    throw new Error("CreateRecord: link '{$link}' does not accept {$target->getEntityType()}.");
                }
                $entity->set($link . 'Id', $target->getId());

                return;
            }
            if ($type === $entity::BELONGS_TO_PARENT) {
                $entity->set($link . 'Id', $target->getId());
                $entity->set($link . 'Type', $target->getEntityType());

                return;
            }
        }

        // Common parent polymorphic on Task/Note/etc.
        if ($entity->hasAttribute('parentId') && $entity->hasAttribute('parentType')) {
            $entity->set('parentId', $target->getId());
            $entity->set('parentType', $target->getEntityType());
        }
    }

    /**
     * When the target is a Contact and the new record can take accountId,
     * copy the contact's primary account if fields left it empty.
     */
    private function applyContactAccountDefaults(\Espo\ORM\Entity $target, \Espo\ORM\Entity $entity): void
    {
        if ($target->getEntityType() !== 'Contact') {
            return;
        }

        if (!$entity->hasAttribute('accountId')) {
            return;
        }

        $existing = $entity->get('accountId');
        if (is_string($existing) && $existing !== '') {
            return;
        }

        $accountId = $target->get('accountId');
        if (!is_string($accountId) || $accountId === '') {
            return;
        }

        $entity->set('accountId', $accountId);
    }
}
