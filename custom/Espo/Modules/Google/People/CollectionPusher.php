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

namespace Espo\Modules\Google\People;

use Espo\Core\Job\JobSchedulerFactory;
use Espo\Modules\Google\Core\Google\Clients\People as Client;
use Espo\Modules\Google\Core\Google\Exceptions\RequestError;
use Espo\Modules\Google\Entities\GoogleContactsPair as Pair;

use Espo\Modules\Google\Tools\People\Jobs\UpdateOneByOne;
use Espo\ORM\Collection;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

use Espo\Core\Utils\Log;
use Espo\Core\Utils\Config;

use RuntimeException;

/**
 * Creates new contacts if pair does not exist in a batch create request.
 * Tries to update contacts if pair exists in a batch update request.
 * If an error occurs during the batch update request, fetches records one-by-one
 * to update their etags (in the pairs) and makes update or create requests for each.
 */
class CollectionPusher
{
    private string $userId;
    private $ownEmailAddress = null;

    private const ONE_BY_ONE_COUNT_THRESHOLD = 4;

    private Client $client;
    private OwnEmailAddressFetcherFactory $ownEmailAddressFetcherFactory;
    private EntityManager $entityManager;
    private Log $log;
    private Config $config;
    private JobSchedulerFactory $jobSchedulerFactory;

    public function __construct(
        string $userId,
        Client $client,
        OwnEmailAddressFetcherFactory $ownEmailAddressFetcherFactory,
        EntityManager $entityManager,
        Log $log,
        Config $config,
        JobSchedulerFactory $jobSchedulerFactory
    ) {
        $this->userId = $userId;
        $this->client = $client;
        $this->ownEmailAddressFetcherFactory = $ownEmailAddressFetcherFactory;
        $this->entityManager = $entityManager;
        $this->log = $log;
        $this->config = $config;
        $this->jobSchedulerFactory = $jobSchedulerFactory;
    }

    public function push(Collection $collection, CollectionPusherParams $params): PushResult
    {
        $createContactList = [];
        $updateContactList = [];
        $updateOneByOneContactList = [];

        $contactEntityMap = [];

        foreach ($collection as $entity) {
            $pair = $this->findPair($entity);

            $contact = $this->createContactFromEntity(
                $entity,
                $pair,
                $params->getContactGroupResourceName()
            );

            $contactEntityMap[spl_object_hash($contact)] = $entity;

            $resourceName = null;
            $etag = null;

            if ($pair) {
                $resourceName = $pair->getResourceName();
                $etag = $pair->getEtag();
            }

            if (!$resourceName) {
                $createContactList[] = $contact;

                continue;
            }

            if (!$etag) {
                $updateOneByOneContactList[] = $contact;
            }

            $updateContactList[] = $contact;
        }

        $createdCount = 0;
        $updatedCount = 0;

        if (count($createContactList)) {
            $createdCount = $this->processCreateBatchRequest($createContactList, $contactEntityMap);
        }

        if (count($updateContactList)) {
            $updatedCount = $this->processUpdateBatchRequest($updateContactList, $contactEntityMap, $params);
        }

        if (count($updateOneByOneContactList)) {
            if (count($updateOneByOneContactList) > $this->getOneByOneCountThreshold()) {
                $this->scheduleUpdateOneByOne($updateOneByOneContactList, $contactEntityMap);
            }
            else {
                $updatedCount +=
                    $this->processUpdateOneByOne($updateOneByOneContactList, $contactEntityMap, $params);
            }
        }

        return new PushResult($createdCount, $updatedCount);
    }

    /**
     * @param Contact[] $contactList
     */
    public function processUpdateOneByOne(
        array $contactList,
        array $contactEntityMap,
        CollectionPusherParams $params
    ): int {

        $count = 0;

        foreach ($contactList as $contact) {
            $resourceName = $contact->getResourceName();

            if (!$resourceName) {
                continue;
            }

            $entity = $contactEntityMap[spl_object_hash($contact)] ?? null;

            if (!$entity) {
                continue;
            }

            $this->processUpdateOne($contact, $entity, $params);

            $count++;
        }

        return $count;
    }

