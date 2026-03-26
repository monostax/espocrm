<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\FeatureIntegrationClinicaNasNuvensAgendamento as AgendamentoService;
use stdClass;

class FeatureIntegrationClinicaNasNuvensAgendamento extends \Espo\Core\Templates\Controllers\Base
{
    /**
     * POST FeatureIntegrationClinicaNasNuvensAgendamento/action/enrichDownstreams
     *
     * Enrich all downstream CNN entities related to a given Agendamento.
     *
     * @param Request $request Body: { "id": "agendamentoLocalId" }
     * @return stdClass { enriched: string[], errors: string[] }
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionEnrichDownstreams(Request $request, Response $response): stdClass
    {
        $data = $request->getParsedBody();
        $id = $data->id ?? null;

        if (!$id || !is_string($id)) {
            throw new BadRequest('Missing required parameter: id');
        }

        $entity = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensAgendamento', $id);

        if (!$entity) {
            throw new NotFound('Agendamento not found.');
        }

        if (!$this->acl->check($entity, 'read')) {
            throw new Forbidden('Access denied.');
        }

        /** @var AgendamentoService $service */
        $service = $this->injectableFactory
            ->create(RecordServiceContainer::class)
            ->get('FeatureIntegrationClinicaNasNuvensAgendamento');

        $result = $service->enrichDownstreams($id);

        return (object) $result;
    }
}
