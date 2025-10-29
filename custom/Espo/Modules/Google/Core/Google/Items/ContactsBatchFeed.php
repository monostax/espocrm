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
use DOMXPath;

class ContactsBatchFeed extends XMLFeedBase
{
    const NAMESPACE_GD = 'http://schemas.google.com/g/2005';
    const NAMESPACE_G_CONTACT = 'http://schemas.google.com/contact/2008';
    const NAMESPACE_BATCH = 'http://schemas.google.com/gdata/batch';

    protected $currentEntry = null;
    protected $feed = null;
    protected $xpath = null;

    public function addEntry($entry = null)
    {
        if (!$entry) {
            $entry = $this->item->createElement('entry');
        } else {
            $entry = $this->item->importNode($entry, true);
        }

        $this->feed->appendChild($entry);
        $this->currentEntry = $entry;
    }

    public function addOperation($type)
    {
        $fieldItem = $this->item->createElement('batch:operation');
        $fieldItem->setAttribute('type', $type);
        $this->currentEntry->appendChild($fieldItem);
        if ($type != "insert") {
            $this->currentEntry->setAttributeNS(self::NAMESPACE_GD,'gd:etag', '*');
        }
    }

    public function __construct($xml = '')
    {
        if (empty($xml)) {
            $xml = $this->createFeed();
        }
        $this->init($xml);

        $this->xpath = new DOMXPath($this->item);
        $this->xpath->registerNameSpace('gContact', self::NAMESPACE_G_CONTACT);
        $this->xpath->registerNameSpace('gd', self::NAMESPACE_GD);
    }

    public function createFeed()
    {
        $doc  = new DOMDocument('1.0', 'utf-8');

        $doc->formatOutput = true;
        $doc->resolveExternals = true;
        $feed = $doc->createElementNS('http://www.w3.org/2005/Atom', 'feed');
        $doc->appendChild($feed);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:batch', self::NAMESPACE_BATCH);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:gContact', self::NAMESPACE_G_CONTACT);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:gd', self::NAMESPACE_GD);
        $this->feed = $feed;

