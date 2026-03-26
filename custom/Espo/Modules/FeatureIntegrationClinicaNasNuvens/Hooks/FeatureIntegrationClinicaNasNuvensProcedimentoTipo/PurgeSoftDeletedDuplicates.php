<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Hooks\FeatureIntegrationClinicaNasNuvensProcedimentoTipo;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Prevent unique-key collisions on soft-delete.
 *
 * Unique index: (procedimentoTipoId, credentialId, deleted)
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
        $procedimentoTipoId = $entity->get('procedimentoTipoId');
        $credentialId = $entity->get('credentialId');

        if ($procedimentoTipoId === null || $credentialId === null) {
            return;
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from('FeatureIntegrationClinicaNasNuvensProcedimentoTipo')
            ->where([
                'procedimentoTipoId' => $procedimentoTipoId,
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
