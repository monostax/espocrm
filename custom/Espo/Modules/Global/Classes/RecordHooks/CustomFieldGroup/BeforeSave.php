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

namespace Espo\Modules\Global\Classes\RecordHooks\CustomFieldGroup;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;

/**
 * Validates CustomFieldGroup machine name and entityType.
 *
 * @implements SaveHook<Entity>
 * @noinspection PhpUnused
 */
class BeforeSave implements SaveHook
{
    private const NAME_PATTERN = '/^[a-z][a-zA-Z0-9_]*$/';

    public function __construct(
        private Metadata $metadata,
    ) {}

    public function process(Entity $entity): void
    {
        $name = $entity->get('name');

        if (!is_string($name) || $name === '' || !preg_match(self::NAME_PATTERN, $name)) {
            throw new BadRequest(
                "CustomFieldGroup.name must match /^[a-z][a-zA-Z0-9_]*$/ (no dots)."
            );
        }

        // Explicitly ban dots so valueKey group.field stays unambiguous.
        if (str_contains($name, '.')) {
            throw new BadRequest('CustomFieldGroup.name cannot contain dots.');
        }

        $entityType = $entity->get('entityType');

        if (!is_string($entityType) || $entityType === '') {
            throw new BadRequest('entityType is required.');
        }

        /** @var list<string>|null $enabled */
        $enabled = $this->metadata->get(['app', 'customFields', 'entityTypeList']);

        if (is_array($enabled) && !in_array($entityType, $enabled, true)) {
            throw new BadRequest(
                "entityType '{$entityType}' is not enabled for custom fields."
            );
        }

        $label = $entity->get('label');

        if (!is_string($label) || trim($label) === '') {
            $entity->set('label', $name);
        }
    }
}