        return $doc;
    }

    public function addField($fieldName, $value, $attributes = array())
    {
        $value = htmlspecialchars($value, ENT_XML1, 'UTF-8');
        switch ($fieldName) {
            case 'title':
            case 'content':
                $fieldItem = $this->item->createElement($fieldName, $value);
                $fieldItem->setAttribute('type', 'text');
                $this->currentEntry->appendChild($fieldItem);
                break;
            case 'name':
                $name = $this->item->createElement('gd:name');
                $this->currentEntry->appendChild($name);
                $fullName = $this->item->createElement('gd:fullName', $value);
                $name->appendChild($fullName);
                if (isset($attributes['firstName'])) {
                    $firstName = $this->item->createElement('gd:givenName', htmlspecialchars($attributes['firstName'], ENT_XML1, 'UTF-8'));
                    $name->appendChild($firstName);
                }
                if (isset($attributes['lastName'])) {
                    $lastName = $this->item->createElement('gd:familyName', htmlspecialchars($attributes['lastName'], ENT_XML1, 'UTF-8'));
                    $name->appendChild($lastName);
                }
                break;
            case 'email':
                $email = $this->item->createElement('gd:email');
                $email->setAttribute('address' ,$value);
                $email->setAttribute('rel' ,'http://schemas.google.com/g/2005#other');
                if (!empty($attributes['primary'])) {
                    $email->setAttribute('primary', 'true');
                }
                $this->currentEntry->appendChild($email);
                break;
            case 'phoneNumber':
                $phone = $this->item->createElement('gd:phoneNumber', $value);
                $phone->setAttribute('rel' ,'http://schemas.google.com/g/2005#' . $attributes['type']);
                if (!empty($attributes['primary'])) {
                    $phone->setAttribute('primary', 'true');
                }
                $this->currentEntry->appendChild($phone);
                break;
            case 'group':
                $group = $this->item->createElement('gContact:groupMembershipInfo');
                $group->setAttribute('href', $value);
                $group->setAttribute('deleted', 'false');
                $this->currentEntry->appendChild($group);
                break;
            case 'organization':
                $org = $this->item->createElement('gd:organization');
                $org->setAttribute('rel' ,'http://schemas.google.com/g/2005#other');
                $this->currentEntry->appendChild($org);
                $orgName = $this->item->createElement('gd:orgName', $value);
                $org->appendChild($orgName);
                $title = (isset($attributes['title'])) ? htmlspecialchars($attributes['title'], ENT_XML1, 'UTF-8') : '';
                $orgTitle = $this->item->createElement('gd:orgTitle', $title);
                $org->appendChild($orgTitle);
                break;
            case 'batchId':
                $id = $this->item->createElement('batch:id', $value);
                $this->currentEntry->appendChild($id);
                break;
            case 'id':
                $id = $this->item->createElement('id', 'http://www.google.com/m8/feeds/contacts/default/base/' . $value);
                $this->currentEntry->appendChild($id);
                break;
        }
    }

    public function updateField($fieldName, $value, $attributes = array())
    {
        $value = htmlspecialchars($value);
        $entry = $this->item->getElementsByTagName('entry')->item(0);
        switch ($fieldName) {
            case 'title':
            case 'content':
                $fieldItem = $this->getChildNode($fieldName);
                if ($fieldItem) {
                    $fieldItem->nodeValue = $value;
                } else {
                     $this->addField($fieldName, $value, $attributes);
                }
                break;
            case 'name':
                $oldField = $this->getChildNode('gd:name');
                if ($oldField) {
                    $this->currentEntry->removeChild($oldField);
                }
                $this->addField($fieldName, $value, $attributes);
                break;
            case 'email':
                $res = $this->xpath->query("//gd:email[@address='{$value}']", $this->currentEntry);
                if ($res->length) {
                    foreach ($res as $node) {
                        $email = $node;
                        break;
                    }
                    if (!empty($attributes['primary'])) {
                        $email->setAttribute('primary', 'true');
                    }
                } else {
                    $email = $this->addField($fieldName, $value, $attributes);
                }

                if (!empty($attributes['primary'])) {
                    $res = $this->xpath->query("//gd:email[@primary='true']", $this->currentEntry);
                    if ($res->length) {
                        foreach ($res as $node) {
                            if ($node->nodeValue != $value)
                                $node->setAttribute('primary', 'false');
                        }
                    }
                }
                break;
            case 'phoneNumber':
                $res = $this->xpath->query("//gd:phoneNumber[.='{$value}']", $this->currentEntry);
                if ($res->length) {
                    foreach ($res as $node) {
                        $phone = $node;
                        break;
                    }
                    if (!empty($attributes['primary'])) {
                        $phone->setAttribute('primary', 'true');
                    }
                    $phone->setAttribute('rel' ,'http://schemas.google.com/g/2005#' . $attributes['type']);
                } else {
                    $phone = $this->addField($fieldName, $value, $attributes);
                }

                if (!empty($attributes['primary'])) {
                    $res = $this->xpath->query("//gd:phoneNumber[@primary='true']", $this->currentEntry);
                    if ($res->length) {
                        foreach ($res as $node) {
                            if ($node->nodeValue != $value)
                                $node->setAttribute('primary', 'false');
                        }
                    }
                }
                break;
            case 'group':
                $res = $this->xpath->query("//gContact:groupMembershipInfo[@href='{$value}']", $this->currentEntry);
                if ($res->length) {
                    foreach ($res as $node) {
                        $group = $node;
                        break;
                    }
                    $group->setAttribute('deleted', 'false');
                } else {
                    $group = $this->addField('group', $value);
                }
                break;
            case 'organization':
                $children = $this->currentEntry->getElementsByTagNameNS(self::NAMESPACE_GD, 'organization');
                if ($children->length > 0) {
                    foreach ($children as $chl) {
                        $this->currentEntry->removeChild($chl);
                    }
                }
                $this->addField($fieldName, $value, $attributes);
                break;
        }
    }

    protected function getChildNode($nodeName)
    {
        $children = $this->currentEntry->getElementsByTagName($nodeName);
        return ($children->length > 0) ? $children->item(0) : false;
    }
}
