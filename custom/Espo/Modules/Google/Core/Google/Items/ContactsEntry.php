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

namespace Espo\Modules\Google\Core\Google\Items;

use DOMDocument;

class ContactsEntry extends XMLEntryBase
{
    const LINK_REL_EDIT = "edit";
    const LINK_REL_PHOTO = "http://schemas.google.com/contacts/2008/rel#photo";

    const PHONE_TYPE_REL_HOME = "http://schemas.google.com/g/2005#home";
    const PHONE_TYPE_REL_WORK = "http://schemas.google.com/g/2005#work";

    const NAMESPACE_GD = 'http://schemas.google.com/g/2005';
    const NAMESPACE_G_CONTACT = 'http://schemas.google.com/contact/2008';
    const NAMESPACE_BATCH = 'http://schemas.google.com/gdata/batch';

    public function createNewEntry()
    {
        $doc  = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;
        $entry = $doc->createElementNS('http://www.w3.org/2005/Atom', 'entry');
        $doc->appendChild($entry);
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:batch', self::NAMESPACE_BATCH);
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:gContact', self::NAMESPACE_G_CONTACT);
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:gd', self::NAMESPACE_GD);
        return $doc;
    }

    public function addField($fieldName, $value, $attributes = array())
    {
        $entry = $this->item->getElementsByTagName('entry')->item(0);
        switch ($fieldName) {
            case 'title':
            case 'content':
                $fieldItem = $this->item->createElement($fieldName, $value);
                $fieldItem->setAttribute('type', 'text');
                $entry->appendChild($fieldItem);
                break;
            case 'name':
                $name = $this->item->createElement('gd:name');
                $entry->appendChild($name);
                $fullName = $this->item->createElement('gd:fullName', $value);
                $name->appendChild($fullName);
                break;
            case 'email':
                $email = $this->item->createElement('gd:email');
                $email->setAttribute('address' ,$value);
                $email->setAttribute('rel' ,'http://schemas.google.com/g/2005#other');
                if (!empty($attributes['primary'])) {
                    $email->setAttribute('primary', 'true');
                }
                $entry->appendChild($email);
                break;
            case 'group':
                $group = $this->item->createElement('gContact:groupMembershipInfo');
                $group->setAttribute('href', $value);
                $group->setAttribute('deleted', 'false');
                $entry->appendChild($group);
                break;
            case 'organization':
                $org = $this->item->createElement('gd:organization');
                $org->setAttribute('rel' ,'http://schemas.google.com/g/2005#other');
                $entry->appendChild($org);
                $orgName = $this->item->createElement('gd:orgName', $value);
                $org->appendChild($orgName);
                $title = (isset($attributes['title'])) ? $attributes['title'] : '';
                $orgTitle = $this->item->createElement('gd:orgTitle', $title);
                $org->appendChild($orgTitle);
                break;
        }
    }

    protected function getField($fieldName)
    {
        $children = $this->item->getElementsByTagNameNS(self::NAMESPACE_GD, $fieldName);
        return ($children->length > 0) ? $children->item(0) : null;
    }

    protected function getFieldValue($fieldName)
    {
        $obj = $this->getField($fieldName);
        if (is_object($obj)) {
            return $obj->nodeValue;
        }
        return '';
    }

    protected function getStructuredField($fieldName, $attributeName = null, $hasPrimary = false, $hasType = true)
    {
        $result = array();
        $children = $this->item->getElementsByTagNameNS(self::NAMESPACE_GD, $fieldName);
        if ($children->length > 0) {
            for($i = 0; $i < $children->length; $i++) {
                $elem = $children->item($i);

                $current = array();
                if ($hasType) {
                    if (!$elem->hasAttribute('rel')) {
                        continue;
                    }
                    $rel = $elem->getAttributeNode('rel')->value;
                    $type = substr($rel, strrpos($rel,'#') + 1);
                    $current['type'] = $type;
                }
                if (!empty($attributeName)) {
                    $current['value'] = $elem->getAttributeNode($attributeName)->value;
                } else {
                     $current['value'] = $elem->nodeValue;
                }

                if ($hasPrimary) {
                    $current['primary'] = $elem->hasAttribute('primary');
                }
                $result[] = $current;
            }
        }
        return $result;
    }

    public function getName()
    {
        $name = $this->getFieldValue('fullName');
        if (empty($name)) {
            $name = $this->getTitle();
        }
        return $name;
    }

    public function getFirstName()
    {
        return $this->getFieldValue('givenName');
    }

    public function getLastName()
    {
        return $this->getFieldValue('familyName');
    }

    public function getOrganization()
    {
        $result = array('name' => '', 'title' => '');
        $nameObj = $this->getField('organization');
        if (!empty($nameObj)) {
            $result['name'] = $this->getFieldValue('orgName');
            $result['title'] = $this->getFieldValue('orgTitle');
        }
        return $result;
    }

    public function getEmails()
    {
        return $this->getStructuredField('email', 'address', true);
    }

    public function getPrimaryEmail()
    {
        $primaryEmail = '';
        $emails = $this->getEmails();
        if (!empty($emails)) {
            foreach ($emails as $email) {
                if ($email['primary']) {
                    $primaryEmail = $email['value'];
                    break;
                }
            }
            if (empty($primaryEmail)) {
                $primaryEmail = $emails[0]['value'];
            }
        }
        return $primaryEmail;
    }

    public function getPhoneNumbers()
    {
        return $this->getStructuredField('phoneNumber');
    }

    public function getPostalAddresses()
    {
        return $this->getStructuredField('postalAddress');
    }

    public function getWebsites()
    {
        return $this->getStructuredField('website');
    }

    public function getContent()
    {
        return $this->getChildNodeValue('content');
    }

    public function getTitle()
    {
        return $this->getChildNodeValue('title');
    }

    public function updated()
    {
        $updatedString = $this->getChildNodeValue('updated');
        $updated = new \DateTime($updatedString, new \DateTimeZone('UTC'));
        return $updated->format("Y-m-d H:i:s");
    }

    public function getGroupIds()
    {
        $result = array();

        $children = $this->item->getElementsByTagNameNS(self::NAMESPACE_G_CONTACT, 'groupMembershipInfo');
        if ($children->length > 0) {
            for($i = 0; $i < $children->length; $i++) {
                $elem = $children->item($i);

                $current = array();
                $groupId = $elem->getAttributeNode('href')->value;
                $current['value'] = substr($groupId, strrpos($groupId,'/') + 1);
                $current['deleted'] = $elem->getAttributeNode('deleted')->value === "true";
                $result[] = $current;
            }
        }
        return $result;
    }

    public function getPhotoLink()
    {
        return $this->getLinkHref(self::LINK_REL_PHOTO);
    }

    public function getEditLink()
    {
        return $this->getLinkHref(self::LINK_REL_EDIT);
    }

}
