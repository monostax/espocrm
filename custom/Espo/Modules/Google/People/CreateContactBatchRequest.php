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

use RuntimeException;

class CreateContactBatchRequest implements Request
{
    private $contactList;

    /**
     * @param Contact[] $contactList
     */
    public function __construct(array $contactList)
    {
        foreach ($contactList as $contact) {
            if ($contact->getResourceName() !== null) {
                throw new RuntimeException("Can't create contact with resource name.");
            }
        }

        $this->contactList = $contactList;
    }

    /**
     * @param Contact[] $contactList
     */
    public static function create(array $contactList): self
    {
        return new self($contactList);
    }

    public function getMethod(): string
    {
        return 'POST';
    }

    public function getUrl(): string
    {
        return 'https://people.googleapis.com/v1/people:batchCreateContacts';
    }

    public function getHeaders(): string
    {
        return '';
    }

    public function getBody(): string
    {
        $list = [];

        foreach ($this->contactList as $contact) {
            $list[] = (object) [
                'contactPerson' => $contact->getPayloadData()
            ];
        }

        $data = (object) [
            'contacts' => $list,
            'readMask' => 'names',
        ];

        return json_encode($data);
    }
}
