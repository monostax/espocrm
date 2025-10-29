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

use Espo\Modules\Google\Core\Google\Clients\Contacts as ContactsClient;
use Espo\Modules\Google\Core\Google\Items\ContactsBatchEntry;
use Espo\Modules\Google\Core\Google\Items\ContactsBatchFeed;
use Espo\Modules\Google\Core\Google\Items\ContactsFeed;
use Espo\ORM\Entity;
use Espo\Repositories\EmailAddress;
use Espo\Repositories\PhoneNumber;
use Exception;
use stdClass;

class Contacts extends Base
{
    protected $helper;

    protected $contactFieldPairs = [
        'name' => 'name',
        'description' => 'content',
        'emailAddress' => 'email',
        'phoneNumber' => 'phoneNumber',
        'accountName' => 'organization',
    ];

    /**
     * @return ContactsClient
     */
    protected function getClient()
    {
        return parent::getClient()->getContactsClient();
    }

    protected function asContactFeed($string): ContactsFeed
    {
        return new ContactsFeed($string);
    }

    protected function asContactsBatchEntry($string = null): ContactsBatchEntry
    {
        return new ContactsBatchEntry($string);
    }

    protected function asContactsBatchFeed($string = null): ContactsBatchFeed
    {
        return new ContactsBatchFeed($string);
    }

    public function getUserEmail()
    {
        $client = $this->getClient();
        $response = $client->getUserData();

        try {
            $feed = $this->asContactFeed($response);

            return $feed->getId();
        }
        catch (Exception $e) {
            $GLOBALS['log']->error('Getting Google User Email: '. $e->getMessage());
        }

        return false;
    }

    public function pushEspoContactsToGoogleContacts($collection, $groupIds = [])
    {
        $client = $this->getClient();

        $successfulCnt = 0;

        if (!count($collection)) {
            return false;
        }

        $client->ping();

        $feed = $this->asContactsBatchFeed();

        $this->helper = new stdClass();

        $this->helper->groupIds = (is_array($groupIds)) ? $groupIds : [];
        $this->helper->collectionEntityType = $collection->getEntityType();

        $this->helper->forbiddenFields = $this->acl->getScopeForbiddenFieldList($this->helper->collectionEntityType);
        $this->helper->userEmail = $this->getUserEmail();

        $storedContactPairs = $this->loadStoredContactsPairs($this->helper->collectionEntityType);

        foreach ($collection as $entity) {
            if (isset($storedContactPairs[$entity->get('id')])) {
                $this->batchQuery($feed, $entity->get('id'), $storedContactPairs[$entity->get('id')]);
            } else {
                $this->batchInsert($feed, $entity);
            }
        }

        $response = $client->batch($feed->asXML());
        $resultFeed = $this->asContactsBatchFeed($response);
        $responseEntries = $resultFeed->getEntries();

        $feed = $this->asContactsBatchFeed();

        foreach ($responseEntries as $entry) {
            $batchEntry = $this->asContactsBatchEntry($entry);

            if ($batchEntry->getOperationType() == 'query') {
                $entity = $this->entityManager
                    ->getEntityById($this->helper->collectionEntityType, $batchEntry->getBatchId());

                if ($batchEntry->getStatusCode() == 200) {

                    $status = $entry->getElementsByTagName('status')->item(0);

                    if ($status) {
                        $entry->removeChild($status);
                    }

                    $operation = $entry->getElementsByTagName('operation')->item(0);

                    if ($operation) {
                        $entry->removeChild($operation);
                    }

                    $updated = $entry->getElementsByTagName('updated')->item(0);
                    if ($updated) {
                        $entry->removeChild($updated);
                    }

                    $this->batchUpdate($feed, $entry, $entity);

                }

                if ($batchEntry->getStatusCode() == 404) {

                    $storedPairEntity = $this->entityManager
                        ->getRDBRepository('GoogleContactsPair')
                        ->where([
                            'googleAccountEmail' => $this->helper->userEmail,
                            'parentType' => $this->helper->collectionEntityType,
                            'parentId' => $batchEntry->getBatchId(),
                            'googleContactId' => $batchEntry->getShortId()
                        ])
                        ->findOne();

                    if ($storedPairEntity) {
                        $this->entityManager->removeEntity($storedPairEntity);
                    }

                    $this->batchInsert($feed, $entity);
                }
            }

            if (
                $batchEntry->getOperationType() == 'insert' &&
                $batchEntry->getStatusCode() >= 200 &&
                $batchEntry->getStatusCode() < 300
            ) {
                $storedPairEntity = $this->entityManager->getNewEntity('GoogleContactsPair');

                $storedPairEntity->set('googleAccountEmail', $this->helper->userEmail);
                $storedPairEntity->set('parentType', $this->helper->collectionEntityType);
                $storedPairEntity->set('parentId', $batchEntry->getBatchId());
                $storedPairEntity->set('googleContactId', $batchEntry->getShortId());

                $this->entityManager->saveEntity($storedPairEntity);

                $successfulCnt++;
            }
        }

        if ($feed->getEntries()->length) {
            $response = $client->batch($feed->asXML());
            $resultFeed = $this->asContactsBatchFeed($response);
            $responseEntries = $resultFeed->getEntries();

            foreach ($responseEntries as $entry) {
                $batchEntry = $this->asContactsBatchEntry($entry);

                if ($batchEntry->getStatusCode() >= 200 && $batchEntry->getStatusCode() < 300) {
                    $successfulCnt++;

                    if ($batchEntry->getOperationType() === 'insert') {
                        $storedPairEntity = $this->entityManager->getNewEntity('GoogleContactsPair');

                        $storedPairEntity->set('googleAccountEmail', $this->helper->userEmail);
                        $storedPairEntity->set('parentType', $this->helper->collectionEntityType);
                        $storedPairEntity->set('parentId', $batchEntry->getBatchId());
                        $storedPairEntity->set('googleContactId', $batchEntry->getShortId());

                        $this->entityManager->saveEntity($storedPairEntity);
                    }
                }
            }
        }

        return $successfulCnt;
    }

