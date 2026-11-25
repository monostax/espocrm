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
use Espo\Core\Utils\FieldUtil;
use Espo\Core\Utils\Metadata;
use Espo\Tools\Layout\LayoutProvider;
use Throwable;

/** Which fields a query may return or sort by: the record type's fields the user may read, as in the record API. */
class RecordFields
{
    public function __construct(
        private Metadata $metadata,
        private Acl $acl,
        private FieldUtil $fieldUtil,
        private LayoutProvider $layoutProvider,
    ) {}

    public function readable(string $entityType, string $field): bool
    {
        $defs = $this->metadata->get(['entityDefs', $entityType, 'fields', $field]);

        return is_array($defs) &&
            empty($defs['disabled']) &&
            empty($defs['utility']) &&
            !in_array($field, $this->acl->getScopeForbiddenFieldList($entityType, Acl\Table::ACTION_READ), true);
    }

    /**
     * Without a `select`, the columns of the record type's list, as people see it in Monostax.
     *
     * @return string[]
     */
    public function defaultSelect(string $entityType): array
    {
        $fields = [];

        try {
            $layout = json_decode((string) $this->layoutProvider->get($entityType, 'list'), true);
        } catch (Throwable) {
            $layout = null;
        }

        foreach (is_array($layout) ? $layout : [] as $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : null;

            if (is_string($name) && $this->readable($entityType, $name)) {
                $fields[] = $name;
            }
        }

        if ($this->readable($entityType, 'name')) {
            array_unshift($fields, 'name');
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param string[] $fields
     * @return string[]
     */
    public function attributes(string $entityType, array $fields): array
    {
        $attributes = ['id'];

        foreach ($fields as $field) {
            array_push($attributes, ...$this->fieldUtil->getAttributeList($entityType, $field));
        }

        return array_values(array_unique($attributes));
    }
}