    public function updateOneByOne(Collection $collection, CollectionPusherParams $params): void
    {
        $contactList = [];

        $contactEntityMap = [];

        foreach ($collection as $entity) {
            $pair = $this->findPair($entity);

            $contact = $this->createContactFromEntity(
                $entity,
                $pair,
                $params->getContactGroupResourceName()
            );

            $resourceName = $contact->getResourceName();

            if (!$resourceName) {
                continue;
            }

            $contactEntityMap[spl_object_hash($contact)] = $entity;

            $contactList[] = $contact;
        }

        $this->processUpdateOneByOne($contactList, $contactEntityMap, $params);
    }

    private function createContactFromEntity(
        Entity $entity,
        ?Pair $pair,
        ?string $contactGroupResourceName
    ): Contact {

        $emailAddressList = [];
        $phoneAddressList = [];

        /** @var \Espo\Repositories\EmailAddress $eaRepo */
        $eaRepo = $this->entityManager->getRepository('EmailAddress');
        /** @var \Espo\Repositories\PhoneNumber $pnRepo */
        $pnRepo = $this->entityManager->getRepository('PhoneNumber');

        $emailAddressData = $eaRepo->getEmailAddressData($entity);
        $phoneNumberData = $pnRepo->getPhoneNumberData($entity);

        foreach ($emailAddressData as $item) {
            if ($item->optOut || $item->invalid) {
                continue;
            }

            $emailAddressList[] = EmailAddress::create($item->emailAddress);
        }

        foreach ($phoneNumberData as $item) {
            if ($item->optOut || $item->invalid) {
                continue;
            }

            $phoneAddressList[] = PhoneNumber::create($item->phoneNumber, $item->type);
        }

        $resourceName = null;
        $etag = null;

        if ($pair) {
            $resourceName = $pair->getResourceName();
            $etag = $pair->getEtag();
        }

        return Contact
            ::create()
            ->withResourceName($resourceName)
            ->withEtag($etag)
            ->withContactGroupResourceName($contactGroupResourceName)
            ->withGivenName($entity->get('firstName'))
            ->withMiddleName($entity->get('middleName'))
            ->withFamilyName($entity->get('lastName'))
            ->withOrganization($entity->get('accountName'))
            ->withTitle($entity->get('title'))
            ->withEmailAddressList($emailAddressList)
            ->withPhoneNumberList($phoneAddressList)
            ->withBiography($entity->get('description'))
            ->withAddressCity($entity->get('addressCity'))
            ->withAddressCountry($entity->get('addressCountry'))
            ->withAddressPostalCode($entity->get('addressPostalCode'))
            ->withAddressRegion($entity->get('addressState'))
            ->withAddressStreet($entity->get('addressStreet'));
    }

    /**
     * @param Contact[] $contactList
     */
    private function processCreateBatchRequest(
        array $contactList,
        array $contactEntityMap
    ): int {

        $request = new CreateContactBatchRequest($contactList);

        $responseArray = $this->client->request(
            $request->getUrl(),
            $request->getBody(),
            $request->getMethod(),
            'application/json'
        );

        $response = json_decode(json_encode($responseArray));

        if (!property_exists($response, 'createdPeople')) {
            throw new RuntimeException("Bad response on batch create.");
        }

        $list = $response->createdPeople;

        if (count($list) !== count($contactList)) {
            throw new RuntimeException("Response count does not match request count.");
        }

        $count = 0;

        foreach ($list as $i => $item) {
            $contact = $contactList[$i];

            $resourceName = $item->person->resourceName;
            $etag = $item->person->etag;

            if (!$resourceName) {
                $this->log->warning("Google batch create contact: No resource name in item response.");

                continue;
            }

            if (!$etag) {
                $this->log->warning("Google batch create contact: No etag in item response.");

                continue;
            }

            $entity = $contactEntityMap[spl_object_hash($contact)] ?? null;

            if (!$entity) {
                throw new RuntimeException("No contact entity in map.");
            }

            $this->storePair($entity, $resourceName, $etag);

            $count++;
        }

        return $count;
    }

