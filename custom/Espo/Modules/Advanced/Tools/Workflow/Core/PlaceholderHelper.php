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
 * License ID: c4060ef13557322b374635a5ad844ab2
 ************************************************************************************/

namespace Espo\Modules\Advanced\Tools\Workflow\Core;

class PlaceholderHelper
{
    public function __construct(
        private SecretProvider $secretProvider,
    ) {}

    public function applySecrets(string $content): string
    {
        return preg_replace_callback('/{#secrets\.([A-Za-z0-9_]+)}/', function ($matches) {
            $name = trim($matches[1]);

            $secret = $this->secretProvider->get($name);

            return $secret ?? '';
        }, $content);
    }
}
