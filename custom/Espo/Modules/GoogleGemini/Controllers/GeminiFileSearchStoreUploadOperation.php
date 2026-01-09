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
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Forbidden;
use stdClass;

/**
 * Controller for GeminiFileSearchStoreUploadOperation entity.
 * 
 * This entity is read-only - operations are created by the IndexArticle job
 * and processed by the ProcessUploadOperations scheduled job.
 */
class GeminiFileSearchStoreUploadOperation extends Record
{
    /**
     * Disable manual creation of operations.
     * Operations are created automatically by the IndexArticle job.
     *
     * @throws Forbidden
     */
    public function postActionCreate(Request $request, Response $response): stdClass
    {
        throw new Forbidden("Upload operations are created automatically by the system.");
    }

    /**
     * Disable manual updates of operations.
     * Operations are updated by the ProcessUploadOperations job.
     *
     * @throws Forbidden
     */
    public function putActionUpdate(Request $request, Response $response): stdClass
    {
        throw new Forbidden("Upload operations cannot be modified manually.");
    }

    /**
     * Disable manual deletion of operations.
     *
     * @throws Forbidden
     */
    public function deleteActionDelete(Request $request, Response $response): bool
    {
        throw new Forbidden("Upload operations cannot be deleted manually.");
    }
}



