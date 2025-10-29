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

class ContactsBatchEntry extends ContactsEntry
{
    const NAMESPACE_GD = 'http://schemas.google.com/g/2005';
    const NAMESPACE_G_CONTACT = 'http://schemas.google.com/contact/2008';
    const NAMESPACE_BATCH = 'http://schemas.google.com/gdata/batch';

    public function getBatchId()
    {
        return $this->getChildNodeValue('id', self::NAMESPACE_BATCH);
    }

    protected function getStatusNode()
    {
        return $this->getChildNode('status');
    }

    public function getStatusCode()
    {
        $status = $this->getStatusNode();

        return ($status) ? $status->getAttribute('code') : false;
    }

    public function getStatusMessage()
    {
        $status = $this->getStatusNode();

        return ($status) ? $status->getAttribute('reason') : '';
    }

    public function getOperationType()
    {
        $node = $this->getChildNode('operation');

        return ($node) ? $node->getAttribute('type') : false;
    }

    public function getId()
    {
        return $this->getChildNodeValue('id', 'http://www.w3.org/2005/Atom');
    }

    protected function getChildNodeValue($nodeName, $namespace = '*')
    {
        $children = $this->item->getElementsByTagNameNS($namespace, $nodeName);

        if ($children) {
            return $children->item(0)->nodeValue;
        }

        return '';
    }
}
