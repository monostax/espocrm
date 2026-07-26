<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureJourney\Entities\Journey as JourneyEntity;
use Espo\Modules\FeatureJourney\Services\JourneyPublishService;
use stdClass;

class Journey extends Record implements \Espo\Core\Di\EntityManagerAware
{
    use \Espo\Core\Di\EntityManagerSetter;

    public function getActionReview(Request $request, Response $response): stdClass
    {
        $journey = $this->requireReadableJourney($request);

        $service = $this->injectableFactory->create(JourneyPublishService::class);
        $review = $service->review((string) $journey->getId());

        return $this->arrayToStdClass($review);
    }

    public function postActionActivate(Request $request, Response $response): stdClass
    {
        $journey = $this->requireEditableJourney($request);

        $service = $this->injectableFactory->create(JourneyPublishService::class);
        $result = $service->activateWithReport((string) $journey->getId());

        return $this->arrayToStdClass($result);
    }

    public function postActionPause(Request $request, Response $response): stdClass
    {
        $journey = $this->requireEditableJourney($request);

        if ($journey->get('status') !== JourneyEntity::STATUS_ACTIVE) {
            throw new BadRequest('Only Active journeys can be paused.');
        }

        $journey->set('status', JourneyEntity::STATUS_PAUSED);
        $this->entityManager->saveEntity($journey);

        return (object) $journey->getValueMap();
    }

    public function postActionStopEnrollment(Request $request, Response $response): stdClass
    {
        $journey = $this->requireEditableJourney($request);

        $journey->set('continuousEnrollment', false);
        $this->entityManager->saveEntity($journey);

        return (object) $journey->getValueMap();
    }

    public function postActionArchive(Request $request, Response $response): stdClass
    {
        $journey = $this->requireEditableJourney($request);

        if ($journey->get('status') === JourneyEntity::STATUS_ARCHIVED) {
            throw new BadRequest('Journey is already archived.');
        }

        $journey->set('status', JourneyEntity::STATUS_ARCHIVED);
        $this->entityManager->saveEntity($journey);

        return (object) $journey->getValueMap();
    }

    private function requireEditableJourney(Request $request): \Espo\ORM\Entity
    {
        $journey = $this->loadJourney($request);

        if (!$this->acl->check($journey, 'edit')) {
            throw new Forbidden('No edit access to Journey ' . $journey->getId() . '.');
        }

        return $journey;
    }

    private function requireReadableJourney(Request $request): \Espo\ORM\Entity
    {
        $journey = $this->loadJourney($request);

        if (!$this->acl->check($journey, 'read')) {
            throw new Forbidden('No read access to Journey ' . $journey->getId() . '.');
        }

        return $journey;
    }

    private function loadJourney(Request $request): \Espo\ORM\Entity
    {
        $id = $request->getRouteParam('id');
        if (!$id) {
            throw new BadRequest('Missing journey ID.');
        }

        $journey = $this->entityManager->getEntityById(JourneyEntity::ENTITY_TYPE, $id);
        if (!$journey) {
            throw new NotFound("Journey {$id} not found.");
        }

        return $journey;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayToStdClass(array $data): stdClass
    {
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }
}
