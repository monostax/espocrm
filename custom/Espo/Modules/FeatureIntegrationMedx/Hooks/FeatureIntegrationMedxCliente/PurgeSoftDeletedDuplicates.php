<?php

namespace Espo\Modules\FeatureIntegrationMedx\Hooks\FeatureIntegrationMedxCliente;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Prevent unique-key collisions on soft-delete.
 *
 * Unique index: (clienteId, credentialId, deleted)
 */
class PurgeSoftDeletedDuplicates
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        $clienteId = $entity->get('clienteId');
        $credentialId = $entity->get('credentialId');

        if ($clienteId === null || $credentialId === null) {
            return;
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from('FeatureIntegrationMedxCliente')
            ->where([
                'clienteId' => $clienteId,
                'credentialId' => $credentialId,
                'deleted' => true,
                'id!=' => $entity->getId(),
            ])
            ->build();

        $this->entityManager
            ->getQueryExecutor()
            ->execute($query);
    }
}
