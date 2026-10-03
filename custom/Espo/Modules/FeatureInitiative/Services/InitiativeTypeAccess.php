<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Services;

use Espo\Core\Acl;
use Espo\Core\ApplicationState;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Ownership and ACL are always read from the live parent, not copied to children. */
class InitiativeTypeAccess
{
    public function __construct(
        private EntityManager $entityManager,
        private ApplicationState $applicationState,
        private Acl $acl,
    ) {}

    public function requireParent(Entity $entity, string $action): Entity
    {
        if (!$entity->isNew() && $entity->isAttributeChanged('initiativeTypeId')) {
            throw ValidationError::badRequest('cannotReparent', 'A stage or initiative cannot be reassigned to another initiative type.');
        }

        $id = $entity->get('initiativeTypeId');
        $initiativeType = is_string($id) && $id !== ''
            ? $this->entityManager->getEntityById('InitiativeType', $id)
            : null;

        if (!$initiativeType || !$initiativeType->get('tenantId')) {
            throw ValidationError::badRequest('initiativeTypeRequired', 'An existing initiative type with an owning tenant is required.');
        }

        $this->assertReadable($initiativeType, $action);

        if ($entity->get('tenantId') && $entity->get('tenantId') !== $initiativeType->get('tenantId')) {
            throw ValidationError::badRequest('tenantMismatch', 'The tenant must match the initiative type tenant.');
        }

        $entity->set('tenantId', $initiativeType->get('tenantId'));
        $entity->set('tenantName', $initiativeType->get('tenantName'));

        return $initiativeType;
    }

    public function assertReadable(Entity $entity, string $action = 'read'): void
    {
        if ($this->applicationState->isLogged() && !$this->acl->checkEntity($entity, $action)) {
            throw ValidationError::forbidden();
        }
    }
}
