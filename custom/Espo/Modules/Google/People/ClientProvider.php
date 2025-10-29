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

use Espo\Core\ExternalAccount\ClientManager;
use Espo\Modules\Google\Core\Google\Clients\Google as Client;

use RuntimeException;

class ClientProvider
{
    private array $hashMap = [];

    private ClientManager $clientManager;

    public function __construct(ClientManager $externalAccountClientManager)
    {
        $this->clientManager = $externalAccountClientManager;
    }

    public function get(string $userId): Client
    {
        if (array_key_exists($userId, $this->hashMap)) {
            return $this->hashMap[$userId];
        }

        $client = $this->clientManager->create('Google', $userId);

        if (!$client) {
            throw new RuntimeException("Google client could not be created for user '$userId.'");
        }

        $this->hashMap[$userId] = $client;

        return $client;
    }
}
