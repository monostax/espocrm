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

namespace Espo\Modules\Google\Tools\People\Jobs;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item;
use Espo\Entities\ExternalAccount;
use Espo\Entities\User;
use Espo\Modules\Google\Services\GoogleContacts;
use Espo\ORM\EntityManager;
use RuntimeException;

class PushPortion implements Job
{
    private EntityManager $entityManager;
    private SelectBuilderFactory $selectBuilderFactory;
    private InjectableFactory $injectableFactory;

    public function __construct(
        EntityManager $entityManager,
        SelectBuilderFactory $selectBuilderFactory,
        InjectableFactory $injectableFactory
    ) {
        $this->entityManager = $entityManager;
        $this->selectBuilderFactory = $selectBuilderFactory;
        $this->injectableFactory = $injectableFactory;
    }

    public function run(Data $data): void
    {
        $data = $data->getRaw();

        $integrationEntity = $this->entityManager->getEntityById('Integration', 'Google');

        if (
            !$integrationEntity ||
            !$integrationEntity->get('enabled')
        ) {
            throw new RuntimeException("Google Contacts: Integration disabled.");
        }

        $userId = $data->userId;
        $entityType = $data->entityType;
        $ids = $data->ids;

        /** @var ?ExternalAccount $externalAccount */
        $externalAccount = $this->entityManager->getEntityById('ExternalAccount', 'Google__' . $userId);

        if (!$externalAccount->get('enabled') || !$externalAccount->get('googleContactsEnabled')) {
            throw new RuntimeException("Google Contacts: Integration disabled for user '$userId'.");
        }

        /** @var ?User $user */
        $user = $this->entityManager->getEntityById('User', $userId);

        if (!$user) {
            throw new RuntimeException("User $userId not found.");
        }

        $where = [
            [
                'type' => 'in',
                'field' => 'id',
                'value' => $ids,
            ]
        ];

        try {
            $query = $this->selectBuilderFactory
                ->create()
                ->from($entityType)
                ->forUser($user)
                ->withStrictAccessControl()
                ->withWhere(Item::fromRawAndGroup($where))
                ->build();
        }
        catch (Error|Forbidden|BadRequest $e) {
            throw new RuntimeException($e->getMessage());
        }

        $collection = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($query)
            ->find();

        $service = $this->injectableFactory->create(GoogleContacts::class);

        $service->pushPortion($userId, $collection, $externalAccount);
    }
}
