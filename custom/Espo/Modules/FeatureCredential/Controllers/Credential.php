<?php

namespace Espo\Modules\FeatureCredential\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Templates\Controllers\Base;
use Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckManager;
use stdClass;

/**
 * Controller for Credential entity.
 * Provides standard CRUD operations and custom actions for credential management.
 */
class Credential extends Base
{
    /**
     * POST Credential/action/healthCheck
     *
     * Run a health check for a credential.
     *
     * @param Request $request Body: { "id": "credentialId" }
     * @return stdClass { status, message, responseTimeMs, checkedAt }
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionHealthCheck(Request $request, Response $response): stdClass
    {
        $data = $request->getParsedBody();
        $id = $data->id ?? null;

        if (!$id) {
            throw new BadRequest('Missing required parameter: id');
        }

        // Check ACL.
        $entity = $this->entityManager->getEntityById('Credential', $id);

        if (!$entity) {
            throw new NotFound('Credential not found.');
        }

        if (!$this->acl->check($entity, 'read')) {
            throw new Forbidden('Access denied.');
        }

        /** @var HealthCheckManager $manager */
        $manager = $this->injectableFactory->create(HealthCheckManager::class);

        $result = $manager->checkById($id);

        return $result->toStdClass();
    }
}