    /**
     * @param Contact[] $contactList
     */
    private function processUpdateBatchRequest(
        array $contactList,
        array $contactEntityMap,
        CollectionPusherParams $params
    ): int {

        $request = new UpdateContactBatchRequest($contactList);

        try {
            $responseArray = $this->client->request(
                $request->getUrl(),
                $request->getBody(),
                $request->getMethod(),
                'application/json'
            );
        }
        catch (RequestError $e) {
            $errorData = $e->getErrorData();

            $status = $errorData->status ?? null;

            $isConflictError = $status === 'NOT_FOUND' || $status === 'FAILED_PRECONDITION';

            if ($isConflictError && $params->noScheduleOnError()) {
                return 0;
            }

            if (!$isConflictError) {
                throw $e;
            }

            if (count($contactList) > $this->getOneByOneCountThreshold()) {
                $this->scheduleUpdateOneByOne($contactList, $contactEntityMap);
            }
            else {
                return $this->processUpdateOneByOne($contactList, $contactEntityMap, $params);
            }

            return 0;
        }

        $response = json_decode(json_encode($responseArray));

        if (!property_exists($response, 'updateResult')) {
            throw new RuntimeException("Bad response on batch update.");
        }

        $items = $response->updateResult;

        $count = 0;

        foreach ($contactList as $contact) {
            $resourceName = $contact->getResourceName();

            if (!property_exists($items, $resourceName)) {
                $this->log->debug("Google batch update contact: No item '$resourceName' in response.");

                continue;
            }

            $entity = $contactEntityMap[spl_object_hash($contact)] ?? null;

            if (!$entity) {
                throw new RuntimeException("No contact entity in map.");
            }

            $item = $items->$resourceName;

            $person = $item->person;

            $etag = $person->etag ?? null;

            if ($etag) {
                $this->storeEtag($entity, $resourceName, $etag);
            }

            $statusCode = $item->status->code ?? 200;

            if ($statusCode !== 200) {
                $this->log->warning("Google batch update contact item '$resourceName', status '$statusCode'.");

                continue;
            }

            $count++;
        }

        return $count;
    }

    private function processUpdateOne(Contact $contact, Entity $entity, CollectionPusherParams $params): void
    {
        $request = GetContactRequest::create($contact);

        try {
            $responseArray = $this->client->request(
                $request->getUrl(),
                $request->getBody(),
                $request->getMethod(),
                'application/json'
            );
        }
        catch (RequestError $e) {
            $errorData = $e->getErrorData();

            $status = $errorData->status ?? null;

            if ($status === 'NOT_FOUND') {
                $this->removePair($entity);
            }
            else {
                $this->log->error($e->getMessage());

                return;
            }
        }

        $response = isset($responseArray) ? json_decode(json_encode($responseArray)) : null;

        if ($response) {
            $etag = $response->etag ?? null;

            if (!$etag) {
                return;
            }

            $this->storeEtag($entity, $contact->getResourceName(), $etag);
        }

        $collection = $this->entityManager->getCollectionFactory()->create();

        $collection[] = $entity;

        $this->push($collection, $params->withNoScheduleOnError());
    }

    /**
     * @param Contact[] $contactList
     */
    private function scheduleUpdateOneByOne(array $contactList, array $contactEntityMap): void
    {
        $ids = [];

        $entityType = null;

        foreach ($contactList as $contact) {
            $entity = $contactEntityMap[spl_object_hash($contact)] ?? null;

            if (!$entity) {
                continue;
            }

            $ids[] = $entity->get('id');

            $entityType = $entity->getEntityType();
        }

        if (!$entityType) {
            return;
        }

        $data = [
            'ids' => $ids,
            'entityType' => $entityType,
            'userId' => $this->userId,
        ];

        $this->jobSchedulerFactory
            ->create()
            ->setClassName(UpdateOneByOne::class)
            ->setData($data)
            ->schedule();
    }

