<?php

namespace Espo\Modules\Chatwoot\Hooks\User;

use Espo\Core\Job\QueueName;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Jobs\ProvisionUserMemberships;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Queue post-commit provisioning from every User creation path. */
class ProvisionChatwootMemberships
{
    public static int $order = 90;

    public function __construct(private EntityManager $entityManager) {}

    public function afterSave(Entity $entity, array $options): void
    {
        if ($entity->isNew() || $entity->isAttributeChanged('teamsIds') ||
            $entity->isAttributeChanged('emailAddress') || $entity->isAttributeChanged('isActive') ||
            $entity->isAttributeChanged('type')) {
            $this->schedule($entity, $options);
        }
    }

    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (in_array($relationParams['relationName'] ?? null, ['teams', 'tenants'], true)) {
            // Includes Tenant.SyncUserTeams and inverse Team.users relations.
            // Resolve teams in the job, once all field savers have completed.
            $this->schedule($entity, $options);
        }
    }

    private function schedule(Entity $entity, array $options): void
    {
        if (!empty($options['skipChatwootProvisioning']) || !$entity instanceof User ||
            !$entity->isActive() || !in_array($entity->getType(), [User::TYPE_REGULAR, User::TYPE_ADMIN], true)) {
            return;
        }

        $this->entityManager->createEntity('Job', [
            'name' => ProvisionUserMemberships::class,
            'className' => ProvisionUserMemberships::class,
            'queue' => QueueName::Q0,
            'attempts' => 5,
            'data' => (object) ['userId' => $entity->getId()],
        ]);
    }
}
