<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Di;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureAutomation\Services\AutomationRunner;
use stdClass;

class Automation extends Record implements Di\EntityManagerAware
{
    use Di\EntityManagerSetter;

    public function postActionActivate(Request $request, Response $response): stdClass
    {
        $id = $this->requireEditableId($request);
        $entity = $this->getRunner()->activate($id);

        return (object) $entity->getValueMap();
    }

    public function postActionPause(Request $request, Response $response): stdClass
    {
        $id = $this->requireEditableId($request);
        $entity = $this->getRunner()->pause($id);

        return (object) $entity->getValueMap();
    }

    public function postActionArchive(Request $request, Response $response): stdClass
    {
        $id = $this->requireEditableId($request);
        $entity = $this->getRunner()->archive($id);

        return (object) $entity->getValueMap();
    }

    public function postActionRunNow(Request $request, Response $response): stdClass
    {
        $id = $this->requireEditableId($request);

        $body = $request->getParsedBody();
        $payload = [];
        if (is_object($body) && isset($body->triggerPayload) && (is_object($body->triggerPayload) || is_array($body->triggerPayload))) {
            $payload = (array) $body->triggerPayload;
        }

        $run = $this->getRunner()->runNow($id, 'manual', $payload);

        return (object) $run->getValueMap();
    }

    public function postActionSimulate(Request $request, Response $response): stdClass
    {
        $id = $this->requireEditableId($request);

        $body = $request->getParsedBody();
        $payload = [];
        if (is_object($body) && isset($body->triggerPayload) && (is_object($body->triggerPayload) || is_array($body->triggerPayload))) {
            $payload = json_decode(json_encode($body->triggerPayload) ?: '{}', true) ?: [];
        }

        $maxPreview = 50;
        if (is_object($body) && isset($body->maxPreview)) {
            $maxPreview = (int) $body->maxPreview;
        }

        $result = $this->getRunner()->simulate($id, $payload, $maxPreview);

        return json_decode(json_encode($result) ?: '{}') ?: (object) [];
    }

    private function getRunner(): AutomationRunner
    {
        return $this->injectableFactory->create(AutomationRunner::class);
    }

    private function requireEditableId(Request $request): string
    {
        $id = $request->getRouteParam('id');
        if (!$id) {
            $body = $request->getParsedBody();
            $id = is_object($body) && isset($body->id) ? (string) $body->id : '';
        }

        if (!$id) {
            throw new BadRequest('Missing Automation id.');
        }

        if (!$this->acl->check('Automation', 'edit')) {
            throw new Forbidden();
        }

        $entity = $this->entityManager->getEntityById('Automation', $id);
        if (!$entity) {
            throw new NotFound();
        }

        if (!$this->acl->check($entity, 'edit')) {
            throw new Forbidden();
        }

        return $id;
    }
}
