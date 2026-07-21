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

namespace Espo\Modules\Global\Classes\RecordHooks\CustomFieldDef;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Validates CustomFieldDef, locks valueKey after create, and auto-builds
 * valueKey from group.name + field.name on create.
 *
 * Key design:
 *  - valueKey is the ONLY identity for stored values (e.g. "address.city")
 *  - group is presentation; moving field across groups does NOT change valueKey
 *  - valueKey is immutable after create (readOnlyAfterCreate + server guard)
 *
 * @implements SaveHook<Entity>
 * @noinspection PhpUnused
 */
class BeforeSave implements SaveHook
{
    private const NAME_PATTERN = '/^[a-z][a-zA-Z0-9_]*$/';
    private const VALUE_KEY_PATTERN = '/^[a-z][a-zA-Z0-9_]*(\.[a-z][a-zA-Z0-9_]*)?$/';

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
    ) {}

    public function process(Entity $entity): void
    {
        $name = $entity->get('name');

        if (!is_string($name) || $name === '' || !preg_match(self::NAME_PATTERN, $name)) {
            throw new BadRequest(
                "CustomFieldDef.name must match /^[a-z][a-zA-Z0-9_]*$/ (no dots)."
            );
        }

        if (str_contains($name, '.')) {
            throw new BadRequest('CustomFieldDef.name cannot contain dots.');
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

        $type = $entity->get('type');
        /** @var list<string>|null $allowedTypes */
        $allowedTypes = $this->metadata->get(['app', 'customFields', 'allowedTypes']);

        if (
            !is_string($type) ||
            (is_array($allowedTypes) && !in_array($type, $allowedTypes, true))
        ) {
            throw new BadRequest("Invalid custom field type '{$type}'.");
        }

        if (in_array($type, ['enum', 'multiEnum'], true)) {
            $options = $entity->get('options');

            if (!is_array($options) && !($options instanceof \stdClass)) {
                throw new BadRequest('options are required for enum/multiEnum fields.');
            }

            $list = is_array($options) ? $options : array_values((array) $options);

            if ($list === []) {
                throw new BadRequest('options must not be empty for enum/multiEnum fields.');
            }
        }

        $label = $entity->get('label');

        if (!is_string($label) || trim($label) === '') {
            $entity->set('label', $name);
        }

        $groupId = $entity->get('groupId');
        $groupName = null;

        if (is_string($groupId) && $groupId !== '') {
            $group = $this->entityManager->getEntityById('CustomFieldGroup', $groupId);

            if (!$group) {
                throw new BadRequest('CustomFieldGroup not found.');
            }

            $groupEntityType = $group->get('entityType');

            if (is_string($groupEntityType) && $groupEntityType !== '' && $groupEntityType !== $entityType) {
                $entity->set('entityType', $groupEntityType);
                $entityType = $groupEntityType;
            }

            $groupName = is_string($group->get('name')) ? $group->get('name') : null;

            if (!$entity->get('teamsIds') && $group instanceof CoreEntity) {
                try {
                    $teamIds = $group->getLinkMultipleIdList('teams') ?: [];

                    if ($teamIds !== []) {
                        $entity->set('teamsIds', $teamIds);
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }

            if (!$entity->get('tenantId') && $group->get('tenantId')) {
                $entity->set('tenantId', $group->get('tenantId'));
            }
        }

        if ($entity->isNew()) {
            $valueKey = $entity->get('valueKey');
            $valueKey = is_string($valueKey) ? trim($valueKey) : '';

            // Default identity = group.name + field.name when grouped.
            // Also rewrite the common client footgun of submitting valueKey === name
            // (mirrors the machine name into the storage key and drops the group prefix).
            // Explicit dotted keys (e.g. address.city) and non-name bare keys are kept.
            $shouldDefault =
                $valueKey === '' ||
                ($groupName !== null && $groupName !== '' && $valueKey === $name);

            if ($shouldDefault) {
                $valueKey = ($groupName !== null && $groupName !== '')
                    ? $groupName . '.' . $name
                    : $name;

                $entity->set('valueKey', $valueKey);
            } else {
                $entity->set('valueKey', $valueKey);
            }

            if (!preg_match(self::VALUE_KEY_PATTERN, $valueKey)) {
                throw new BadRequest(
                    "valueKey must match /^[a-z][a-zA-Z0-9_]*(\\.[a-z][a-zA-Z0-9_]*)?$/."
                );
            }

            return;
        }

        // Existing record: valueKey is immutable (groups are UI-only moves).
        if ($entity->isAttributeChanged('valueKey')) {
            $original = $entity->getFetched('valueKey');
            $current = $entity->get('valueKey');

            if ($original !== null && $original !== $current) {
                throw new BadRequest(
                    'valueKey is immutable after create. Create a new field and migrate values if needed.'
                );
            }
        }
    }
}
