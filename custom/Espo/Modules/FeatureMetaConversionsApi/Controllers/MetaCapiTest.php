<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Services\CapiDispatcher;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Admin-only endpoint to dispatch a test event for an existing CRM record.
 *
 * Routes:
 *   POST /MetaCapiTest/sendForOpportunity   body: { id, eventName?, testEventCode? }
 *   POST /MetaCapiTest/sendForContact       body: { id, eventName?, testEventCode? }
 */
class MetaCapiTest
{
    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private User $user,
    ) {}

    public function postActionSendForOpportunity(Request $request): stdClass
    {
        $this->assertAdmin();

        $data = $request->getParsedBody();

        $id = $this->requireString($data, 'id');
        $eventName = $this->optionalString($data, 'eventName') ?? 'TestEvent';

        $entity = $this->entityManager->getEntityById('Opportunity', $id);
        if (!$entity) {
            throw new NotFound('Opportunity not found.');
        }

        $dataset = $this->resolveDatasetOverride($data);

        $logEntity = $this->injectableFactory
            ->create(CapiDispatcher::class)
            ->dispatch($entity, $eventName, $dataset);

        return (object) [
            'logId'           => $logEntity->getId(),
            'status'          => $logEntity->get('status'),
            'httpStatus'      => $logEntity->get('httpStatus'),
            'eventsReceived'  => $logEntity->get('eventsReceived'),
            'fbtraceId'       => $logEntity->get('fbtraceId'),
            'errorMessage'    => $logEntity->get('errorMessage'),
        ];
    }

    public function postActionSendForContact(Request $request): stdClass
    {
        $this->assertAdmin();

        $data = $request->getParsedBody();

        $id = $this->requireString($data, 'id');
        $eventName = $this->optionalString($data, 'eventName') ?? 'TestEvent';

        $entity = $this->entityManager->getEntityById('Contact', $id);
        if (!$entity) {
            throw new NotFound('Contact not found.');
        }

        $dataset = $this->resolveDatasetOverride($data);

        $logEntity = $this->injectableFactory
            ->create(CapiDispatcher::class)
            ->dispatch($entity, $eventName, $dataset);

        return (object) [
            'logId'           => $logEntity->getId(),
            'status'          => $logEntity->get('status'),
            'httpStatus'      => $logEntity->get('httpStatus'),
            'eventsReceived'  => $logEntity->get('eventsReceived'),
            'fbtraceId'       => $logEntity->get('fbtraceId'),
            'errorMessage'    => $logEntity->get('errorMessage'),
        ];
    }

    private function resolveDatasetOverride(stdClass $data): ?MetaCapiDataset
    {
        $datasetId = $this->optionalString($data, 'metaCapiDatasetId');

        if (!$datasetId) {
            return null;
        }

        $dataset = $this->entityManager->getEntityById('MetaCapiDataset', $datasetId);

        if (!$dataset instanceof MetaCapiDataset) {
            throw new NotFound('MetaCapiDataset not found.');
        }

        return $dataset;
    }

    private function assertAdmin(): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Admin access required.');
        }
    }

    private function requireString(stdClass $data, string $key): string
    {
        $value = $data->{$key} ?? null;

        if (!is_string($value) || $value === '') {
            throw new BadRequest("Missing or invalid '{$key}'.");
        }

        return $value;
    }

    private function optionalString(stdClass $data, string $key): ?string
    {
        $value = $data->{$key} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
