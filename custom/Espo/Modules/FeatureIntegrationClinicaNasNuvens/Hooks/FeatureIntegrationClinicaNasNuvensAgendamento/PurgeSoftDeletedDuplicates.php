<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Hooks\FeatureIntegrationClinicaNasNuvensAgendamento;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Prevent unique-key collisions on soft-delete.
 *
 * Unique index: (agendamentoId, credentialId, deleted)
 * If a deleted=1 duplicate already exists, deleting another row with the same
 * key would fail when it flips deleted from 0 to 1.
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
        $agendamentoId = $entity->get('agendamentoId');
        $credentialId = $entity->get('credentialId');

        if ($agendamentoId === null || $credentialId === null) {
            return;
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->where([
                'agendamentoId' => $agendamentoId,
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
