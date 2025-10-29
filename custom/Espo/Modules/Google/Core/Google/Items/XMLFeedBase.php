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
use DOMElement;
use RuntimeException;
use SimpleXMLElement;

class XMLFeedBase
{
    const LINK_REL_ALTERNATE = "alternate";
    const LINK_REL_FEED = "http://schemas.google.com/g/2005#feed";
    const LINK_REL_POST = "http://schemas.google.com/g/2005#post";
    const LINK_REL_BATCH = "http://schemas.google.com/g/2005#batch";
    const LINK_REL_SELF = "self";
    const LINK_REL_NEXT = "next";
    const LINK_REL_PREVIOUS = "previous";
    const LINK_REL_EDIT = "edit";

    protected $item;

    public function __construct($xml = '')
    {
        if (!empty($xml)) {
            $this->init($xml);
        }
    }

    public function init($xml)
    {
        if ($xml instanceof DOMDocument) {
            $this->item = $xml;
        } else if ($xml instanceof SimpleXMLElement) {
            $this->item = dom_import_simplexml($xml);
        } else {
            $this->item = new DOMDocument();
            $this->item->loadXML($xml);
            if (empty($this->item)){
                throw new RuntimeException("Xml parse error");
            }
        }
    }

    public function getId()
    {
        return $this->getChildNodeValue('id');
    }

    protected function getChildNodeValue($nodeName)
    {
        $children = $this->item->getElementsByTagName($nodeName);
        return ($children->length > 0) ? $children->item(0)->nodeValue : '';
    }

    public function getTitle()
    {
        return $this->getChildNodeValue('title');
    }

    public function getEntries()
    {
        return $this->item->getElementsByTagName('entry');
    }

    protected function getLinkHref($rel)
    {
        $links = $this->item->getElementsByTagName('link');
        foreach ($links as $link) {
            if ($rel == $link->getAttribute('rel')) {
                return $link->getAttribute('href');
            }
        }
        return false;
    }

    public function getFeedLink()
    {
        return $this->getLinkHref(self::LINK_REL_FEED);
    }

    public function getPostLink()
    {
        return $this->getLinkHref(self::LINK_REL_POST);
    }

    public function getBatchLink()
    {
        return $this->getLinkHref(self::LINK_REL_BATCH);
    }

    public function getNextLink()
    {
        return $this->getLinkHref(self::LINK_REL_NEXT);
    }

    public function getPreviousLink()
    {
        return $this->getLinkHref(self::LINK_REL_PREVIOUS);
    }

    public function getEditLink()
    {
        return $this->getLinkHref(self::LINK_REL_EDIT);
    }

    public function asXML()
    {
        if ($this->item instanceof DOMElement) {
            $xml = $this->item->ownerDocument->saveXML($this->item);
        } else {
            $xml = $this->item->saveXML();
        }
        return $xml;
    }
}
