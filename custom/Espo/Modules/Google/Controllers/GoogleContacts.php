<?php
/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2025 EspoCRM, Inc.
 *
 * License ID: 99e925c7f52e4853679eb7c383162336
 ************************************************************************************/

namespace Espo\Modules\Google\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Modules\Google\Services\GoogleContacts as Service;

class GoogleContacts
{
    private InjectableFactory $injectableFactory;
    private Acl $acl;

    public function __construct(
        InjectableFactory $injectableFactory,
        Acl $acl
    ) {
        $this->injectableFactory = $injectableFactory;
        $this->acl = $acl;
    }

    public function getActionUsersContactsGroups()
    {
        return $this->injectableFactory
            ->create(Service::class)
            ->usersContactsGroups();
    }

    /**
     * @throws Forbidden
     * @throws BadRequest
     */
    public function postActionPush(Request $request): array
    {
        if (!$this->acl->checkScope('GoogleContacts')) {
            throw new Forbidden();
        }

        $data = $request->getParsedBody();

        $entityType = $data->entityType;

        $params = [];

        if (isset($data->byWhere) && $data->byWhere) {
            $params['where'] = [];

            foreach ($data->where as $cause) {
                $params['where'][] = (array) $cause;
            }
        }
        else {
            $params['ids'] = $data->idList;
        }

        return [
            'count' => $this->injectableFactory
                ->create(Service::class)
                ->push($entityType, $params)
        ];
    }
}