    protected function batchInsert($feed, $entity)
    {
        $feed->addEntry();
        $feed->addOperation('insert');
        $feed->addField('batchId', $entity->get('id'));

        foreach ($this->contactFieldPairs as $field => $googleField) {
            if (in_array($field, $this->helper->forbiddenFields)) {
                continue;
            }

            if ($entity->hasField($field) && $entity->get($field)) {
                $attributes = [];

                if ($field == 'phoneNumber') {
                    /** @var PhoneNumber $repo */
                    $repo = $this->entityManager->getRepository('PhoneNumber');

                    $fieldData = $repo->getPhoneNumberData($entity);

                    if ($fieldData) {
                        foreach ($fieldData as $item) {
                            $attributes = $this->preparePhoneNumber($item);

                            $feed->addField($googleField, $item->$field, $attributes);
                        }
                    }
                }
                else if ($field == 'emailAddress') {
                    /** @var EmailAddress $repo */
                    $repo = $this->entityManager->getRepository('EmailAddress');

                    $fieldData = $repo->getEmailAddressData($entity);

                    if ($fieldData) {
                        foreach ($fieldData as $item) {
                            if ($item->optOut || $item->invalid) {
                                continue;
                            }

                            $attributes = [];
                            $attributes['primary'] = $item->primary;

                            $feed->addField($googleField, $item->$field, $attributes);
                        }
                    }
                }
                else if ($field == 'name') {
                    if ($entity->hasField('firstName')) {
                        $attributes['givenName'] = $entity->get('firstName');
                    }

                    if ($entity->hasField('lastName')) {
                        $attributes['familyName'] = $entity->get('lastName');
                    }

                    if ($entity->hasField('middleName')) {
                        $attributes['middleName'] = $entity->get('middleName');
                    }

                    $feed->addField($googleField, $entity->get($field), $attributes);
                    $feed->addField('title', $entity->get($field), []);
                }
                else {
                    $feed->addField($googleField, $entity->get($field), $attributes);
                }
            }
        }

        if (!empty($this->helper->groupIds) && is_array($this->helper->groupIds)) {
            foreach ($this->helper->groupIds as $groupId) {
                $feed->addField('group', $groupId);
            }
        }
    }

