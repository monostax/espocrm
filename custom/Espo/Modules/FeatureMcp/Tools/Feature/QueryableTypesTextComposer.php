<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Feature;

use Espo\Core\Utils\Language;
use Espo\Modules\FeatureMcp\Tools\Query\QueryableTypes;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Feature\TextComposer;

/** The feature's "Target" column: the record types the advanced filter covers. */
class QueryableTypesTextComposer implements TextComposer
{
    public function __construct(
        private Language $language,
        private QueryableTypes $types,
    ) {}

    public function compose(Data $data): string
    {
        return implode(', ', array_map(
            fn (string $type) => $this->language->translateLabel($type, 'scopeNamesPlural'),
            $this->types->all()
        ));
    }
}
