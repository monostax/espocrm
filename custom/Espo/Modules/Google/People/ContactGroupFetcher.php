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

use Espo\Modules\Google\Core\Google\Clients\People as Client;

use RuntimeException;

class ContactGroupFetcher
{
    private $client;

    private const LIMIT = 1000;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return ContactGroup[]
     */
    public function fetch(): array
    {
        $list = [];

        $dataList = $this->client->fetchGroupList(self::LIMIT);

        foreach ($dataList as $item) {
            if (!isset($item->resourceName)) {
                throw new RuntimeException("No resource name returned for a group.");
            }

            $type = $item->groupType ?? null;
            $resourceName = $item->resourceName;

            if (
                $type === 'SYSTEM_CONTACT_GROUP' &&
                $resourceName != 'contactGroups/myContacts'
            ) {
                continue;
            }

            $list[] = ContactGroup::create(
                $resourceName,
                $item->formattedName ?? $item->name ?? 'no-name'
            );
        }

        return $list;
    }
}
