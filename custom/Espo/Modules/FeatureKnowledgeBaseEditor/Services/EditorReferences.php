<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Text\Filter\Data as TextFilterData;
use Espo\Core\Select\Text\FilterFactory;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;

class EditorReferences
{
    private ?array $supportedTypes = null;

    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private Acl $acl,
        private Metadata $metadata,
        private User $user,
        private FilterFactory $textFilterFactory,
    ) {}

    private function allowedType(string $type): bool
    {
        $this->supportedTypes ??= (new Scopes($this->metadata))->all();
        return in_array($type, $this->supportedTypes, true) && $this->metadata->get(['scopes', $type, 'entity']) &&
            $this->acl->checkScope($type, 'read') && $this->acl->checkField($type, 'name') &&
            ($type !== 'User' || $this->acl->getPermissionLevel('mention') !== 'no');
    }

    private function allowedRecord(Entity $entity): bool
    {
        return $this->acl->checkEntityRead($entity) &&
            ($entity->getEntityType() !== 'User' ||
            ($entity->get('isActive') && $this->acl->checkUserPermission($entity->getId(), 'mention')));
    }

    public function search(string $query, ?array $nextActionParent = null): array
    {
        if (strlen($query) > 240) throw new BadRequest('Query too long.');
        $query = trim($query);
        $results = [];
        $union = UnionBuilder::create()->all()->order('lastViewedNumber', 'DESC')->order('rank')->order('name');
        $queries = [];
        $historyQuery = SelectBuilder::create()->from('ActionHistoryRecord')
            ->select(['targetType', 'targetId', ['MAX:number', 'lastViewedNumber']])
            ->where(['userId' => $this->user->getId(), 'action' => ['read', 'create']])
            ->group(['targetType', 'targetId'])->build();
        $history = [];
        foreach ($this->em->getQueryExecutor()->execute($historyQuery)->fetchAll() as $row) {
            $history[$row['targetType']][$row['targetId']] = (int) $row['lastViewedNumber'];
        }
        $this->supportedTypes ??= (new Scopes($this->metadata))->all();
        foreach ($this->supportedTypes as $rank => $type) {
            if ($nextActionParent && !in_array($type, ['Task', 'Meeting', 'Call'], true)) continue;
            if (!$this->allowedType($type)) continue;
            if ($nextActionParent && (!$this->acl->checkField($type, 'status') || !$this->acl->checkField($type, 'parent'))) continue;
            try {
                $builder = $this->select->create()->from($type)->withStrictAccessControl();
                if ($type === 'ChatwootAccountUserMembership') {
                    $builder->withPrimaryFilter('aiOnly');
                }
                if ($type === 'User') {
                    $builder->withPrimaryFilter('active');
                    if ($this->acl->getPermissionLevel('mention') === 'team') $builder->withBoolFilter('onlyMyTeam');
                }
                // Hydration needs ACL, but must not repeat the expensive text search.
                $queryBuilder = $builder->buildQueryBuilder();
                if ($nextActionParent) {
                    $parents = [[
                        'parentType' => $nextActionParent['type'], 'parentId' => $nextActionParent['id'],
                    ]];
                    if ($this->acl->checkScope($type, 'edit') && $this->acl->checkField($type, 'parent', 'edit')) {
                        $parents[] = ['parentId' => null];
                    }
                    $queryBuilder->where(['OR' => $parents]);
                    $queryBuilder->where($type === 'Task' ? [
                        'status!=' => $this->metadata->get(['entityDefs', 'Task', 'fields', 'status', 'notActualOptions']) ?? [],
                    ] : [
                        'status' => $this->metadata->get(['scopes', $type, 'activityStatusList']) ?? [],
                    ]);
                }
                $queries[$type] = $queryBuilder->build();
                $queryBuilder->order('name')->limit(0, 10);
                $this->textFilterFactory->create($type, $this->user)
                    ->apply($queryBuilder, TextFilterData::create($query, ['name']));
                $columns = [
                    'id', 'name', ['VALUE:' . $type, 'entityType'], [(string) $rank, 'rank'],
                ];
                $union->query($queryBuilder->select([...$columns, ['0', 'lastViewedNumber']])->build());
                if (!empty($history[$type])) {
                    $mapping = [Expr::column('id')];
                    foreach ($history[$type] as $id => $number) array_push($mapping, $id, $number);
                    $mapping[] = 0;
                    $lastViewed = Expr::map(...$mapping);
                    // Only sort the user's viewed IDs, never the whole matching table.
                    $union->query($queryBuilder->where(['id' => array_keys($history[$type])])
                        ->order([])->order(Expr::alias('lastViewedNumber'), 'DESC')->order('name')
                        ->select([...$columns, [$lastViewed, 'lastViewedNumber']])->build());
                }
            } catch (Forbidden) { continue; }
        }
        if (!$queries) return [];
        $candidates = $this->em->getQueryExecutor()->execute($union->build())->fetchAll();
        $rows = [];
        $groups = [];
        foreach ($candidates as $row) {
            $type = $row['entityType'];
            $ids = $groups[$type] ?? [];
            if (count($ids) >= 10 || in_array($row['id'], $ids, true)) continue;
            $groups[$type][] = $row['id'];
            $rows[] = $row;
        }
        $entities = [];
        foreach ($groups as $type => $ids) {
            // Hydrate full records only for matches, keeping the original ACL
            // query and all attributes used by custom record access checkers.
            $sql = $this->em->getQueryBuilder()->select()->clone($queries[$type])
                ->where(['id' => $ids])->limit(0, 10)->build();
            foreach ($this->em->getRDBRepository($type)->clone($sql)->find() as $entity) {
                if (!$this->allowedRecord($entity)) continue;
                if ($nextActionParent && !$entity->get('parentId') && !$this->acl->checkEntityEdit($entity)) continue;
                $entities[$type][$entity->getId()] = $entity;
            }
        }
        foreach ($rows as $row) {
            $type = $row['entityType'];
            $entity = $entities[$type][$row['id']] ?? null;
            if (!$entity) continue;
            $results[] = [
                'kind' => 'record', 'entityType' => $type, 'recordId' => $entity->getId(), 'label' => $entity->get('name'),
                'recentlyViewed' => (bool) $row['lastViewedNumber'],
                ...$this->visual($entity),
            ];
        }
        return $results;
    }

    /** One ACL-filtered select per entity group, never one lookup per chip. */
    public function resolve(array $references): array
    {
        if (count($references) > 200) throw new BadRequest('Too many references.');
        $groups = [];
        $results = [];
        foreach ($references as $reference) {
            $ref = (array) $reference;
            if (!References::valid($ref)) throw new BadRequest('Invalid reference.');
            if ($ref['kind'] !== 'record') continue;
            $ref = ['kind' => 'record', 'entityType' => $ref['entityType'], 'recordId' => $ref['recordId']];
            $results[References::url($ref)] = [...$ref, 'available' => false, 'label' => 'Unavailable reference'];
            $groups[$ref['entityType']][] = $ref['recordId'];
        }
        foreach ($groups as $type => $ids) {
            if (!$this->allowedType($type)) continue;
            try {
                $sql = $this->select->create()->from($type)->withStrictAccessControl()->buildQueryBuilder()
                    ->where(['id' => array_values(array_unique($ids))])->build();
                foreach ($this->em->getRDBRepository($type)->clone($sql)->find() as $entity) {
                    if (!$this->allowedRecord($entity)) continue;
                    $ref = ['kind' => 'record', 'entityType' => $type, 'recordId' => $entity->getId()];
                    $results[References::url($ref)] = [
                        ...$ref, 'available' => true, 'label' => $entity->get('name'), ...$this->visual($entity),
                    ];
                }
            } catch (Forbidden) { continue; }
        }
        return array_values($results);
    }

    private function visual(Entity $entity): array
    {
        $type = $entity->getEntityType();
        $iconAttribute = $this->metadata->get(['clientDefs', $type, 'recordIconAttribute']);
        $avatarReadable = $this->metadata->get(['entityDefs', $type, 'fields', 'avatar']) &&
            $this->acl->checkField($type, 'avatar');
        $urlReadable = $this->metadata->get(['entityDefs', $type, 'fields', 'avatarUrl']) &&
            $this->acl->checkField($type, 'avatarUrl');

        return [
            'avatarId' => $avatarReadable ? $entity->get('avatarId') : null,
            'avatarUrl' => $urlReadable ? $entity->get('avatarUrl') : null,
            'icon' => $iconAttribute && $this->acl->checkField($type, $iconAttribute)
                ? $entity->get($iconAttribute) : null,
        ];
    }

    public function context(array $ref, array $bindings): ?array
    {
        $key = $ref['key'];
        $id = $key === 'primaryContact' ? ($bindings['primaryContactId'] ?? null) : ($bindings['opportunityId'] ?? null);
        $type = $key === 'primaryContact' ? 'Contact' : 'Opportunity';
        $record = ['kind' => 'record', 'entityType' => $type, 'recordId' => $id];
        if (!References::valid($record)) return null;
        $resolved = $this->resolve([$record])[0] ?? null;
        if (!$resolved || !$resolved['available']) return null;
        if ($key !== 'opportunityOwner') return $resolved;
        if (!$this->acl->checkField('Opportunity', 'assignedUser')) return null;
        $opportunity = $this->em->getEntityById('Opportunity', $id);
        $owner = $opportunity?->get('assignedUserId');
        if (!$owner) return null;
        return $this->resolve([['kind' => 'record', 'entityType' => 'User', 'recordId' => $owner]])[0] ?? null;
    }
}
