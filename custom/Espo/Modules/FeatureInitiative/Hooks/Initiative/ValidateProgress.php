<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Hooks\Initiative;

use Espo\Modules\FeatureInitiative\Services\ValidationError;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\FeatureInitiative\Services\InitiativeTypeAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateProgress implements BeforeSave
{
    public static int $order = 10;

    public const STATUSES = ['On Hold', 'To Do', 'Doing', 'Done'];

    public function __construct(
        private EntityManager $entityManager,
        private InitiativeTypeAccess $initiativeTypeAccess,
        private UserTenantResolver $userTenantResolver,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $initiativeType = $this->initiativeTypeAccess->requireParent($entity, 'read');
        $stageId = $entity->get('stageId');
        $stage = is_string($stageId) && $stageId !== ''
            ? $this->entityManager->getEntityById('InitiativeStage', $stageId)
            : null;

        if (!$stage || $stage->get('initiativeTypeId') !== $initiativeType->getId()) {
            throw ValidationError::badRequest('stageMismatch', 'The selected stage must belong to the selected initiative type.');
        }

        $enteringStage = $entity->isNew() || $entity->isAttributeChanged('stageId');

        if ($enteringStage && (!$initiativeType->get('isActive') || !$stage->get('isActive'))) {
            throw ValidationError::badRequest('inactiveEntry', 'Initiatives can only enter active types and stages.');
        }

        if ($entity->isNew() && $entity->get('status') === null) {
            $entity->set('status', 'To Do');
        }

        if (!in_array($entity->get('status'), self::STATUSES, true)) {
            throw ValidationError::badRequest('invalidStatus', 'Status must be On Hold, To Do, Doing or Done.');
        }

        // Status belongs to the current record/stage pair, never to the stage itself.
        if (!$entity->isNew() && $enteringStage) {
            $entity->set('status', 'To Do');
        }

        $this->validateAssignee($entity, (string) $initiativeType->get('tenantId'));
    }

    private function validateAssignee(Entity $entity, string $tenantId): void
    {
        if (!$entity->isNew() && !$entity->isAttributeChanged('assignedUserId')) {
            return;
        }

        $id = $entity->get('assignedUserId');

        if (!$id) {
            return;
        }

        $user = is_string($id) ? $this->entityManager->getEntityById('User', $id) : null;

        if (!$user instanceof User || !in_array($tenantId, $this->userTenantResolver->resolveTenantIds($user), true)) {
            throw ValidationError::badRequest('assigneeTenantMismatch', 'The assigned user must belong to the initiative tenant.');
        }
    }
}
