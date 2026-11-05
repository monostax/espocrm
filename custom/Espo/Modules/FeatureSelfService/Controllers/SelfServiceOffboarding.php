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

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureSelfService\Tools\Offboarding\Offboarder;
use stdClass;

/**
 * Workspace offboardings, for instance administrators (the scope has no ACL,
 * so nobody else can read them). Records are written only by Offboarder.
 *
 *   POST /SelfServiceOffboarding/action/start {tenantId, slug}
 *        Starts (or retries) the tenant's offboarding; the job runs it.
 *        Errors: SLUG_MISMATCH (400), OPEN_SUBSCRIPTION (409).
 */
class SelfServiceOffboarding extends Record
{
    public function postActionStart(Request $request): stdClass
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }

        $body = $request->getParsedBody();
        $tenantId = $body->tenantId ?? null;

        if (!is_string($tenantId) || $tenantId === '') {
            throw new BadRequest('No tenantId.');
        }

        $record = $this->injectableFactory
            ->create(Offboarder::class)
            ->start($tenantId, (string) ($body->slug ?? ''), (string) $this->user->getId());

        return (object) ['id' => $record->getId(), 'status' => $record->get('status'), 'step' => $record->get('step')];
    }

    public function postActionCreate(Request $request, Response $response): stdClass
    {
        throw new Forbidden();
    }

    public function putActionUpdate(Request $request, Response $response): stdClass
    {
        throw new Forbidden();
    }

    public function deleteActionDelete(Request $request, Response $response): bool
    {
        throw new Forbidden();
    }
}
