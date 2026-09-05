<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Services;

use Espo\Core\Acl;
use Espo\Core\ApplicationState;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Ownership and ACL are always read from the live parent, not copied to children. */
class JourneyAccess
{
    public function __construct(
        private EntityManager $entityManager,
        private ApplicationState $applicationState,
        private Acl $acl,
    ) {}

    public function requireParent(Entity $entity, string $action): Entity
    {
        if (!$entity->isNew() && $entity->isAttributeChanged('journeyId')) {
            throw ValidationError::badRequest('cannotReparent', 'A stage or record cannot be reassigned to another journey.');
        }

        $id = $entity->get('journeyId');
        $journey = is_string($id) && $id !== ''
            ? $this->entityManager->getEntityById('SimpleJourney', $id)
            : null;

        if (!$journey || !$journey->get('tenantId')) {
            throw ValidationError::badRequest('journeyRequired', 'An existing journey with an owning tenant is required.');
        }

        $this->assertReadable($journey, $action);

        if ($entity->get('tenantId') && $entity->get('tenantId') !== $journey->get('tenantId')) {
            throw ValidationError::badRequest('tenantMismatch', 'The tenant must match the journey tenant.');
        }

        $entity->set('tenantId', $journey->get('tenantId'));
        $entity->set('tenantName', $journey->get('tenantName'));

        return $journey;
    }

    public function assertReadable(Entity $entity, string $action = 'read'): void
    {
        if ($this->applicationState->isLogged() && !$this->acl->checkEntity($entity, $action)) {
            throw ValidationError::forbidden();
        }
    }
}
