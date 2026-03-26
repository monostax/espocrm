<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Hooks\FeatureIntegrationClinicaNasNuvensProcedimentoConvenio;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Prevent unique-key collisions on soft-delete.
 *
 * Unique index: (procedimentoTipoId, convenioTipoId, deleted)
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
        $convenioTipoId = $entity->get('convenioTipoId');

        if ($procedimentoTipoId === null || $convenioTipoId === null) {
            return;
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from('FeatureIntegrationClinicaNasNuvensProcedimentoConvenio')
            ->where([
                'procedimentoTipoId' => $procedimentoTipoId,
                'convenioTipoId' => $convenioTipoId,
                'deleted' => true,
                'id!=' => $entity->getId(),
            ])
            ->build();

        $this->entityManager
            ->getQueryExecutor()
            ->execute($query);
    }
}
