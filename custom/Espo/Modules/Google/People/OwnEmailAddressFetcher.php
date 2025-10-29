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

class OwnEmailAddressFetcher
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function fetch(): string
    {
        $data = $this->client->fetchMe();

        if (!property_exists($data, 'emailAddresses')) {
            throw new RuntimeException("No email addresses in 'me' response.");
        }

        foreach ($data->emailAddresses as $item) {
            $value = $item->value ?? null;

            $metadata = $item->metadata ?? null;

            if (!$metadata) {
                continue;
            }

            $primary = $metadata->primary ?? false;
            $source = $metadata->source ?? null;

            if (!$source) {
                continue;
            }

            $type = $source->type ?? null;


            if (
                strtoupper($type) === 'ACCOUNT' ||
                strtoupper($type) === 'DOMAIN_PROFILE' && $primary
            ) {
                return $value;
            }
        }

        throw new RuntimeException("No account email address in 'me' response.");
    }
}
