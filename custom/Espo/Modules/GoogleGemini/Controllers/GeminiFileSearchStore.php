<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\GoogleGemini\Controllers;

use Espo\Core\Controllers\Record;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\GoogleGemini\Services\GeminiFileSearchStore as GeminiFileSearchStoreService;
use stdClass;

class GeminiFileSearchStore extends Record
{
    /**
     * Sync all File Search Stores from Gemini API.
     * POST GeminiFileSearchStore/action/syncFromGemini
     *
     * @throws Forbidden
     */
    public function postActionSyncFromGemini(Request $request): stdClass
    {
        if (!$this->acl->check('GeminiFileSearchStore', 'edit')) {
            throw new Forbidden();
        }

        $service = $this->getGeminiFileSearchStoreService();
        $result = $service->syncAllFromGemini();

        return (object) [
            'success' => true,
            'created' => $result['created'],
            'updated' => $result['updated'],
            'errors' => $result['errors'],
        ];
    }

    /**
     * Sync a specific store from Gemini API.
     * POST GeminiFileSearchStore/action/syncStore
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionSyncStore(Request $request): stdClass
    {
        $data = $request->getParsedBody();

        if (empty($data->id)) {
            throw new BadRequest("ID is required.");
        }

        $id = $data->id;

        if (!$this->acl->check('GeminiFileSearchStore', 'edit')) {
            throw new Forbidden();
        }

        $entity = $this->entityManager->getEntityById('GeminiFileSearchStore', $id);

        if (!$entity) {
            throw new NotFound("Store not found.");
        }

        if (!$this->acl->check($entity, 'edit')) {
            throw new Forbidden();
        }

        $service = $this->getGeminiFileSearchStoreService();
        $success = $service->syncStoreFromGemini($entity);

        // Reload entity to get updated values
        $entity = $this->entityManager->getEntityById('GeminiFileSearchStore', $id);

        return (object) [
            'success' => $success,
            'entity' => $entity->getValueMap(),
        ];
    }

    private function getGeminiFileSearchStoreService(): GeminiFileSearchStoreService
    {
        /** @var GeminiFileSearchStoreService */
        return $this->recordServiceContainer->get('GeminiFileSearchStore');
    }
}



