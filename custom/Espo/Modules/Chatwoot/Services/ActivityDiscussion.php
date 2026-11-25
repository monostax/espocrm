<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\Where\Comparison as Cmp;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;
use Espo\Tools\Stream\MassNotePreparator;

/** Reuses the Note discussion wire fields introduced for Opportunities. Cursors are activity-specific. */
class ActivityDiscussion
{
    public const PARENT_TYPES = ['Task', 'Call', 'Meeting', 'Appointment', 'Initiative', 'Contact', 'Account', 'AiSession'];

    public function __construct(
        private EntityManager $em,
        private User $user,
        private Acl $acl,
        private OpportunityEventAccess $eventAccess,
    ) {}

    /** Parents whose Mural shows their own overdue event (2026-11-25). */
    public const SELF_OVERDUE_TYPES = ['Task'];

    /** Apply before ordering, pagination, counts and cursor selection. Threads contain posts only. */
    public function entryWhere(string $type, ?string $root = null, string $alias = 'note'): array
    {
        if ($root === null && in_array($type, self::SELF_OVERDUE_TYPES, true)) {
            // Its own event only: reading the parent already grants reading the related record.
            return ['OR' => [['type' => 'Post'], [
                'type' => OpportunityStreamEvents::ACTIVITY_OVERDUE, 'relatedType' => $type, 'relatedId:' => "$alias.parentId",
            ]]];
        }
        if (!isset(OpportunityStreamEvents::DISCUSSION_ROLLOUT[$type]) || $root !== null) return ['type' => 'Post'];
        return [
            'type' => OpportunityStreamEvents::TYPES,
            $this->eventAccess->where($this->user),
            $this->eventAccess->messageLinkWhere($this->user, $alias, $type),
        ];
    }

    public function unreadWhere(string $alias = 'note'): array
    {
        $seen = SelectBuilder::create()->from('ActivityReadState', 'activitySeen')->select('id')->where([
            'parentType:' => "$alias.parentType", 'parentId:' => "$alias.parentId", 'userId' => $this->user->getId(),
            'lastSeenNumber>=:' => "$alias.number",
            'OR' => [
                ['threadKey' => 'main', "$alias.opportunityThreadRootId" => null],
                ['threadKey:' => "$alias.opportunityThreadRootId"],
            ],
        ])->build();
        return [
            'OR' => [["$alias.createdById!=" => $this->user->getId()], ["$alias.createdById" => null]],
            Cond::not(Cond::exists($seen))->getRaw(),
        ];
    }

    /**
     * Uncorrelated parent-id sets, so MariaDB materializes the few matching posts and
     * drives into the parent by primary key. Correlated EXISTS made it probe every
     * parent in the tenant (88k contacts, ~1.5s) through the ACL subqueries first.
     */
    public function applyFilter(SelectBuilder $query, string $type, bool $unread): void
    {
        $notes = SelectBuilder::create()->from('Note', 'activityPost')->select('parentId', 'id')->where([
            'parentType' => $type, 'opportunityPostDeleted' => false,
        ])->where($unread ? $this->entryWhere($type, alias: 'activityPost') : ['type' => 'Post']);
        if ($unread) {
            $notes->where($this->unreadWhere('activityPost'))->where($this->countsAsUnreadWhere($type));
            $marked = SelectBuilder::create()->from('ActivityReadState')->select('parentId', 'id')->where([
                'parentType' => $type, 'userId' => $this->user->getId(),
                'threadKey' => 'main', 'isMarkedUnread' => true,
            ])->build();
            $union = UnionBuilder::create()->query($notes->build())->query($marked)->build();
            $ids = SelectBuilder::create()->fromQuery($union, 'activityUnread')->select('id')->build();
        } else {
            $ids = $notes->where($this->mentionWhere())->build();
        }
        $query->where(Cmp::in(Expr::column('id'), $ids));
    }

    /**
     * Incoming mural messages and posts or replies that @mention the viewer make a record unread; plain posts
     * never do. A task's own overdue event is addressed to its assignee, creator and collaborators through the
     * same mention ids. AI sessions are the viewer's own chat with an agent, so every reply counts.
     * Per-thread summaries still count every unread reply.
     */
    private function countsAsUnreadWhere(string $type): array
    {
        return match ($type) {
            'AiSession' => [],
            'Account', 'Initiative' => ['OR' => [['type' => OpportunityStreamEvents::MESSAGE_RECEIVED], ['type' => 'Post'] + $this->mentionWhere()]],
            default => $this->mentionWhere(),
        };
    }

