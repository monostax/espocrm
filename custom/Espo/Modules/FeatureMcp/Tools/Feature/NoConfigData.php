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

use Espo\Modules\Mcp\Tools\Feature\Data;
use stdClass;

/**
 * Data of a feature with nothing to configure: the advanced filter tools work on every record type the CRM can query
 * and every field the user may read, so an admin only adds the feature to an endpoint. One per endpoint (the key is
 * the type). `version` only keeps the stored data a non-empty object.
 */
abstract readonly class NoConfigData implements Data
{
    final public function __construct() {}

    public function composeName(): string
    {
        return $this->getKey();
    }

    public function jsonSerialize(): stdClass
    {
        return (object) ['version' => 1];
    }

    public static function fromRaw(stdClass $raw): static
    {
        return new static();
    }

    public function getKey(): string
    {
        return static::TYPE;
    }
}
