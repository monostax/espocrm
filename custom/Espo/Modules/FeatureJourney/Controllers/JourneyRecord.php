<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Templates\Controllers\Base;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord as JourneyRecordEntity;
use Espo\Modules\FeatureJourney\Services\JourneyEnrollmentService;
use stdClass;

/**
 * Standard CRUD + lifecycle actions for JourneyRecord.
 */
class JourneyRecord extends Base implements \Espo\Core\Di\EntityManagerAware
{
    use \Espo\Core\Di\EntityManagerSetter;

    /**
     * POST JourneyRecord/{id}/exit — stop processing, keep history (status Exited).
     */
    public function postActionExit(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');
        $body = $request->getParsedBody();

        if (!$id && is_object($body) && isset($body->id)) {
            $id = (string) $body->id;
        }

        if (!$id) {
            throw new BadRequest('Missing JourneyRecord ID.');
        }

        $record = $this->entityManager->getEntityById(JourneyRecordEntity::ENTITY_TYPE, (string) $id);

        if (!$record) {
            throw new NotFound("JourneyRecord {$id} not found.");
        }

        if (!$this->acl->check($record, 'edit')) {
            throw new Forbidden("No edit access to JourneyRecord {$id}.");
        }

        $reason = 'manual';
        if (is_object($body) && isset($body->reason) && is_string($body->reason)) {
            $reason = trim($body->reason) !== '' ? trim($body->reason) : 'manual';
        }

        $service = $this->injectableFactory->create(JourneyEnrollmentService::class);
        $updated = $service->exitRecord(
            (string) $id,
            $this->user->getId() ? (string) $this->user->getId() : null,
            $reason,
        );

        return (object) ($updated->getValueMap() ?? []);
    }
}