    private function mentionWhere(): array
    {
        return [
            'opportunityMentionUserIds!=' => null,
            'opportunityMentionUserIds*' => '%"' . $this->user->getId() . '"%',
        ];
    }

    public function states(string $type, array $ids): array
    {
        if (!$ids) return [];
        $result = array_fill_keys($ids, [
            'lastSeenNumber' => 0, 'lastSeenAt' => null, 'version' => 0, 'isMarkedUnread' => false,
            'unreadCount' => 0, 'streamUnreadCount' => 0, 'threadUnreadCount' => 0, 'unreadThreadIds' => [],
        ]);
        foreach ($this->em->getRDBRepository('ActivityReadState')->where([
            'parentType' => $type, 'parentId' => $ids, 'userId' => $this->user->getId(), 'threadKey' => 'main',
        ])->find() as $state) {
            foreach (['lastSeenNumber', 'lastSeenAt', 'version', 'isMarkedUnread'] as $field) {
                $result[$state->get('parentId')][$field] = $state->get($field);
            }
        }
        $query = SelectBuilder::create()->from('Note')->select(['parentId', 'opportunityThreadRootId', ['COUNT:id', 'count']])
            ->where(['parentType' => $type, 'parentId' => $ids, 'opportunityPostDeleted' => false])->where($this->entryWhere($type))
            ->where($this->unreadWhere())->where($this->countsAsUnreadWhere($type))
            ->group(['parentId', 'opportunityThreadRootId'])->build();
        foreach ($this->em->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $state = &$result[$row['parentId']];
            $count = (int) $row['count'];
            $state['unreadCount'] += $count;
            $state[$row['opportunityThreadRootId'] ? 'threadUnreadCount' : 'streamUnreadCount'] += $count;
            if ($row['opportunityThreadRootId']) $state['unreadThreadIds'][] = $row['opportunityThreadRootId'];
            unset($state);
        }
        return $result;
    }

    public function cursor(Entity $parent, string $threadKey = 'main'): array
    {
        $state = $this->em->getRDBRepository('ActivityReadState')->where([
            'parentType' => $parent->getEntityType(), 'parentId' => $parent->getId(),
            'userId' => $this->user->getId(), 'threadKey' => $threadKey,
        ])->findOne();
        return ['lastSeenNumber' => (int) $state?->get('lastSeenNumber'), 'version' => (int) $state?->get('version')];
    }

    public function mark(Entity $parent, object $data): array
    {
        $root = $data->rootId ?? null;
        if ($root) $this->root($parent, $root);
        $where = ['parentType' => $parent->getEntityType(), 'parentId' => $parent->getId(), 'opportunityThreadRootId' => $root]
            + $this->entryWhere($parent->getEntityType(), $root);
        $postId = $data->lastPostId ?? null;
        // An explicit null means the rendered stream was empty. Never consume a
        // post that arrived between that snapshot and its automatic cursor write.
        $emptySnapshot = property_exists($data, 'lastPostId') && $data->lastPostId === null;
        $post = $emptySnapshot ? null : $this->em->getRDBRepository('Note')->where($where + ($postId ? ['id' => $postId] : []))->order('number', 'DESC')->findOne();
        if ($postId && (!$post || !$this->acl->checkEntityRead($post))) throw new BadRequest('Invalid read cutoff.');
        if (isset($data->expectedVersion) && (!is_int($data->expectedVersion) || $data->expectedVersion < 0)) throw new BadRequest('Invalid version.');
        if (isset($data->expectedVersion) && !property_exists($data, 'lastPostId')) throw new BadRequest('A rendered cutoff is required.');
        $this->em->getTransactionManager()->run(function () use ($parent, $data, $post, $root): void {
            $this->em->getRDBRepository($parent->getEntityType())->where(['id' => $parent->getId()])->forUpdate()->findOne();
            $key = ['parentType' => $parent->getEntityType(), 'parentId' => $parent->getId(), 'userId' => $this->user->getId(), 'threadKey' => $root ?: 'main'];
            $state = $this->em->getRDBRepository('ActivityReadState')->where($key)->forUpdate()->findOne();
            $version = (int) $state?->get('version');
            if (isset($data->expectedVersion) && $version !== $data->expectedVersion) return;
            $state ??= $this->em->getNewEntity('ActivityReadState');
            $unread = ($data->unread ?? false) === true;
            $number = $unread ? max(0, (int) $post?->get('number') - 1) : max((int) $state->get('lastSeenNumber'), (int) $post?->get('number'));
            if (!$state->isNew() && $number === (int) $state->get('lastSeenNumber') && $unread === (bool) $state->get('isMarkedUnread')) return;
            $state->set($key + ['lastSeenNumber' => $number, 'lastSeenAt' => gmdate('Y-m-d H:i:s'), 'version' => $version + 1, 'isMarkedUnread' => $unread]);
            $this->em->saveEntity($state);
        });
        if (!$root && ($data->allThreads ?? false) === true && !($data->unread ?? false)) {
            $roots = SelectBuilder::create()->from('Note')->select('opportunityThreadRootId')->distinct()->where([
                'parentType' => $parent->getEntityType(), 'parentId' => $parent->getId(), 'type' => 'Post', 'opportunityThreadRootId!=' => null,
            ])->build();
            foreach ($this->em->getQueryExecutor()->execute($roots)->fetchAll(\PDO::FETCH_COLUMN) as $rootId) {
                $this->mark($parent, (object) ['rootId' => $rootId]);
            }
        }
        return $root ? $this->cursor($parent, $root) : $this->states($parent->getEntityType(), [$parent->getId()])[$parent->getId()];
    }

