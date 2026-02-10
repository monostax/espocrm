<?php

namespace Espo\Modules\FeatureIntegrationSimplesAgenda\Hooks\SimplesAgendaCliente;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;

/**
 * Before soft-deleting a SimplesAgendaCliente, hard-delete any previously
 * soft-deleted rows that share the same (codCliente, credentialId) pair.
 *
 * This prevents a unique-constraint violation on the
 * UNIQ_COD_CLIENTE_CREDENTIAL index (codCliente, credentialId, deleted)
 * which would otherwise block the soft-delete when a deleted=1 row
 * already exists for the same key.
 */
class PurgeSoftDeletedDuplicates
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        $codCliente = $entity->get('codCliente');
        $credentialId = $entity->get('credentialId');

        if ($codCliente === null || $credentialId === null) {
            return;
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from('SimplesAgendaCliente')
            ->where([
                'codCliente' => $codCliente,
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
