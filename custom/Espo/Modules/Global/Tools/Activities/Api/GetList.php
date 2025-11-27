<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2025 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Modules\Global\Tools\Activities\Api;

use Espo\Core\Acl;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Modules\Global\Tools\Activities\List\Params;
use Espo\Modules\Global\Tools\Activities\ListService;

/**
 * Get all activities list.
 *
 * @noinspection PhpUnused
 */
class GetList implements Action
{
    public function __construct(
        private SearchParamsFetcher $searchParamsFetcher,
        private ListService $service,
        private Acl $acl
    ) {}

    public function process(Request $request): Response
    {
        if (!$this->acl->check('Activities')) {
            throw new Forbidden();
        }

        $params = $this->fetchParams($request);

        $result = $this->service->get($params);

        return ResponseComposer::json($result->toApiOutput());
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     */
    private function fetchParams(Request $request): Params
    {
        $entityTypeList = $this->fetchEntityTypeList($request);
        $searchParams = $this->searchParamsFetcher->fetch($request);

        $orderBy = $searchParams->getOrderBy();
        $order = $searchParams->getOrder();

        return new Params(
            offset: $searchParams->getOffset(),
            maxSize: $searchParams->getMaxSize(),
            entityTypeList: $entityTypeList,
            orderBy: $orderBy,
            order: $order,
        );
    }

    /**
     * @return ?string[]
     * @throws BadRequest
     */
    private function fetchEntityTypeList(Request $request): ?array
    {
        $entityTypeList = $request->getQueryParams()['entityTypeList'] ?? null;

        if (!is_array($entityTypeList) && $entityTypeList !== null) {
            throw new BadRequest("Bad entityTypeList.");
        }

        foreach ($entityTypeList ?? [] as $it) {
            if (!is_string($it)) {
                throw new BadRequest("Bad item in entityTypeList.");
            }
        }

        return $entityTypeList;
    }
}

