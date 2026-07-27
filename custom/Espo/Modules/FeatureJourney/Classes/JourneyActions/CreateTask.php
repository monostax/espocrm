<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\EntityManager;

class CreateTask implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
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
            'status' => $params['status'] ?? 'Not Started',
            'priority' => $params['priority'] ?? 'Normal',
            'description' => $params['description'] ?? null,
            'dateEnd' => $params['dateEnd'] ?? null,
            'assignedUserId' => $assignedUserId,
            'parentType' => $context->target->getEntityType(),
            'parentId' => $context->target->getId(),
        ]);
        $this->tenantGuard->stampNewEntity($task, $tenantId, $teamsIds);

        $createdById = $context->actor?->getId() ?: 'system';

        $this->entityManager->saveEntity($task, [
            SaveOption::SILENT => true,
            'skipJourneyDispatch' => true,
            SaveOption::CREATED_BY_ID => $createdById,
        ]);
    }
}
