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

namespace Espo\Modules\FeatureMcp\Tools\SavedFilters;

/**
 * A saved filter's condition rows to CRM where items, the same way the Chatwoot lists build them, so a saved filter
 * returns over MCP what it returns in Monostax. Port of `createCrmWhereBuilder` and the field maps in Chatwoot
 * `app/javascript/dashboard/api/crm/{opportunityFilters,contact,account,initiative}.js`: keep them in step. Every item is ANDed
 * (the browser ignores `queryOperator` too) and runs with the user's access like any list request.
 */
class ConditionWhere
{
    private const array FIELDS = [
        'Contact' => [
            'name' => 'text', 'account' => 'link', 'assignedUser' => 'link', 'teams' => 'link', 'tags' => 'link',
            'source' => 'enum', 'emailAddress' => 'text', 'phoneNumber' => 'text', 'cpf' => 'text',
            'hasLinkedin' => 'bool', 'createdAt' => 'datetime', 'modifiedAt' => 'datetime', 'createdBy' => 'link',
            'description' => 'text', 'emailEnrichedAt' => 'datetime', 'emailEnrichmentResult' => 'enum',
            'phoneEnrichedAt' => 'datetime', 'phoneEnrichmentResult' => 'enum',
        ],
        'Account' => [
            'name' => 'text', 'cnpj' => 'text', 'website' => 'text', 'linkedinUrl' => 'text', 'assignedUser' => 'link',
            'teams' => 'link', 'type' => 'enum', 'industry' => 'text', 'emailAddress' => 'text',
            'phoneNumber' => 'text', 'createdAt' => 'datetime', 'modifiedAt' => 'datetime', 'createdBy' => 'link',
            'description' => 'text', 'sociosEnrichedAt' => 'datetime', 'sociosEnrichmentResult' => 'enum',
        ],
        'Opportunity' => [
            'funnel' => 'link', 'tags' => 'link', 'assignedUser' => 'link', 'teams' => 'link',
            'sourceChannel' => 'enum', 'sourceTargetList' => 'link', 'followupStatus' => 'enum',
            'followupTimer' => 'datetime', 'followupCount' => 'int', 'followupAiAgent' => 'link',
            'createdAt' => 'datetime', 'createdBy' => 'link', 'modifiedAt' => 'datetime',
            'streamUpdatedAt' => 'datetime', 'account' => 'link', 'amountConverted' => 'float',
            'amountWeightedConverted' => 'float', 'campaign' => 'link', 'closeDate' => 'date', 'contacts' => 'link',
            'leadSource' => 'enum', 'probability' => 'int', 'description' => 'text',
        ],
        'Initiative' => [
            'initiativeType' => 'link', 'assignedUser' => 'link', 'createdBy' => 'link', 'createdAt' => 'datetime',
            'modifiedAt' => 'datetime', 'name' => 'text', 'description' => 'text',
        ],
    ];

    private const array LINK_MULTIPLE = [
        'Contact' => ['teams', 'tags'],
        'Account' => ['teams'],
        'Opportunity' => ['teams', 'contacts', 'tags'],
    ];

    public static function supports(string $entityType): bool
    {
        return isset(self::FIELDS[$entityType]);
    }

