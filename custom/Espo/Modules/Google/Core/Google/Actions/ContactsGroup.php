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
use Espo\Modules\Google\Core\Google\Items\ContactsEntry;
use Espo\Modules\Google\Core\Google\Items\ContactsGroupFeed;
use Exception;

class ContactsGroup extends Base
{
    /**
     * @return ContactsClient
     */
    protected function getClient()
    {
        return parent::getClient()->getContactsClient();
    }

    protected function asContactsGroupFeed($string): ContactsGroupFeed
    {
        return new ContactsGroupFeed($string);
    }

    protected function asContactsGroupEntry($string): ContactsEntry
    {
        return new ContactsEntry($string);
    }

    public function getGroupList($params = [])
    {
        static $lists = [];

        $client = $this->getClient();
        $response = $client->getGroupList($params);

        if (!empty($response)) {
            try {
                $feed = $this->asContactsGroupFeed($response);
                $entries = $feed->getEntries();

                foreach ($entries as $entry) {
                    $parsedEntry = $this->asContactsGroupEntry($entry);
                    $lists[$parsedEntry->getId()] = $parsedEntry->getTitle();
                }

                $nextPageLink = $feed->getNextLink();

                if (!empty($nextPageLink)) {
                    $queryString = parse_url($nextPageLink, PHP_URL_QUERY);

                    parse_str($queryString, $queryParams);

                    $this->getGroupList($queryParams);
                }
            }
            catch (Exception $e) {
                $GLOBALS['log']->error('Google Contacts. Getting List Error: '. $e->getMessage());
            }
        }

        return $lists;
    }
}
