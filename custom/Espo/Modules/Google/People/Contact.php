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

use Espo\Core\Field\Address;
use stdClass;

class Contact
{
    private $resourceName = null;
    private $givenName = null;
    private $familyName = null;
    private $middleName = null;
    /** @var EmailAddress[]|null */
    private $emailAddressList = null;
    /** @var PhoneNumber[]|null */
    private $phoneNumberList = null;
    private $organization = null;
    private $contactGroupResourceName = null;
    private $etag = null;
    /** @var ?string */
    private $biography = null;
    /** @var ?string */
    private $title = null;
    /** @var ?string */
    private $addressStreet = null;
    /** @var ?string */
    private $addressCity = null;
    /** @var ?string */
    private $addressPostalCode = null;
    /** @var ?string */
    private $addressRegion = null;
    /** @var ?string */
    private $addressCountry = null;

    public function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public function getPayloadData(): stdClass
    {
        $data = (object) [];

        $name = (object) [];

        if ($this->familyName !== null) {
            $name->familyName = $this->familyName;
        }

        if ($this->givenName !== null) {
            $name->givenName = $this->givenName;
        }

        if ($this->middleName !== null) {
            $name->middleName = $this->middleName;
        }

        if (get_object_vars($name)) {
            $data->names = [$name];
        }

        if ($this->resourceName !== null) {
            $data->resourceName = $this->resourceName;
        }

        if ($this->organization !== null) {
            $organizationData = (object) [
                'name' => $this->organization,
            ];

            if ($this->title) {
                $organizationData->title = $this->title;
            }

            $data->organizations = [$organizationData];
        }

        if ($this->emailAddressList) {
            $emailAddressListData = [];

            foreach ($this->emailAddressList as $i => $emailAddress) {
                $emailAddressListData[] = (object) [
                    'value' => $emailAddress->getAddress(),
                    'type' => $emailAddress->getGoogleType(),
                    'metadata' => (object) [
                        'primary' => $i === 0,
                    ],
                ];
            }

            $data->emailAddresses = $emailAddressListData;
        }

        if ($this->phoneNumberList) {
            $phoneNumberListData = [];

            foreach ($this->phoneNumberList as $i => $phoneNumber) {
                $phoneNumberListData[] = (object) [
                    'value' => $phoneNumber->getNumber(),
                    'type' => $phoneNumber->getGoogleType(),
                    'metadata' => (object) [
                        'primary' => $i === 0,
                    ],
                ];
            }

            $data->phoneNumbers = $phoneNumberListData;
        }

        if ($this->contactGroupResourceName) {
            $data->memberships = (object) [
                'contactGroupMembership' => (object) [
                    'contactGroupResourceName' => $this->contactGroupResourceName,
                ],
            ];
        }

        if ($this->etag !== null) {
            $data->etag = $this->etag;
        }

        if ($this->biography) {
            $data->biographies = [
                (object) [
                    'value' => $this->biography,
                    'contentType' => 'TEXT_HTML',
                    'metadata' => (object) ['primary' => true],
                ]
            ];
        }

        if (
            $this->addressCity ||
            $this->addressCountry ||
            $this->addressPostalCode ||
            $this->addressRegion ||
            $this->addressStreet
        ) {
            $addressData = (object) [
                'metadata' => (object) ['primary' => true],
            ];

            if ($this->addressCity) {
                $addressData->city = $this->addressCity;
            }

            if ($this->addressCountry) {
                $addressData->country = $this->addressCountry;
            }

            if ($this->addressPostalCode) {
                $addressData->postalCode = $this->addressPostalCode;
            }

            if ($this->addressRegion) {
                $addressData->region = $this->addressRegion;
            }

            if ($this->addressStreet) {
                $addressData->streetAddress = $this->addressStreet;
            }

            $data->addresses = [$addressData];
        }

        return $data;
    }

    public function withResourceName(?string $resourceName): self
    {
        $obj = clone $this;
        $obj->resourceName = $resourceName;

        return $obj;
    }

    public function withGivenName(?string $givenName): self
    {
        $obj = clone $this;
        $obj->givenName = $givenName;

        return $obj;
    }

    public function withFamilyName(?string $familyName): self
    {
        $obj = clone $this;
        $obj->familyName = $familyName;

        return $obj;
    }

    public function withMiddleName(?string $middleName): self
    {
        $obj = clone $this;
        $obj->middleName = $middleName;

        return $obj;
    }

    public function withOrganization(?string $organization): self
    {
        $obj = clone $this;
        $obj->organization = $organization;

        return $obj;
    }

    public function withTitle(?string $title): self
    {
        $obj = clone $this;
        $obj->title = $title;

        return $obj;
    }

    public function withEmailAddressList(?array $emailAddressList): self
    {
        $obj = clone $this;
        $obj->emailAddressList = $emailAddressList;

        return $obj;
    }

    public function withPhoneNumberList(?array $phoneNumberList): self
    {
        $obj = clone $this;
        $obj->phoneNumberList = $phoneNumberList;

        return $obj;
    }

    public function withAddressStreet(?string $addressStreet): self
    {
        $obj = clone $this;
        $obj->addressStreet = $addressStreet;

        return $obj;
    }

    public function withAddressCity(?string $addressCity): self
    {
        $obj = clone $this;
        $obj->addressCity = $addressCity;

        return $obj;
    }

    public function withAddressPostalCode(?string $addressPostalCode): self
    {
        $obj = clone $this;
        $obj->addressPostalCode = $addressPostalCode;

        return $obj;
    }

    public function withAddressRegion(?string $addressRegion): self
    {
        $obj = clone $this;
        $obj->addressRegion = $addressRegion;

        return $obj;
    }

    public function withAddressCountry(?string $addressCountry): self
    {
        $obj = clone $this;
        $obj->addressCountry = $addressCountry;

        return $obj;
    }

    public function withContactGroupResourceName(?string $contactGroupResourceName): self
    {
        $obj = clone $this;
        $obj->contactGroupResourceName = $contactGroupResourceName;

        return $obj;
    }

    public function withEtag(?string $etag): self
    {
        $obj = clone $this;
        $obj->etag = $etag;

        return $obj;
    }

    public function withBiography(?string $biography): self
    {
        $obj = clone $this;
        $obj->biography = $biography;

        return $obj;
    }

    public function getResourceName(): ?string
    {
        return $this->resourceName;
    }

    public function getGiverName(): ?string
    {
        return $this->givenName;
    }

    public function getFamilyName(): ?string
    {
        return $this->familyName;
    }

    public function getMiddleName(): ?string
    {
        return $this->middleName;
    }

    public function getOrganization(): ?string
    {
        return $this->organization;
    }

    public function geTitle(): ?string
    {
        return $this->title;
    }

    public function getContactGroupResourceName(): ?string
    {
        return $this->contactGroupResourceName;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function getBiography(): ?string
    {
        return $this->biography;
    }

    /**
     * @return EmailAddress[]|null
     */
    public function getEmailAddressList(): ?array
    {
        return $this->emailAddressList;
    }

    /**
     * @return PhoneNumber[]|null
     */
    public function getPhoneNumberList(): ?array
    {
        return $this->phoneNumberList;
    }
}