    private function findPair(Entity $entity): ?Pair
    {
        /** @var Pair $pair */
        $pair = $this->entityManager
            ->getRDBRepository('GoogleContactsPair')
            ->where([
                'parentId' => $entity->get('id'),
                'parentType' => $entity->getEntityType(),
                'googleAccountEmail' => $this->getOwnEmailAddress(),
            ])
            ->findOne();

        if (!$pair) {
            return null;
        }

        $resourceName = $pair->getResourceName();
        $etag = $pair->getEtag();

        if ($resourceName && $etag) {
            return $pair;
        }

        $id = $pair->get('googleContactId');

        if ($id) {
            $resourceName = Util::convertContactIdToResourceName($id);

            $pair->set('resourceName', $resourceName);

            $this->entityManager->saveEntity($pair);
        }

        if ($resourceName && $etag) {
            return $pair;
        }

        if (!$resourceName) {
            $this->entityManager->removeEntity($pair);

            return null;
        }

        return $pair;
    }

    private function storeEtag(Entity $entity, string $resourceName, string $etag): void
    {
        $pair = $this->entityManager
            ->getRDBRepository('GoogleContactsPair')
            ->where([
                'parentId' => $entity->get('id'),
                'parentType' => $entity->getEntityType(),
                'googleAccountEmail' => $this->getOwnEmailAddress(),
                'resourceName' => $resourceName,
            ])
            ->findOne();

        if (!$pair) {
            return;
        }

        $pair->set('etag', $etag);

        $this->entityManager->saveEntity($pair);
    }

    private function storePair(Entity $entity, string $resourceName, string $etag): void
    {
        $this->entityManager->createEntity('GoogleContactsPair', [
            'parentId' => $entity->get('id'),
            'parentType' => $entity->getEntityType(),
            'googleAccountEmail' => $this->getOwnEmailAddress(),
            'resourceName' => $resourceName,
            'etag' => $etag,
        ]);
    }


    private function removePair(Entity $entity): void
    {
        $pairs = $this->entityManager
            ->getRDBRepository('GoogleContactsPair')
            ->where([
                'parentId' => $entity->get('id'),
                'parentType' => $entity->getEntityType(),
                'googleAccountEmail' => $this->getOwnEmailAddress(),
            ])
            ->find();

        foreach ($pairs as $pair) {
            $this->entityManager->removeEntity($pair);
        }
    }

    private function getOwnEmailAddress(): string
    {
        if ($this->ownEmailAddress === null) {
            $this->loadOwnEmailAddress();
        }

        return $this->ownEmailAddress;
    }

    private function loadOwnEmailAddress(): void
    {
        $externalAccount = $this->entityManager->getEntityById('ExternalAccount', 'Google__' . $this->userId);

        if (!$externalAccount) {
            throw new RuntimeException("Could not get external account for '$this->userId'.");
        }

        $accountEmailAddress = $externalAccount->get('accountEmailAddress');

        if ($accountEmailAddress) {
            $this->ownEmailAddress = $accountEmailAddress;

            return;
        }

        $this->ownEmailAddress = $this->ownEmailAddressFetcherFactory
            ->create($this->userId)
            ->fetch();

        $externalAccount->set('accountEmailAddress', $this->ownEmailAddress);

        $this->entityManager->saveEntity($externalAccount, ['skipHooks' => true]);
    }

    private function getOneByOneCountThreshold(): int
    {
        return $this->config->get('googleContactsPushOneByOneCountThreshold') ?? self::ONE_BY_ONE_COUNT_THRESHOLD;
    }
}
