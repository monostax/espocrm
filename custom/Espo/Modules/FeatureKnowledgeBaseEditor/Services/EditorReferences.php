<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Text\FilterFactory;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\Expression\Util as ExpressionUtil;
use Espo\ORM\Query\Part\Where\Comparison as Cmp;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\ReferenceSearch;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;

class EditorReferences
{
    /** User mentions and AI agents (ChatwootAccountUserMembership, aiOnly filter). */
    private const PRIORITY_TYPES = ['User', 'ChatwootAccountUserMembership'];
    /** ActionHistoryRecord action for a record reference the user added to editor content. */
    public const MENTION_ACTION = 'mention';

    private ?array $supportedTypes = null;

    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private Acl $acl,
        private Metadata $metadata,
        private User $user,
        private FilterFactory $textFilterFactory,
        private ReferenceSearch $referenceSearch,
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
        $this->supportedTypes ??= (new Scopes($this->metadata))->all();
        $search = $this->referenceSearch->parse($query, $this->supportedTypes);
        $results = [];
        // What the user mentioned recently comes first; then people and AI agents, which
        // are what @-mentions are mostly for, before records.
        $union = UnionBuilder::create()->all()->order('lastMentionedNumber', 'DESC')->order('priority')
            ->order('lastViewedNumber', 'DESC')->order('rank')->order('name');
        $queries = [];
        $historyTypes = $nextActionParent
            ? array_values(array_intersect($search['types'], ['Task', 'Meeting', 'Call'])) : $search['types'];
        $viewed = $this->history(['read', 'create'], $historyTypes, 2000);
        $mentioned = $this->history([self::MENTION_ACTION], $historyTypes, 200);
        $this->supportedTypes ??= (new Scopes($this->metadata))->all();
        foreach ($this->supportedTypes as $rank => $type) {
            if (!in_array($type, $search['types'], true)) continue;
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
                $fullTextColumns = $this->metadata->get(['entityDefs', $type, 'indexes', 'editorReferenceName', 'columns']);
                $fullTextTerms = array_values(array_filter(array_map(ReferenceSearch::fullTextTerm(...), $search['terms'])));
                if ($fullTextColumns && $fullTextTerms) {
                    // Keep strict ACL on the indexed query. Boolean operators are
                    // generated only from plain words, never accepted from input.
                    $queryBuilder->where(Cmp::greater(ExpressionUtil::composeFunction('MATCH_BOOLEAN',
                        ...[...array_map(Expr::column(...), $fullTextColumns), implode(' ', $fullTextTerms)]), 0));
                }
                foreach ($search['terms'] as $term) {
                    if ($fullTextColumns && ReferenceSearch::fullTextTerm($term) !== null) continue;
                    // Short words, stopwords and punctuation remain searchable.
                    $queryBuilder->where(Cmp::equal(ExpressionUtil::composeFunction('REGEXP',
                        Expr::column('name'), ReferenceSearch::wordPattern($term)), true));
                }
                $columns = [
                    'id', 'name', ['VALUE:' . $type, 'entityType'],
                    [in_array($type, self::PRIORITY_TYPES, true) ? '0' : '1', 'priority'], [(string) $rank, 'rank'],
                ];
                $union->query($queryBuilder
                    ->select([...$columns, ['0', 'lastMentionedNumber'], ['0', 'lastViewedNumber']])->build());
                $recent = ($mentioned[$type] ?? []) + ($viewed[$type] ?? []);
                if ($recent) {
                    // Only sort the user's mentioned/viewed IDs, never the whole matching table.
                    $union->query($queryBuilder->where(['id' => array_keys($recent)])
                        ->order([])->order(Expr::alias('lastMentionedNumber'), 'DESC')
                        ->order(Expr::alias('lastViewedNumber'), 'DESC')->order('name')
                        ->select([...$columns, [self::lastNumber($mentioned[$type] ?? []), 'lastMentionedNumber'],
                            [self::lastNumber($viewed[$type] ?? []), 'lastViewedNumber']])->build());
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
                'recentlyMentioned' => (bool) $row['lastMentionedNumber'],
                'recentlyViewed' => (bool) $row['lastViewedNumber'],
                ...$this->visual($entity),
            ];
        }
        return $results;
    }

    /**
     * Latest history number per target, from the user's newest rows only: grouping a user's
     * whole history (50k+ rows) and inlining every id into each union branch cost seconds per keystroke.
     */
    private function history(array $actions, array $types, int $limit): array
    {
        $recent = SelectBuilder::create()->from('ActionHistoryRecord')
            ->select(['targetType', 'targetId', 'number'])
            ->where(['userId' => $this->user->getId(), 'deleted' => false, 'action' => $actions, 'targetType' => $types])
            ->order('number', 'DESC')->limit(0, $limit)->build();
        $query = SelectBuilder::create()->fromQuery($recent, 'recentView')
            ->select([
                [Expr::alias('recentView.targetType'), 'targetType'],
                [Expr::alias('recentView.targetId'), 'targetId'],
                [Expr::max(Expr::alias('recentView.number')), 'lastNumber'],
            ])
            ->group(Expr::alias('recentView.targetType'))->group(Expr::alias('recentView.targetId'))->build();
        $history = [];
        foreach ($this->em->getQueryExecutor()->execute($query)->fetchAll() as $row) {
            $history[$row['targetType']][$row['targetId']] = (int) $row['lastNumber'];
        }
        return $history;
    }

    private static function lastNumber(array $numbers): Expr|string
    {
        if (!$numbers) return '0';
        $mapping = [Expr::column('id')];
        foreach ($numbers as $id => $number) array_push($mapping, $id, $number);
        $mapping[] = 0;
        return Expr::map(...$mapping);
    }

    /**
     * The picker's "Recent" tab in one request: the user's ten latest mentioned records, then
     * the ten latest viewed ones not already listed. The tab used to chain LastViewed, resolve
     * and recentMentions; each CRM request costs a full bootstrap, so this resolves both at once.
     */
    public function recent(): array
    {
        $mentioned = array_slice($this->latest([self::MENTION_ACTION]), 0, 10, true);
        $viewed = array_slice(array_diff_key($this->latest(['read', 'create']), $mentioned), 0, 10, true);
        $results = [];
        // resolve() keeps the input order and applies the same ACL as every other reference.
        foreach ($this->resolve([...array_values($mentioned), ...array_values($viewed)]) as $ref) {
            if (!$ref['available']) continue;
            $wasMentioned = isset($mentioned[References::url($ref)]);
            $results[] = [...$ref, 'recentlyMentioned' => $wasMentioned, 'recentlyViewed' => !$wasMentioned];
        }
        return $results;
    }

    /** Record references from the user's latest history rows, keyed by URL, newest first. */
    private function latest(array $actions): array
    {
        $this->supportedTypes ??= (new Scopes($this->metadata))->all();
        $latest = [];
        foreach ($this->history($actions, $this->supportedTypes, 200) as $type => $ids) {
            foreach ($ids as $id => $number) {
                $ref = ['kind' => 'record', 'entityType' => $type, 'recordId' => (string) $id];
                // resolve() rejects the whole batch on one malformed ID.
                if (References::valid($ref)) $latest[References::url($ref)] = [$number, $ref];
            }
        }
        uasort($latest, fn ($a, $b) => $b[0] <=> $a[0]);
        return array_map(fn ($item) => $item[1], $latest);
    }

    /** Feeds "Mentioned recently". Search re-applies ACL, so this history never exposes a target. */
    public function logMentions(array $refs): void
    {
        if ($this->user->isSystem()) return;
        foreach ($refs as $ref) {
            $this->em->createEntity('ActionHistoryRecord', [
                'action' => self::MENTION_ACTION, 'userId' => $this->user->getId(),
                'targetType' => $ref['entityType'], 'targetId' => $ref['recordId'],
                'authTokenId' => $this->user->get('authTokenId'),
                'authLogRecordId' => $this->user->get('authLogRecordId'),
                'ipAddress' => $this->user->get('ipAddress'),
            ]);
        }
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
                    if ($type === 'ChatwootConversation') {
                        foreach (['chatwootAccountIdExternal', 'chatwootConversationId'] as $field) {
                            if ($this->acl->checkField($type, $field)) {
                                $results[References::url($ref)][$field] = $entity->get($field);
                            }
                        }
                    }
                }
            } catch (Forbidden) { continue; }
        }
        return array_values($results);
    }

    private function visual(Entity $entity): array
    {
        $type = $entity->getEntityType();
        if ($type === 'ChatwootConversation') {
            $visual = [];
            foreach (['contactAvatarUrl' => 'avatarUrl', 'inboxName' => 'inboxName', 'inboxChannelType' => 'inboxChannelType'] as $field => $attribute) {
                if ($this->acl->checkField($type, $field)) {
                    $visual[$attribute] = $entity->get($field);
                }
            }
            return $visual;
        }
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
