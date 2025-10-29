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

namespace Espo\Modules\Google\Core\Google\Actions;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\Exceptions\Error;
use Espo\Core\ExternalAccount\ClientManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Google\Core\Google\Clients\Google;
use Espo\ORM\EntityManager;
use RuntimeException;

abstract class Base
{
    //protected $baseUrl = 'https://www.googleapis.com/calendar/v3/';

    protected ?string $userId = null;
    protected ?Google $client = null;
    protected string $configPath = 'data/google/config.json';

    protected Acl $acl;
    protected EntityManager $entityManager;
    protected AclManager $aclManager;
    protected ClientManager $clientManager;
    protected InjectableFactory $injectableFactory;
    protected Config $config;
    protected Metadata $metadata;

    public function __construct(
        EntityManager $entityManager,
        AclManager $aclManager,
        ClientManager $clientManager,
        Config $config,
        InjectableFactory $injectableFactory,
        Metadata $metadata
    ) {
        $this->entityManager = $entityManager;
        $this->aclManager = $aclManager;
        $this->clientManager = $clientManager;
        $this->injectableFactory = $injectableFactory;
        $this->config = $config;
        $this->metadata = $metadata;
    }

    protected function setAcl(): void
    {
        /** @var ?User $user */
        $user = $this->entityManager->getEntityById('User', $this->getUserId());

        if (!$user) {
            throw new RuntimeException("No User with id: " . $this->getUserId());
        }

        $this->acl = $this->aclManager->createUserAcl($user);
    }

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
        $this->client = null;

        $this->setAcl();
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * @return Google
     */
    protected function getClient()
    {
        if (!$this->client) {
            try {
                $this->client = $this->clientManager->create('Google', $this->getUserId());
            }
            catch (Error $e) {
                throw new RuntimeException($e->getMessage());
            }
        }

        return $this->client;
    }
}
