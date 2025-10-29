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

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Modules\Google\Services\GoogleGmail as Service;
use Espo\ORM\EntityManager;

class GoogleGmail
{
    private InjectableFactory $injectableFactory;
    private EntityManager $entityManager;
    private Config $config;

    public function __construct(
        InjectableFactory $injectableFactory,
        EntityManager $entityManager,
        Config $config
    ) {
        $this->injectableFactory = $injectableFactory;
        $this->entityManager = $entityManager;
        $this->config = $config;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function postActionConnect(Request $request)
    {
        $data = $request->getParsedBody();

        $entityType = $data->entityType ?? null;
        $id = $data->id ?? null;
        $code = $data->code ?? null;

        if (!$entityType) {
            throw new BadRequest();
        }

        if (!$id) {
            throw new BadRequest();
        }

        if (!$code) {
            throw new BadRequest();
        }

        $service = $this->injectableFactory->create(Service::class);

        $service->processAccessCheck($entityType, $id);

        return $service->connect($entityType, $id, $code);
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     */
    public function postActionDisconnect(Request $request)
    {
        $data = $request->getParsedBody();

        $entityType = $data->entityType ?? null;
        $id = $data->id ?? null;

        if (!$entityType) {
            throw new BadRequest();
        }

        if (!$id) {
            throw new BadRequest();
        }

        $service = $this->injectableFactory->create(Service::class);

        $service->processAccessCheck($entityType, $id);

        return $service->disconnect($entityType, $id);
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionPing(Request $request)
    {
        $data = $request->getParsedBody();

        $entityType = $data->entityType ?? null;
        $id = $data->id ?? null;

        if (!$entityType) {
            throw new BadRequest();
        }

        if (!$id) {
            throw new BadRequest();
        }

        $service = $this->injectableFactory->create(Service::class);

        $service->processAccessCheck($entityType, $id);

        $integration = $this->entityManager->getEntityById('Integration', 'Google');

        if (!$integration) {
            throw new NotFound();
        }

        return [
            'clientId' => $integration->get('clientId'),
            'redirectUri' => $this->config->get('siteUrl') . '?entryPoint=oauthCallback',
            'isConnected' => $service->ping($entityType, $id),
        ];
    }
}