    protected function batchQuery($feed, $entityId, $entryId)
    {
        $feed->addEntry();
        $feed->addOperation('query');
        $feed->addField('batchId', $entityId);
        $feed->addField('id', $entryId);
    }

    protected function batchUpdate($feed, $entry, Entity $entity)
    {
        $feed->addEntry($entry);
        $feed->addOperation('update');

        foreach ($this->contactFieldPairs as $field => $googleField) {
            if (in_array($field, $this->helper->forbiddenFields)) {
                continue;
            }

            if ($entity->hasAttribute($field) && $entity->get($field)) {
                $attributes = [];

                if ($field == 'phoneNumber') {
                    /** @var PhoneNumber $repo */
                    $repo = $this->entityManager->getRepository('PhoneNumber');

                    $fieldData = $repo->getPhoneNumberData($entity);

                    if ($fieldData) {
                        foreach ($fieldData as $item) {
                            $attributes = $this->preparePhoneNumber($item);
                            $feed->updateField($googleField, $item->$field, $attributes);
                        }
                    }
                }
                else if ($field == 'emailAddress') {
                    /** @var EmailAddress $repo */
                    $repo = $this->entityManager->getRepository('EmailAddress');

                    $fieldData = $repo->getEmailAddressData($entity);

                    if ($fieldData) {
                        foreach ($fieldData as $item) {
                            if ($item->optOut || $item->invalid) {
                                continue;
                            }

                            $attributes = [];

                            $attributes['primary'] = $item->primary;

                            $feed->updateField($googleField, $item->$field, $attributes);
                        }
                    }
                }
                else if ($field == "name") {
                    if ($entity->hasAttribute('firstName')) {
                        $attributes['givenName'] = $entity->get('firstName');
                    }

                    if ($entity->hasAttribute('lastName')) {
                        $attributes['familyName'] = $entity->get('lastName');
                    }

                    if ($entity->hasAttribute('middleName')) {
                        $attributes['middleName'] = $entity->get('middleName');
                    }

                    $feed->updateField($googleField, $entity->get($field), $attributes);
                    $feed->updateField('title', $entity->get($field), []);
                }
                else {
                    $feed->updateField($googleField, $entity->get($field), $attributes);
                }
            }
        }

        if (!empty($this->helper->groupIds) && is_array($this->helper->groupIds)) {
            foreach ($this->helper->groupIds as $groupId) {
                $feed->updateField('group', $groupId);
            }
        }
    }

    protected function preparePhoneNumber($phoneNumber)
    {
        $attributes = [];

        $phoneTypeEq = [
            'Mobile' => 'mobile',
            'Office' => 'work',
            'Home' => 'home',
            'Fax' => 'fax',
            'Other' => 'other',
        ];

        $defaultType = 'other';
        $attributes['primary'] = $phoneNumber->primary;

        $attributes['type'] = (isset($phoneTypeEq[$phoneNumber->type])) ?
            $phoneTypeEq[$phoneNumber->type] :
            $defaultType;

        return $attributes;
    }

    protected function loadStoredContactsPairs($entityType)
    {
        $result = [];

        $pairs = $this->entityManager
            ->getRDBRepository('GoogleContactsPair')
            ->where([
                'googleAccountEmail' => $this->helper->userEmail,
                'parentType' => $entityType,
            ])
            ->find();

        foreach ($pairs as $pair) {
            $result[$pair->get('parentId')] = $pair->get("googleContactId");
        }

        return $result;
    }
}