    /**
     * @param mixed $conditions the saved definition's `conditions`
     * @return list<array<string, mixed>>
     */
    public function build(string $entityType, mixed $conditions, ?string $timeZone): array
    {
        $fields = self::FIELDS[$entityType] ?? [];
        $multiple = self::LINK_MULTIPLE[$entityType] ?? [];
        $items = [];

        foreach (is_array($conditions) ? $conditions : [] as $row) {
            $row = json_decode((string) json_encode($row), true);

            if (!is_array($row)) {
                continue;
            }

            $key = $row['attributeKey'] ?? null;
            $operator = $row['filterOperator'] ?? null;
            $values = $row['values'] ?? null;

            if ($key === 'recordQuery') {
                if (is_string($values) && trim($values) !== '') {
                    $items[] = [
                        'type' => 'recordQuery',
                        'attribute' => 'id',
                        'value' => ['query' => $values] + ($timeZone ? ['timeZone' => $timeZone] : []),
                    ];
                }

                continue;
            }

            if ($entityType === 'Opportunity' && $key === 'chatwootInbox') {
                if ($values !== null) {
                    $items[] = ['type' => 'inChatwootInbox', 'attribute' => 'id', 'value' => $values];
                }

                continue;
            }

            $item = $this->item($key, $operator, $values, $fields, $multiple, $timeZone);

            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, string> $fields
     * @param string[] $multiple
     * @return ?array<string, mixed>
     */
    private function item(
        mixed $key,
        mixed $operator,
        mixed $values,
        array $fields,
        array $multiple,
        ?string $timeZone,
    ): ?array {

        if (!is_string($key) || !isset($fields[$key]) || !is_string($operator)) {
            return null;
        }

        $type = $fields[$key];
        $presence = in_array($operator, ['is_present', 'is_not_present'], true);

        if (!$presence && $values === null) {
            return null;
        }

        if ($type === 'bool') {
            $flag = is_array($values) ? ($values['id'] ?? null) : null;

            if (!is_bool($flag) || !in_array($operator, ['equal_to', 'not_equal_to'], true)) {
                return null;
            }

            return ['type' => $flag === ($operator === 'equal_to') ? 'isTrue' : 'isFalse', 'attribute' => $key];
        }

        if ($presence && $type === 'text') {
            return $this->textPresence($key, $operator === 'is_present');
        }

        $isMultiple = in_array($key, $multiple, true);
        $isLink = $type === 'link' && !$isMultiple;
        $isDateTime = $type === 'datetime';
        $value = $this->normalize($values, $type, $isMultiple);
        $isList = $type === 'enum' || is_array($value);
        $hasValue = is_array($value) ? $value !== [] : $value !== '' && $value !== null;
        $attribute = $isLink ? $key . 'Id' : $key;

        $build = function (string $whereType, mixed $value) use ($attribute, $isDateTime, $timeZone): array {
            $item = ['type' => $whereType, 'attribute' => $attribute, 'value' => $value];

            if ($isDateTime) {
                $item['dateTime'] = true;

                if ($timeZone) {
                    $item['timeZone'] = $timeZone;
                }
            }

            return $item;
        };

        switch ($operator) {
            case 'equal_to':
                if (!$hasValue) return null;
                if ($isMultiple) return $build('linkedWith', (array) $value);
                if ($isList) return $build('in', $value);
                if ($isDateTime) return $build('on', $value);
                return $build('equals', $value);

            case 'not_equal_to':
                // Espo has no notOn; the browser never offers it for date-times.
                if (!$hasValue || $isDateTime) return null;
                if ($isMultiple) return $build('notLinkedWith', (array) $value);
                if ($isList) return $build('notIn', $value);
                return $build('notEquals', $value);

            case 'is_present':
                return $build($isMultiple ? 'isLinked' : 'isNotNull', null);

            case 'is_not_present':
                return $build($isMultiple ? 'isNotLinked' : 'isNull', null);

            case 'contains':
                return $hasValue && is_scalar($value) ? $build('like', '%' . $value . '%') : null;

            case 'does_not_contain':
                return $hasValue && is_scalar($value) ? $build('notLike', '%' . $value . '%') : null;

            case 'is_greater_than':
                if (!$hasValue) return null;
                return $build(in_array($type, ['datetime', 'date'], true) ? 'after' : 'greaterThan', $value);

            case 'is_less_than':
                if (!$hasValue) return null;
                return $build(in_array($type, ['datetime', 'date'], true) ? 'before' : 'lessThan', $value);

            case 'days_before':
                return $hasValue ? $build('olderThanXDays', $value) : null;
        }

        return null;
    }

    /**
     * As the browser: every link row (link-multiple too) is a single search-select {id, name}; enum and
     * multi-select rows hold [{id, name}] or plain values.
     */
    private function normalize(mixed $values, string $type, bool $isMultiple): mixed
    {
        if ($type === 'link') {
            return is_array($values) ? ($values['id'] ?? null) : null;
        }

        if ($type === 'enum' || $isMultiple || (is_array($values) && array_is_list($values))) {
            return array_map(fn ($it) => is_array($it) ? ($it['id'] ?? null) : $it, is_array($values) ? $values : []);
        }

        return $values;
    }

    /** @return array<string, mixed> Espo has no isEmpty for text; mirrors its varchar search. */
    private function textPresence(string $attribute, bool $present): array
    {
        return $present
            ? ['type' => 'and', 'value' => [
                ['type' => 'isNotNull', 'attribute' => $attribute],
                ['type' => 'notEquals', 'attribute' => $attribute, 'value' => ''],
            ]]
            : ['type' => 'or', 'value' => [
                ['type' => 'isNull', 'attribute' => $attribute],
                ['type' => 'equals', 'attribute' => $attribute, 'value' => ''],
            ]];
    }
}
