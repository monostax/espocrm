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

namespace Espo\Modules\Global\Tools\CustomField;

use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Handlebars data for a message about a record to one receiver.
 *
 * Paths stay relative to the record (`{{name}}`, `{{account.name}}`,
 * `{{customFields.x}}`); `{{contact.*}}` is the receiver, not the record's own
 * `contact` link (an Opportunity's primary contact). Every to-one link the
 * template names is loaded here with custom fields expanded, so the caller
 * renders with links skipped. Call {@see TemplateBridge::restoreExpanded()}
 * after rendering.
 */
class ReceiverTemplateData
{
    private const EXPRESSION = '/\{\{\s*([a-zA-Z_]\w*(?:\.\w+)*)\s*\}\}/';

    public function __construct(
        private EntityManager $entityManager,
        private TemplateBridge $templateBridge,
        private AclManager $aclManager,
    ) {}

    public static function usesReceiver(string $template): bool
    {
        return str_contains($template, '{{contact.') || str_contains($template, '{{ contact.');
    }

    /**
     * @param ?User $aclUser checks read access on loaded links when given
     * @return array<string, mixed>
     */
    public function build(Entity $record, ?Entity $receiver, string $template, ?User $aclUser = null): array
    {
        $data = [];

        foreach ($record->getRelationList() as $relation) {
            $type = $record->getRelationType($relation);

            if (
                $relation === 'contact' ||
                !in_array($type, [Entity::BELONGS_TO, Entity::BELONGS_TO_PARENT, Entity::HAS_ONE], true) ||
                !preg_match('/\{\{\s*' . preg_quote($relation, '/') . '\./', $template)
            ) {
                continue;
            }

            try {
                $related = $this->entityManager->getRelation($record, $relation)->findOne();
            } catch (Throwable) {
                continue;
            }

            if (!$related || ($aclUser && !$this->aclManager->checkEntityRead($aclUser, $related))) {
                continue;
            }

            $this->templateBridge->expandInPlace($related);
            $data[$relation] = self::toArray($related);
        }

        if ($receiver) {
            $this->templateBridge->expandInPlace($receiver);
            $data['contact'] = self::toArray($receiver);
        }

        return $data;
    }

    /**
     * Plain `{{a.b}}` expressions in the template that resolve to nothing for
     * this record and receiver, e.g. `{{account.customFields.x}}` with no value.
     *
     * @return list<string>
     */
    public function emptyExpressions(Entity $record, ?Entity $receiver, string $template): array
    {
        preg_match_all(self::EXPRESSION, $template, $matches);

        if ($matches[1] === []) {
            return [];
        }

        try {
            $this->templateBridge->expandInPlace($record);
            $root = array_replace(self::toArray($record), $this->build($record, $receiver, $template));
        } finally {
            $this->templateBridge->restoreExpanded();
        }

        $empty = [];

        foreach (array_unique($matches[1]) as $path) {
            $value = $root;

            foreach (explode('.', $path) as $key) {
                $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : null;
            }

            if ($value === null || $value === '' || is_array($value)) {
                $empty[] = '{{' . $path . '}}';
            }
        }

        return $empty;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Entity $entity): array
    {
        $decoded = json_decode((string) json_encode($entity->getValueMap()), true);

        return is_array($decoded) ? $decoded : [];
    }
}
