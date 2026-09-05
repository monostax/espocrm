<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourneyRecord;

use Espo\Modules\FeatureSimpleJourney\Services\ValidationError;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\FeatureSimpleJourney\Services\JourneyAccess;
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
        private JourneyAccess $journeyAccess,
        private UserTenantResolver $userTenantResolver,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $journey = $this->journeyAccess->requireParent($entity, 'read');
        $stageId = $entity->get('stageId');
        $stage = is_string($stageId) && $stageId !== ''
            ? $this->entityManager->getEntityById('SimpleJourneyStage', $stageId)
            : null;

        if (!$stage || $stage->get('journeyId') !== $journey->getId()) {
            throw ValidationError::badRequest('stageMismatch', 'The selected stage must belong to the selected journey.');
        }

        $enteringStage = $entity->isNew() || $entity->isAttributeChanged('stageId');

        if ($enteringStage && (!$journey->get('isActive') || !$stage->get('isActive'))) {
            throw ValidationError::badRequest('inactiveEntry', 'Records can only enter active journeys and stages.');
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

        $this->validateAssignee($entity, (string) $journey->get('tenantId'));
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
            throw ValidationError::badRequest('assigneeTenantMismatch', 'The assigned user must belong to the journey tenant.');
        }
    }
}
