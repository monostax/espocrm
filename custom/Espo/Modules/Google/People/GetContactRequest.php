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

class GetContactRequest implements Request
{
    private $resourceName;

    private $fieldList = [
        'names',
        'organizations',
        'emailAddresses',
        'phoneNumbers',
        'memberships',
        'biographies',
        'addresses',
    ];

    public function __construct(Contact $contact)
    {
        $this->resourceName = $contact->getResourceName();

        if ($this->resourceName === null) {
            throw new RuntimeException("Contact w/o resource name.");
        }
    }

    public static function create(Contact $contact): self
    {
        return new self($contact);
    }

    public function getMethod(): string
    {
        return 'GET';
    }

    public function getUrl(): string
    {
        $queryParamsPart = http_build_query([
            'personFields' => implode(',', $this->fieldList),
        ]);

        return 'https://people.googleapis.com/v1/' . $this->resourceName . '?' . $queryParamsPart;
    }

    public function getHeaders(): string
    {
        return '';
    }

    public function getBody(): string
    {
        return '';
    }
}
