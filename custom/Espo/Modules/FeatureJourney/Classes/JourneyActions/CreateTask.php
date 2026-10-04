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

class CreateTask implements Action
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
            throw new Error('CreateTask: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $params = $context->params;
        $name = (string) ($params['name'] ?? $params['subject'] ?? ('Journey: ' . ($context->journey->get('name') ?: '')));
        $assignedUserId = isset($params['assignedUserId']) ? (string) $params['assignedUserId'] : null;

        if ($assignedUserId) {
            $this->tenantGuard->assertUserInTenant($assignedUserId, $tenantId, 'assignedUser');
        }

        $teamsIds = $this->tenantGuard->getJourneyTeamsIds($context->journey);

        $task = $this->entityManager->getNewEntity('Task');
        $task->set([
            'name' => $name,
            'status' => $params['status'] ?? 'Planned',
            'priority' => $params['priority'] ?? 'Normal',
            'description' => $params['description'] ?? null,
            'dateEnd' => $params['dateEnd'] ?? null,
            'assignedUserId' => $assignedUserId,
            'parentType' => $context->target->getEntityType(),
            'parentId' => $context->target->getId(),
        ]);
        if (isset($params['dueInBusinessDays']) && $params['dueInBusinessDays'] !== '') {
            if (!empty($params['dateEnd'])) {
                throw new Error('CreateTask: choose dateEnd or dueInBusinessDays, not both.');
            }
            $base = ($params['dueDateBase'] ?? 'enrollment') === 'stageEntry'
                ? (string) $context->record->get('enteredStageAt')
                : (string) ($context->record->get('createdAt') ?: $context->record->get('enteredStageAt'));
            if ($base === '') {
                throw new Error('CreateTask: enrollment date is required for business-day scheduling.');
            }
            $task->set('dateEndDate', $this->businessDaySchedule->dueDate(
                $base,
                $params['dueInBusinessDays'],
                (string) ($params['timeZone'] ?? $this->config->get('timeZone') ?? 'UTC'),
            ));
        }
        $this->tenantGuard->stampNewEntity($task, $tenantId, $teamsIds);

        $createdById = $context->actor?->getId() ?: 'system';

        $this->entityManager->saveEntity($task, [
            SaveOption::SILENT => true,
            'skipJourneyDispatch' => true,
            SaveOption::CREATED_BY_ID => $createdById,
        ]);

        $context->createdRecord = $task;
    }
}
