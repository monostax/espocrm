<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Hooks\FeatureIntegrationClinicaNasNuvensConvenioTipo;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\FeatureIntegrationClinicaNasNuvensConvenioTipo as ConvenioTipoService;
use Espo\ORM\Entity;

class HydrateOnImport
{
    public static int $order = 10;

    public function __construct(
        private RecordServiceContainer $recordServiceContainer,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (empty($options[SaveOption::IMPORT])) {
            return;
        }

        $id = $entity->getId();

        if (!is_string($id) || $id === '') {
            return;
        }

        /** @var ConvenioTipoService $service */
        $service = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensConvenioTipo');
        $service->hydrateAfterImport($id);
    }
}
