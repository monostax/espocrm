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

namespace Espo\Modules\FeatureMcp\Tools\Query;

use Espo\Core\Acl;
use Espo\Core\Utils\Metadata;

/**
 * Record types the advanced filter works on: those whose lists accept the `recordQuery` where item (the same ones the
 * Chatwoot filter panel offers it on), narrowed to what the user may read.
 */
class QueryableTypes
{
    public const string WHERE_TYPE = 'recordQuery';

    public function __construct(
        private Metadata $metadata,
        private Acl $acl,
    ) {}

    /** @return string[] */
    public function all(): array
    {
        $types = [];

        foreach (array_keys($this->metadata->get(['selectDefs']) ?? []) as $type) {
            if ($this->supports($type)) {
                $types[] = $type;
            }
        }

        sort($types);

        return $types;
    }

    /** @return string[] */
    public function readable(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (string $type) => $this->acl->checkScope($type, Acl\Table::ACTION_READ)
        ));
    }

    public function supports(string $entityType): bool
    {
        return (bool) $this->metadata->get(
            ['selectDefs', $entityType, 'whereItemConverterClassNameMap', 'id_' . self::WHERE_TYPE]
        );
    }
}
