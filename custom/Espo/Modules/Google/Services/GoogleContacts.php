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

namespace Espo\Modules\Google\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\BadRequest;

use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item;
use Espo\Modules\Google\Tools\People\Jobs\PushPortion;
use Espo\ORM\EntityManager;
use Espo\ORM\Collection;

use Espo\Core\Utils\Config;

use Espo\Entities\User;
use Espo\Entities\ExternalAccount;

use Espo\Modules\Google\People\ContactGroupFetcherFactory;
use Espo\Modules\Google\People\CollectionPusherFactory;
use Espo\Modules\Google\People\CollectionPusherParams;
use Espo\Modules\Google\People\Util;
use Espo\Modules\Google\People\PushResult;

use DateTime;
use RuntimeException;

class GoogleContacts
{
    /**
     * 200 is max allowed by API
     */
    private const PUSH_PORTION = 100;
    private const PUSH_PORTION_INTERVAL_PERIOD = '1 minute';

    private EntityManager $entityManager;
    private Config $config;
    private User $user;
    private ContactGroupFetcherFactory $contactGroupFetcherFactory;
    private CollectionPusherFactory $collectionPusherFactory;
    private SelectBuilderFactory $selectBuilderFactory;
    private JobSchedulerFactory $jobSchedulerFactory;

    public function __construct(
        EntityManager $entityManager,
        Config $config,
        User $user,
        ContactGroupFetcherFactory $contactGroupFetcherFactory,
        CollectionPusherFactory $collectionPusherFactory,
        SelectBuilderFactory $selectBuilderFactory,
        JobSchedulerFactory $jobSchedulerFactory
    ) {
        $this->entityManager = $entityManager;
        $this->config = $config;
        $this->user = $user;
        $this->contactGroupFetcherFactory = $contactGroupFetcherFactory;
        $this->collectionPusherFactory = $collectionPusherFactory;
        $this->selectBuilderFactory = $selectBuilderFactory;
        $this->jobSchedulerFactory = $jobSchedulerFactory;
    }

    public function usersContactsGroups(): array
    {
        $fetcher = $this->contactGroupFetcherFactory->create($this->user->get('id'));

        $groupList = $fetcher->fetch();

        $map = [];

        foreach ($groupList as $group) {
            $map[$group->getResourceName()] = $group->getName();
        }

        return $map;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     */
    public function push(string $entityType, array $rawSearchParams): int
    {
        $integrationEntity = $this->entityManager->getEntityById('Integration', 'Google');

        if (
            !$integrationEntity ||
            !$integrationEntity->get('enabled')
        ) {
            throw new Forbidden();
        }

        $userId = $this->user->get('id');

        /** @var ?ExternalAccount $externalAccount */
        $externalAccount = $this->entityManager->getEntityById('ExternalAccount', 'Google__' . $userId);

        if (!$externalAccount->get('enabled') || !$externalAccount->get('googleContactsEnabled')) {
            throw new Forbidden("Google Contacts is not enabled for user '$userId'.");
        }

        if (array_key_exists('ids', $rawSearchParams)) {
            $ids = $rawSearchParams['ids'];

            $where = [
                [
                    'type' => 'in',
                    'field' => 'id',
                    'value' => $ids,
                ]
            ];
        }
        else if (array_key_exists('where', $rawSearchParams)) {
            $where = $rawSearchParams['where'];
        }
        else {
            throw new BadRequest();
        }

        try {
            $builder = $this->selectBuilderFactory
                ->create()
                ->from($entityType)
                ->withStrictAccessControl()
                ->withWhere(Item::fromRawAndGroup($where))
                ->buildQueryBuilder();
        }
        catch (Error $e) {
            throw new RuntimeException($e->getMessage());
        }

        $total = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($builder->build())
            ->count();

        if (!$total) {
            return 0;
        }

        $result = null;
        $runNow = true;
        $offset = 0;

        $time = new DateTime('now');

        while ($offset < $total) {
            $builder->limit($offset, $this->getPortionSize());

            $collection = $this->entityManager
                ->getRDBRepository($entityType)
                ->clone($builder->build())
                ->find();

            $offset += $this->getPortionSize();

            if ($runNow) {
                $result = $this->pushPortion($userId, $collection, $externalAccount);

                $runNow = false;

                continue;
            }

            $ids = [];

            foreach ($collection as $entity) {
                $ids[] = $entity->get('id');
            }

            $data = [
                'ids' => $ids,
                'userId' => $userId,
                'entityType' => $entityType,
            ];

            $time->modify('+' . $this->getPortionIntervalPeriod());

            $this->jobSchedulerFactory
                ->create()
                ->setClassName(PushPortion::class)
                ->setData($data)
                ->setTime($time)
                ->schedule();
        }

        if (!$result) {
            return 0;
        }

        return $result->getPushedCount();
    }

    public function pushPortion(
        string $userId,
        Collection $collection,
        ExternalAccount $externalAccount
    ): PushResult {

        $pusher = $this->collectionPusherFactory->create($userId);

        $storedGroupResourceName = ($externalAccount->get('contactsGroupsIds') ?? [])[0] ?? null;

        $groupResourceName = $storedGroupResourceName ?
            Util::normalizeGroupResourceName($storedGroupResourceName) : null;

        $params = CollectionPusherParams
            ::create()
            ->withContactGroupResourceName($groupResourceName);

        return $pusher->push($collection, $params);
    }

    private function getPortionSize(): int
    {
        return $this->config->get('googleContactsPushPortionSize') ?? self::PUSH_PORTION;
    }

    private function getPortionIntervalPeriod(): string
    {
        return $this->config->get('googleContactsPushPortionIntervalPeriod') ?? self::PUSH_PORTION_INTERVAL_PERIOD;
    }
}