    public function root(Entity $parent, string $rootId): Note
    {
        $root = $this->em->getEntityById('Note', $rootId);
        if (!$root instanceof Note || $root->getParentType() !== $parent->getEntityType() || $root->getParentId() !== $parent->getId() ||
            $root->getType() !== 'Post' || $root->get('opportunityThreadRootId')) throw new BadRequest('Invalid activity thread.');
        if (!$this->acl->checkEntityRead($root)) throw new Forbidden();
        return $root;
    }

    public function stream(Entity $parent, MassNotePreparator $preparator, ?string $rootId, ?int $before, bool $postsOnly = false): object
    {
        $root = $rootId ? $this->root($parent, $rootId) : null;
        $where = ['parentType' => $parent->getEntityType(), 'parentId' => $parent->getId(), 'opportunityThreadRootId' => $rootId]
            + $this->entryWhere($parent->getEntityType(), $rootId);
        // Hiding pills changes pagination, never the read boundary. Take the cutoff before the page
        // so a message arriving during the request cannot be marked read by this snapshot.
        $extra = [];
        if ($postsOnly && !$rootId) {
            if (!$before) {
                $cutoff = $this->em->getRDBRepository('Note')->select(['id', 'number'])->where($where)->order('number', 'DESC')->findOne();
                $extra['readCutoff'] = $cutoff ? ['id' => $cutoff->getId(), 'number' => $cutoff->get('number')] : null;
            }
            $where = ['parentType' => $parent->getEntityType(), 'parentId' => $parent->getId(), 'opportunityThreadRootId' => null, 'type' => 'Post'];
        }
        if ($before) $where['number<'] = $before;
        $notes = [...$this->em->getRDBRepository('Note')->where($where)->order('number', 'DESC')->limit(0, 51)->find()];
        $hasMore = count($notes) > 50;
        $notes = array_slice($notes, 0, 50);
        $all = $root ? [$root, ...$notes] : $notes;
        foreach ($all as $note) {
            if (!$this->acl->checkEntityRead($note)) throw new Forbidden();
            $note->loadAdditionalFields();
        }
        $preparator->prepare($all);
        return (object) [
            ...$extra,
            'list' => array_map(fn ($note) => $note->getValueMap(), array_reverse($notes)), 'hasMore' => $hasMore,
            'root' => $root?->getValueMap(),
            'readState' => $rootId ? $this->cursor($parent, $rootId) : $this->states($parent->getEntityType(), [$parent->getId()])[$parent->getId()],
        ];
    }

    public function summaries(array $ids): array
    {
        if (!$ids) return [];
        $base = SelectBuilder::create()->from('Note')->where(['opportunityThreadRootId' => $ids, 'type' => 'Post']);
        $result = array_fill_keys($ids, ['replyCount' => 0, 'unreadCount' => 0, 'participants' => [], 'lastReplyAt' => null]);
        foreach ([false, true] as $unread) {
            $query = (clone $base)->select(['opportunityThreadRootId', ['COUNT:id', 'count'], ['MAX:createdAt', 'lastReplyAt']])->group('opportunityThreadRootId');
            if ($unread) $query->where($this->unreadWhere());
            foreach ($this->em->getQueryExecutor()->execute($query->build())->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $result[$row['opportunityThreadRootId']][$unread ? 'unreadCount' : 'replyCount'] = (int) $row['count'];
                if (!$unread) $result[$row['opportunityThreadRootId']]['lastReplyAt'] = $row['lastReplyAt'];
            }
        }
        return $result;
    }
}
