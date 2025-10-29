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

class Util
{
    public static function convertContactIdToResourceName(string $id): string
    {
        return 'people/c' . hexdec($id);
    }

    public static function convertContactGroupIdToResourceName(string $id): string
    {
        return 'contactGroups/' . array_slice(explode('/', $id), -1, 1)[0];
    }

    public static function isLegacyContactGroupId(string $id): bool
    {
        return strpos($id, 'www.google.com') !== false;
    }

    public static function normalizeGroupResourceName(string $name): string
    {
        if (self::isLegacyContactGroupId($name)) {
            return self::convertContactGroupIdToResourceName($name);
        }

        return $name;
    }
}
