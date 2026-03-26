<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Hooks\FeatureIntegrationClinicaNasNuvensConvenioTipo;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Prevent unique-key collisions on soft-delete.
 *
 * Unique index: (convenioTipoId, credentialId, deleted)
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
        $convenioTipoId = $entity->get('convenioTipoId');
        $credentialId = $entity->get('credentialId');

        if ($convenioTipoId === null || $credentialId === null) {
            return;
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from('FeatureIntegrationClinicaNasNuvensConvenioTipo')
            ->where([
                'convenioTipoId' => $convenioTipoId,
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
