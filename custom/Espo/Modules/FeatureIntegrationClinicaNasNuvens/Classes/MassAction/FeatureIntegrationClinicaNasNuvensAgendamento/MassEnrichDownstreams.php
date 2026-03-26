<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Classes\MassAction\FeatureIntegrationClinicaNasNuvensAgendamento;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\MassAction\Data;
use Espo\Core\MassAction\MassAction;
use Espo\Core\MassAction\Params;
use Espo\Core\MassAction\QueryBuilder;
use Espo\Core\MassAction\Result;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\FeatureIntegrationClinicaNasNuvensAgendamento as AgendamentoService;
use Throwable;

/**
 * Mass action to enrich all downstream CNN entities for selected Agendamento records.
 */
class MassEnrichDownstreams implements MassAction
{
    public function __construct(
        private QueryBuilder $queryBuilder,
        private Acl $acl,
        private EntityManager $entityManager,
        private RecordServiceContainer $recordServiceContainer,
    ) {}

    public function process(Params $params, Data $data): Result
    {
        $entityType = $params->getEntityType();

        if (!$this->acl->check($entityType, Acl\Table::ACTION_READ)) {
            throw new Forbidden("No read access for '{$entityType}'.");
        }

        $query = $this->queryBuilder->build($params);

        $collection = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($query)
            ->sth()
            ->find();

        /** @var AgendamentoService $service */
        $service = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensAgendamento');

        $ids = [];
        $count = 0;

        foreach ($collection as $entity) {
            if (!$this->acl->checkEntityRead($entity)) {
                continue;
            }

            try {
                $service->enrichDownstreams($entity->getId());

                $ids[] = $entity->getId();
                $count++;
            } catch (Throwable $e) {
                // Skip failures silently; the service already logs them.
            }
        }

        return new Result($count, $ids);
    }
}
