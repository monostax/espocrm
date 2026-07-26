<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SearchParams;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Materializes Batch map[] into item rows (target + payload + tenantId).
 *
 * Mode (row geometry):  primary | expand | loop | passThrough | groupBy
 * Source (where members come from): query | relation | linkMultiple | ids | payload | report
 *
 * Legacy mode=report ⇒ source=report + mode expand/primary.
 * Report is one optional source — loop is general-purpose.
 */
class MapMaterializer
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
        private InjectableFactory $injectableFactory,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $triggerPayload
     * @return list<array{targetType: ?string, targetId: ?string, tenantId: ?string, payload: array<string, mixed>}>
     */
    public function materialize(
        array $definition,
        ?string $automationTenantId,
        array $triggerPayload = [],
    ): array {
        $map = $definition['map'] ?? [];
        if (!is_array($map) || $map === []) {
            throw new Error('MapMaterializer: empty map.');
        }

        $limits = $definition['limits'] ?? [];
        $maxItems = (int) ($limits['maxItems'] ?? 5000);
        $maxExpand = (int) ($limits['maxExpandPerParent'] ?? 500);

        /** @var list<array<string, mixed>> $rows */
        $rows = [[
            '__ctx' => $triggerPayload,
            '__tenantId' => $automationTenantId,
            'entities' => [],
            'payload' => [],
        ]];

        foreach ($map as $stepIndex => $step) {
            if (!is_array($step)) {
                continue;
            }

            $step = $this->normalizeStep($step, $stepIndex);
            $mode = (string) $step['mode'];
            $stepId = (string) $step['id'];

            if ($mode === 'primary' || $stepIndex === 0) {
                // Root seed (query, report, ids, payload, …)
                $rows = $this->applyPrimary($step, $rows, $automationTenantId, $maxItems);
                continue;
            }

            $parentKey = (string) ($step['parent'] ?? '');
            $source = (string) ($step['source'] ?? 'query');
            $needsParentEntity = in_array($source, ['relation', 'linkMultiple'], true);

            if ($needsParentEntity && $parentKey === '') {
                throw new Error("Map step {$stepId}: parent is required for source={$source}.");
            }

            $next = [];

            foreach ($rows as $row) {
                $parentEntity = null;
                if ($parentKey !== '') {
                    $parentEntity = $row['entities'][$parentKey] ?? null;
                    if ($needsParentEntity && !$parentEntity instanceof Entity) {
                        continue;
                    }
                }

                $parentTenant = $row['__tenantId'] ?? $automationTenantId;
                if ($parentEntity instanceof Entity) {
                    $parentTenant = $this->resolveTenantId($parentEntity, $parentTenant);
                }

                $members = $this->resolveMembers(
                    $step,
                    $row,
                    $parentEntity instanceof Entity ? $parentEntity : null,
                    $parentTenant,
                    $maxExpand
                );

                if ($mode === 'passThrough') {
                    $blob = [];
                    foreach ($members as $member) {
                        $blob[] = $this->memberPayload($member);
                    }
                    $row['payload'][$stepId] = $blob;
                    $row['__tenantId'] = $parentTenant;
                    $next[] = $row;
                    continue;
                }

                if ($mode === 'groupBy') {
                    $this->applyGroupBy($step, $stepId, $row, $members, $parentTenant, $next, $maxItems);
                    if (count($next) >= $maxItems) {
                        break;
                    }
                    continue;
                }

                // expand | loop
                if ($members === []) {
                    continue;
                }

                foreach ($members as $member) {
                    $copy = $row;
                    if (!isset($copy['entities']) || !is_array($copy['entities'])) {
                        $copy['entities'] = [];
                    }
                    if (!isset($copy['payload']) || !is_array($copy['payload'])) {
                        $copy['payload'] = [];
                    }

                    $copy['payload'][$stepId] = $this->memberPayload($member);

                    if ($member['kind'] === 'entity' && $member['entity'] instanceof Entity) {
                        $ent = $member['entity'];
                        $copy['entities'][$stepId] = $ent;
                        $copy['targetType'] = $ent->getEntityType();
                        $copy['targetId'] = $ent->getId();
                        $copy['__tenantId'] = $this->resolveTenantId($ent, $parentTenant);
                    } else {
                        // Scalar/object value loop — keep parent target, stash value
                        if ($parentEntity instanceof Entity) {
                            $copy['entities'][$stepId] = $parentEntity;
                            $copy['targetType'] = $parentEntity->getEntityType();
                            $copy['targetId'] = $parentEntity->getId();
                        }
                        $copy['payload'][$stepId . 'Value'] = $member['value'] ?? null;
                        $copy['__tenantId'] = $parentTenant;
                    }

                    $next[] = $copy;
                    if (count($next) >= $maxItems) {
                        break 2;
                    }
                }
            }

            $rows = $next;
        }

        return $this->finalizeRows($rows, $automationTenantId, $triggerPayload, $maxItems);
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function normalizeStep(array $step, int $index): array
    {
        $mode = (string) ($step['mode'] ?? ($index === 0 ? 'primary' : 'expand'));
        if ($mode === 'loop') {
            $mode = 'expand';
        }

        $source = isset($step['source']) ? (string) $step['source'] : '';
        if ($source === '') {
            $source = $this->inferSource($step, $mode);
        }

        // Legacy mode=report
        if ($mode === 'report') {
            $source = 'report';
            $mode = $index === 0 ? 'primary' : 'expand';
        }

        $step['mode'] = $mode;
        $step['source'] = $source;
        $step['id'] = (string) ($step['id'] ?? "s{$index}");

        return $step;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function inferSource(array $step, string $mode): string
    {
        if (!empty($step['reportId']) || $mode === 'report') {
            return 'report';
        }
        if (!empty($step['payloadPath'])) {
            return 'payload';
        }
        if (!empty($step['ids']) || !empty($step['idsPath'])) {
            return 'ids';
        }
        if (!empty($step['link']) || !empty($step['linkMultiple'])) {
            return 'linkMultiple';
        }
        if (!empty($step['relation']) || (!empty($step['parent']) && $mode !== 'primary')) {
            return 'relation';
        }

        return 'query';
    }

    /**
     * @param array<string, mixed> $step
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function applyPrimary(
        array $step,
        array $rows,
        ?string $automationTenantId,
        int $maxItems,
    ): array {
        $stepId = (string) $step['id'];
        $ctx = $rows[0]['__ctx'] ?? [];
        if (!is_array($ctx)) {
            $ctx = [];
        }

        // Seed single entity from trigger when types match (any primary-like source)
        $entityType = (string) ($step['entityType'] ?? '');
        if (
            $entityType !== '' &&
            !empty($ctx['entityType']) &&
            !empty($ctx['entityId']) &&
            (string) $ctx['entityType'] === $entityType &&
            empty($step['forceQuery'])
        ) {
            $ent = $this->entityManager->getEntityById($entityType, (string) $ctx['entityId']);
            if ($ent) {
                $tenantId = $this->resolveTenantId($ent, $automationTenantId);

                return [[
                    'entities' => [$stepId => $ent],
                    'targetType' => $entityType,
                    'targetId' => $ent->getId(),
                    '__tenantId' => $tenantId,
                    'payload' => [$stepId => $this->entityPayload($ent)],
                    '__ctx' => $ctx,
                ]];
            }
        }

        $members = $this->resolveMembers($step, $rows[0] ?? [], null, $automationTenantId, $maxItems);
        $out = [];

        foreach ($members as $member) {
            $row = [
                'entities' => [],
                'payload' => [],
                '__tenantId' => $automationTenantId,
                '__ctx' => $ctx,
            ];

            $row['payload'][$stepId] = $this->memberPayload($member);

            if ($member['kind'] === 'entity' && $member['entity'] instanceof Entity) {
                $ent = $member['entity'];
                $row['entities'][$stepId] = $ent;
                $row['targetType'] = $ent->getEntityType();
                $row['targetId'] = $ent->getId();
                $row['__tenantId'] = $this->resolveTenantId($ent, $automationTenantId);
            } else {
                $row['payload'][$stepId . 'Value'] = $member['value'] ?? null;
            }

            $out[] = $row;
            if (count($out) >= $maxItems) {
                break;
            }
        }

        return $out;
    }

    /**
     * Resolve members for a step (entities or scalar values).
     *
     * @param array<string, mixed> $step
     * @param array<string, mixed> $row
     * @return list<array{kind: string, entity?: Entity, value?: mixed}>
     */
    private function resolveMembers(
        array $step,
        array $row,
        ?Entity $parent,
        ?string $tenantId,
        int $limit,
    ): array {
        $source = (string) ($step['source'] ?? 'query');
        $stepId = (string) ($step['id'] ?? '?');

        return match ($source) {
            'relation' => $this->membersFromRelation($step, $parent, $tenantId, $limit),
            'linkMultiple' => $this->membersFromLinkMultiple($step, $parent, $tenantId, $limit),
            'ids' => $this->membersFromIds($step, $row, $tenantId, $limit),
            'payload' => $this->membersFromPayload($step, $row, $tenantId, $limit),
            'report' => $this->membersFromReport($step, $tenantId, $limit),
            'query' => $this->membersFromQuery($step, $parent, $tenantId, $limit),
            default => throw new Error("Map step {$stepId}: unknown source '{$source}'."),
        };
    }

    /**
     * @param array<string, mixed> $step
     * @return list<array{kind: string, entity?: Entity, value?: mixed}>
     */
    private function membersFromQuery(
        array $step,
        ?Entity $parent,
        ?string $tenantId,
        int $limit,
    ): array {
        $entityType = (string) ($step['entityType'] ?? '');
        if ($entityType === '') {
            throw new Error('source=query requires entityType.');
        }

        $where = $this->normalizeWhere($step['where'] ?? []);

        if ($parent && empty($step['ignoreParent'])) {
            $fk = (string) ($step['foreignKey'] ?? '');
            if ($fk === '') {
                if ($parent->getEntityType() === 'Tenant' && $this->entityHasTenant($entityType)) {
                    $fk = 'tenantId';
                } else {
                    $fk = lcfirst($parent->getEntityType()) . 'Id';
                }
            }
            $where[$fk] = $parent->getId();
        }

        if ($tenantId && $entityType !== 'Tenant' && $this->entityHasTenant($entityType)) {
            $where['tenantId'] = $tenantId;
        }

        try {
            $found = $this->entityManager
                ->getRDBRepository($entityType)
                ->where($where)
                ->limit(0, $limit)
                ->find();
        } catch (Throwable $e) {
            $this->log->error('MapMaterializer query: ' . $e->getMessage());

            return [];
        }

        return $this->entitiesToMembers($found, $tenantId, $entityType);
    }

    /**
     * @param array<string, mixed> $step
     * @return list<array{kind: string, entity?: Entity, value?: mixed}>
     */
    private function membersFromRelation(
        array $step,
        ?Entity $parent,
        ?string $tenantId,
        int $limit,
    ): array {
        if (!$parent) {
            return [];
        }

        $entityType = (string) ($step['entityType'] ?? '');
        $relation = (string) ($step['relation'] ?? '');
        $where = $this->normalizeWhere($step['where'] ?? []);

        try {
            if ($relation !== '') {
                $rel = $this->entityManager
                    ->getRDBRepository($parent->getEntityType())
                    ->getRelation($parent, $relation);

                if ($where !== []) {
                    $rel->where($where);
                }

                $found = $rel->limit(0, $limit)->find();
            } else {
                // FK convention under relation source
                return $this->membersFromQuery($step, $parent, $tenantId, $limit);
            }
        } catch (Throwable $e) {
            $this->log->error('MapMaterializer relation: ' . $e->getMessage());

            return [];
        }

        return $this->entitiesToMembers($found, $tenantId, $entityType !== '' ? $entityType : null);
    }

    /**
     * @param array<string, mixed> $step
     * @return list<array{kind: string, entity?: Entity, value?: mixed}>
     */
    private function membersFromLinkMultiple(
        array $step,
        ?Entity $parent,
        ?string $tenantId,
        int $limit,
    ): array {
        if (!$parent) {
            return [];
        }

        $link = (string) ($step['link'] ?? $step['linkMultiple'] ?? '');
        $entityType = (string) ($step['entityType'] ?? '');
        if ($link === '') {
            throw new Error('source=linkMultiple requires link.');
        }

        try {
            $ids = $parent->getLinkMultipleIdList($link) ?: [];
        } catch (Throwable) {
            $idsAttr = $link . 'Ids';
            $ids = $parent->get($idsAttr) ?: [];
        }

        if (!is_array($ids)) {
            return [];
        }

        if ($entityType === '') {
            // Try link defs
            try {
                $entityType = $this->entityManager
                    ->getDefs()
                    ->getEntity($parent->getEntityType())
                    ->getRelation($link)
                    ->getForeignEntityType();
            } catch (Throwable) {
                throw new Error('source=linkMultiple requires entityType when link target unknown.');
            }
        }

        $members = [];
        foreach (array_slice($ids, 0, $limit) as $id) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            $ent = $this->entityManager->getEntityById($entityType, $id);
            if (!$ent) {
                continue;
            }
            if (!$this->tenantOk($ent, $tenantId)) {
                continue;
            }
            $members[] = ['kind' => 'entity', 'entity' => $ent];
        }

        return $members;
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $row
     * @return list<array{kind: string, entity?: Entity, value?: mixed}>
     */
    private function membersFromIds(
        array $step,
        array $row,
        ?string $tenantId,
        int $limit,
    ): array {
        $entityType = (string) ($step['entityType'] ?? '');
        if ($entityType === '') {
            throw new Error('source=ids requires entityType.');
        }

        $ids = $step['ids'] ?? null;
        if ($ids === null && !empty($step['idsPath'])) {
            $ids = $this->pathGet($row, (string) $step['idsPath']);
        }

        if ($ids instanceof \stdClass) {
            $ids = (array) $ids;
        }
        if (!is_array($ids)) {
            return [];
        }

        $members = [];
        foreach (array_slice(array_values($ids), 0, $limit) as $id) {
            if (is_array($id) || $id instanceof \stdClass) {
                $id = is_array($id) ? ($id['id'] ?? null) : ($id->id ?? null);
            }
            $id = (string) ($id ?? '');
            if ($id === '') {
                continue;
            }
            $ent = $this->entityManager->getEntityById($entityType, $id);
            if (!$ent || !$this->tenantOk($ent, $tenantId)) {
                continue;
            }
            $members[] = ['kind' => 'entity', 'entity' => $ent];
        }

        return $members;
    }

    /**
     * Iterate payload array (entities-by-shape or scalar values).
     *
     * @param array<string, mixed> $step
     * @param array<string, mixed> $row
     * @return list<array{kind: string, entity?: Entity, value?: mixed}>
     */
    private function membersFromPayload(
        array $step,
        array $row,
        ?string $tenantId,
        int $limit,
    ): array {
        $path = (string) ($step['payloadPath'] ?? '');
        if ($path === '') {
            throw new Error('source=payload requires payloadPath.');
        }

        $list = $this->pathGet($row, $path);
        if ($list instanceof \stdClass) {
            $list = (array) $list;
        }
        if (!is_array($list)) {
            return [];
        }

        $entityType = (string) ($step['entityType'] ?? '');
        $members = [];

        foreach (array_slice(array_values($list), 0, $limit) as $item) {
            if ($item instanceof Entity) {
                if ($this->tenantOk($item, $tenantId)) {
                    $members[] = ['kind' => 'entity', 'entity' => $item];
                }
                continue;
            }

            if ($item instanceof \stdClass) {
                $item = (array) $item;
            }

            if (is_array($item) && !empty($item['id'])) {
                $type = (string) ($item['entityType'] ?? $entityType);
                if ($type !== '') {
                    $ent = $this->entityManager->getEntityById($type, (string) $item['id']);
                    if ($ent && $this->tenantOk($ent, $tenantId)) {
                        $members[] = ['kind' => 'entity', 'entity' => $ent];
                        continue;
                    }
                }
            }

            if (is_string($item) && $entityType !== '' && preg_match('/^[a-f0-9]{17}$/i', $item)) {
                $ent = $this->entityManager->getEntityById($entityType, $item);
                if ($ent && $this->tenantOk($ent, $tenantId)) {
                    $members[] = ['kind' => 'entity', 'entity' => $ent];
                    continue;
                }
            }

            // Scalar / free-form value (loop without changing target entity)
            $members[] = ['kind' => 'value', 'value' => $item];
        }

        return $members;
    }

    /**
     * @param array<string, mixed> $step
     * @return list<array{kind: string, entity?: Entity, value?: mixed}>
     */
    private function membersFromReport(
        array $step,
        ?string $tenantId,
        int $limit,
    ): array {
        $reportId = (string) ($step['reportId'] ?? '');
        $entityType = (string) ($step['entityType'] ?? '');
        $stepId = (string) ($step['id'] ?? 'report');

        if ($reportId === '') {
            throw new Error("Map step {$stepId}: reportId required for source=report.");
        }
        if ($entityType === '') {
            throw new Error("Map step {$stepId}: entityType required for source=report.");
        }

        $class = 'Espo\\Modules\\Advanced\\Tools\\Report\\Service';
        if (!class_exists($class)) {
            throw new Error('Report service unavailable (Advanced module).');
        }

        $maxRows = min($limit, (int) ($step['maxRows'] ?? $limit));

        try {
            $service = $this->injectableFactory->create($class);
            $searchParams = SearchParams::fromRaw(['maxSize' => $maxRows]);
            $result = $service->runList($reportId, $searchParams, null);
            $collection = $result->getCollection();
        } catch (Throwable $e) {
            throw new Error("Map step {$stepId} report failed: " . $e->getMessage());
        }

        $list = [];
        foreach ($collection as $ent) {
            if (!$ent instanceof Entity) {
                continue;
            }
            if ($ent->getEntityType() !== $entityType) {
                $loaded = $this->entityManager->getEntityById($entityType, $ent->getId());
                if (!$loaded) {
                    continue;
                }
                $ent = $loaded;
            }
            $list[] = $ent;
            if (count($list) >= $maxRows) {
                break;
            }
        }

        return $this->entitiesToMembers($list, $tenantId, $entityType);
    }

    /**
     * @param iterable<Entity>|array<int, Entity> $found
     * @return list<array{kind: string, entity?: Entity, value?: mixed}>
     */
    private function entitiesToMembers(iterable $found, ?string $tenantId, ?string $expectedType): array
    {
        $members = [];
        foreach ($found as $child) {
            if (!$child instanceof Entity) {
                continue;
            }
            if ($expectedType && $child->getEntityType() !== $expectedType) {
                // keep lenient
            }
            if (!$this->tenantOk($child, $tenantId)) {
                continue;
            }
            $members[] = ['kind' => 'entity', 'entity' => $child];
        }

        return $members;
    }

    private function tenantOk(Entity $entity, ?string $tenantId): bool
    {
        if (!$tenantId) {
            return true;
        }

        $type = $entity->getEntityType();

        if ($type === 'Tenant') {
            return $entity->getId() === $tenantId;
        }

        if ($type === 'User') {
            return $this->tenantGuard->userBelongsToTenant($entity->getId(), $tenantId);
        }

        try {
            if ($entity->hasAttribute('tenantId') && $entity->get('tenantId')) {
                $this->tenantGuard->assertEntityTenant($entity, $tenantId, 'map-member');
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param array{kind: string, entity?: Entity, value?: mixed} $member
     * @return array<string, mixed>
     */
    private function memberPayload(array $member): array
    {
        if (($member['kind'] ?? '') === 'entity' && ($member['entity'] ?? null) instanceof Entity) {
            return $this->entityPayload($member['entity']);
        }

        return [
            'value' => $member['value'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entityPayload(Entity $ent): array
    {
        return [
            'id' => $ent->getId(),
            'name' => $ent->get('name'),
            'entityType' => $ent->getEntityType(),
        ];
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $row
     * @param list<array{kind: string, entity?: Entity, value?: mixed}> $members
     * @param list<array<string, mixed>> $next
     */
    private function applyGroupBy(
        array $step,
        string $stepId,
        array $row,
        array $members,
        ?string $parentTenant,
        array &$next,
        int $maxItems,
    ): void {
        // groupBy: string | string[] of entity fields
        $groupFields = $step['groupBy'] ?? 'assignedUserId';
        if (is_string($groupFields)) {
            $groupFields = [$groupFields];
        }
        if (!is_array($groupFields) || $groupFields === []) {
            $groupFields = ['assignedUserId'];
        }
        $groupFields = array_values(array_filter(array_map('strval', $groupFields), fn($f) => $f !== ''));

        // timeBucket: { field, size: "1 hour", timezone? } — floors member field into window
        $timeBucket = $step['timeBucket'] ?? null;
        if ($timeBucket instanceof \stdClass) {
            $timeBucket = (array) $timeBucket;
        }
        if (!is_array($timeBucket)) {
            $timeBucket = null;
        }

        // aggregates: [{ op: count|sum|min|max|collectIds|avg, field?, as }]
        $aggregates = $step['aggregates'] ?? null;
        if ($aggregates instanceof \stdClass) {
            $aggregates = (array) $aggregates;
        }
        if (!is_array($aggregates)) {
            $aggregates = [
                ['op' => 'count', 'as' => 'count'],
                ['op' => 'collectIds', 'as' => 'ids'],
            ];
        }

        $groups = [];

        foreach ($members as $member) {
            if (($member['kind'] ?? '') !== 'entity' || !($member['entity'] instanceof Entity)) {
                continue;
            }
            $child = $member['entity'];

            $parts = [];
            foreach ($groupFields as $gf) {
                $parts[] = (string) ($child->get($gf) ?? '_null');
            }

            $bucketStart = null;
            $bucketEnd = null;
            if ($timeBucket !== null) {
                $tbField = (string) ($timeBucket['field'] ?? 'createdAt');
                $tbSize = (string) ($timeBucket['size'] ?? '1 hour');
                $tbTz = (string) ($timeBucket['timezone'] ?? 'UTC');
                $rawTs = $child->get($tbField);
                if ($rawTs) {
                    $floored = $this->floorTimeBucket((string) $rawTs, $tbSize, $tbTz);
                    if ($floored !== null) {
                        $bucketStart = $floored['start'];
                        $bucketEnd = $floored['end'];
                        $parts[] = $bucketStart;
                    } else {
                        $parts[] = '_nobucket';
                    }
                } else {
                    $parts[] = '_nobucket';
                }
            }

            $gKey = implode('|', $parts);
            if (!isset($groups[$gKey])) {
                $groups[$gKey] = [
                    'entities' => [],
                    'keyParts' => $parts,
                    'bucketStart' => $bucketStart,
                    'bucketEnd' => $bucketEnd,
                ];
            }
            $groups[$gKey]['entities'][] = $child;
        }

        foreach ($groups as $gKey => $group) {
            $list = $group['entities'];
            $first = $list[0];
            $copy = $row;
            // Prefer groupTarget load by first group field value when single key field
            $targetKey = (string) ($group['keyParts'][0] ?? $gKey);
            $target = $this->resolveGroupTarget($step, $targetKey, $list);
            if ($target) {
                $copy['entities'][$stepId] = $target;
                $copy['targetType'] = $target->getEntityType();
                $copy['targetId'] = $target->getId();
            }

            $aggPayload = [
                'groupKey' => (string) $gKey,
                'groupFields' => $groupFields,
            ];
            if ($group['bucketStart'] !== null) {
                $aggPayload['bucketStart'] = $group['bucketStart'];
                $aggPayload['bucketEnd'] = $group['bucketEnd'];
            }

            foreach ($aggregates as $agg) {
                if ($agg instanceof \stdClass) {
                    $agg = (array) $agg;
                }
                if (!is_array($agg)) {
                    continue;
                }
                $op = strtolower((string) ($agg['op'] ?? 'count'));
                $as = (string) ($agg['as'] ?? $op);
                $field = isset($agg['field']) ? (string) $agg['field'] : null;
                $aggPayload[$as] = $this->reduceAggregate($op, $list, $field);
            }

            // always expose count + ids for convenience when not custom-only
            if (!array_key_exists('count', $aggPayload)) {
                $aggPayload['count'] = count($list);
            }
            if (!array_key_exists('ids', $aggPayload)) {
                $aggPayload['ids'] = array_map(fn(Entity $e) => $e->getId(), $list);
            }

            $copy['payload'][$stepId] = $aggPayload;
            $copy['__tenantId'] = $this->resolveTenantId($first, $parentTenant);
            $next[] = $copy;

            if (count($next) >= $maxItems) {
                return;
            }
        }
    }

    /**
     * @param list<Entity> $list
     */
    private function reduceAggregate(string $op, array $list, ?string $field): mixed
    {
        return match ($op) {
            'count' => count($list),
            'collectids', 'collect_ids', 'ids' => array_map(fn(Entity $e) => $e->getId(), $list),
            'sum' => $this->numericReduce($list, $field, 'sum'),
            'min' => $this->numericReduce($list, $field, 'min'),
            'max' => $this->numericReduce($list, $field, 'max'),
            'avg', 'average' => $this->numericReduce($list, $field, 'avg'),
            default => count($list),
        };
    }

    /**
     * @param list<Entity> $list
     */
    private function numericReduce(array $list, ?string $field, string $op): float|int|null
    {
        if ($field === null || $field === '') {
            return null;
        }

        $vals = [];
        foreach ($list as $e) {
            $v = $e->get($field);
            if ($v === null || $v === '') {
                continue;
            }
            if (!is_numeric($v)) {
                continue;
            }
            $vals[] = (float) $v;
        }

        if ($vals === []) {
            return null;
        }

        return match ($op) {
            'sum' => array_sum($vals),
            'min' => min($vals),
            'max' => max($vals),
            'avg' => array_sum($vals) / count($vals),
            default => null,
        };
    }

    /**
     * Floor a datetime string into a fixed bucket window.
     *
     * @return array{start: string, end: string}|null
     */
    private function floorTimeBucket(string $datetime, string $size, string $timezone): ?array
    {
        try {
            $tz = new \DateTimeZone($timezone !== '' ? $timezone : 'UTC');
            $dt = new \DateTimeImmutable($datetime, $tz);
        } catch (Throwable) {
            try {
                $dt = new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
                $tz = new \DateTimeZone('UTC');
            } catch (Throwable) {
                return null;
            }
        }

        $size = trim(strtolower($size));
        $seconds = 3600;
        if (preg_match('/^(\d+)\s*(minute|minutes|hour|hours|day|days)$/', $size, $m)) {
            $n = max(1, (int) $m[1]);
            $unit = $m[2];
            $seconds = match (true) {
                str_starts_with($unit, 'minute') => $n * 60,
                str_starts_with($unit, 'hour') => $n * 3600,
                str_starts_with($unit, 'day') => $n * 86400,
                default => 3600,
            };
        }

        $ts = $dt->getTimestamp();
        $floor = (int) (floor($ts / $seconds) * $seconds);
        $start = (new \DateTimeImmutable('@' . $floor))->setTimezone($tz);
        $end = $start->modify("+{$seconds} seconds");

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<Entity> $list
     */
    private function resolveGroupTarget(array $step, string $groupKey, array $list): ?Entity
    {
        $targetEntityType = (string) ($step['groupTargetEntityType'] ?? '');
        if ($targetEntityType !== '' && $groupKey !== '_null') {
            $ent = $this->entityManager->getEntityById($targetEntityType, $groupKey);

            return $ent ?: null;
        }

        return $list[0] ?? null;
    }

    /**
     * Dot-path into row: "payload.foo.bar", "entities.t", or bare payload key.
     */
    private function pathGet(array $row, string $path): mixed
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        // Allow "payload.xxx" or just "xxx" inside payload
        $parts = explode('.', $path);
        $cur = $row;

        if ($parts[0] !== 'payload' && $parts[0] !== 'entities' && $parts[0] !== '__ctx') {
            array_unshift($parts, 'payload');
        }

        foreach ($parts as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
                continue;
            }
            if ($cur instanceof \stdClass && isset($cur->$part)) {
                $cur = $cur->$part;
                continue;
            }
            if ($cur instanceof Entity) {
                $cur = $cur->get($part);
                continue;
            }

            return null;
        }

        return $cur;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $triggerPayload
     * @return list<array{targetType: ?string, targetId: ?string, tenantId: ?string, payload: array<string, mixed>}>
     */
    private function finalizeRows(
        array $rows,
        ?string $automationTenantId,
        array $triggerPayload,
        int $maxItems,
    ): array {
        $items = [];
        foreach ($rows as $row) {
            $tenantId = $row['__tenantId'] ?? $automationTenantId;
            $targetType = $row['targetType'] ?? null;
            $targetId = $row['targetId'] ?? null;

            if (isset($row['entities']) && is_array($row['entities'])) {
                $last = null;
                foreach ($row['entities'] as $ent) {
                    if ($ent instanceof Entity) {
                        $last = $ent;
                    }
                }
                if ($last) {
                    $targetType = $last->getEntityType();
                    $targetId = $last->getId();
                }
            }

            $payload = $row['payload'] ?? [];
            if (!is_array($payload)) {
                $payload = [];
            }
            $payload['_trigger'] = $triggerPayload;

            // Skip orphan value-only rows with no target
            if (!$targetType || !$targetId) {
                continue;
            }

            $items[] = [
                'targetType' => $targetType,
                'targetId' => $targetId,
                'tenantId' => $tenantId ? (string) $tenantId : null,
                'payload' => $payload,
            ];

            if (count($items) >= $maxItems) {
                break;
            }
        }

        return $items;
    }

    private function resolveTenantId(Entity $entity, ?string $fallback): ?string
    {
        if ($entity->getEntityType() === 'Tenant') {
            return $entity->getId();
        }

        if ($entity->hasAttribute('tenantId') && $entity->get('tenantId')) {
            return (string) $entity->get('tenantId');
        }

        try {
            return $this->tenantGuard->requireTenantId($entity, $entity->getEntityType());
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function entityHasTenant(string $entityType): bool
    {
        try {
            return $this->entityManager->getDefs()->getEntity($entityType)->hasAttribute('tenantId');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeWhere(mixed $where): array
    {
        if ($where instanceof \stdClass) {
            $where = (array) $where;
        }
        if (!is_array($where)) {
            return [];
        }
        unset($where['deleted']);

        return $where;
    }
}
