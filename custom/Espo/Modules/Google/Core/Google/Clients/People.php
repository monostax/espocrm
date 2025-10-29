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

namespace Espo\Modules\Google\Core\Google\Clients;

use Exception;

use stdClass;
use RuntimeException;

class People extends Google
{
    protected $baseUrl = 'https://people.googleapis.com/v1/';

    protected function getPingUrl()
    {
        return $this->buildUrl('contactGroups');
    }

    /**
     * @return bool
     */
    public function productPing($url = null)
    {
        try {
            $this->request($this->getPingUrl());

            return true;
        }
        catch (Exception $e) {
            $GLOBALS['log']->debug($e->getMessage());

            return false;
        }
    }

    /**
     * @return stdClass[]
     */
    public function fetchGroupList(int $limit): array
    {
        $url = $this->buildUrl('contactGroups');

        $response = $this->request(
            $url,
            [
                'pageSize' => $limit,
            ],
            'GET'
        );

        if (!is_array($response) || !isset($response['contactGroups'])) {
            throw new RuntimeException("Google, contactGroups: Bad response.");
        }

        $list = [];

        foreach ($response['contactGroups'] as $item) {
            $list[] = (object) $item;
        }

        return $list;
    }

    public function fetchMe(): stdClass
    {
        $url = $this->buildUrl('people/me');

        $response = $this->request(
            $url,
            [
                'personFields' => 'emailAddresses',
            ],
            'GET'
        );

        if (!is_array($response)) {
            throw new RuntimeException("Google, me: Bad response.");
        }

        return json_decode(json_encode($response));
    }
}
