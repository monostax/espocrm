<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\SelectBuilder;
use Closure;

class OpportunityReadStateService
{
    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Acl $acl,
        private UserTenantResolver $tenantResolver,
    ) {}

    /** Filter before pagination, using the same personal cutoff as getReadStates. */
    public function applyListFilter(SelectBuilder $queryBuilder, bool $onlyUnread): void
    {
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->acl->checkScope('Opportunity', 'stream')) {
            throw new Forbidden();
        }

        // The record list applies read ACL; stream access and tenant membership
        // must also hold, just as they do for the read-state endpoints.
        if (!$this->user->isAdmin()) {
            $queryBuilder->where(['tenantId' => $this->tenantResolver->resolveTenantIds($this->user)]);
        }

        $userId = $this->user->getId();
        $mention = ['opportunityMentionUserIds*' => '%"' . $userId . '"%'];
        $posts = SelectBuilder::create()
            ->from('Note', 'streamPost')
            ->select('id')
            ->where([
                'parentType' => 'Opportunity',
                'parentId:' => 'opportunity.id',
                'type' => Note::TYPE_POST,
                'OR' => [['createdById!=' => $userId], ['createdById' => null]],
            ]);

        if ($onlyUnread) {
            $posts->join('OpportunityReadState', 'readState', [
                'readState.opportunityId:' => 'streamPost.parentId',
                'readState.userId' => $userId,
                'readState.deleted' => false,
            ])->where(['readState.lastSeenAt!=' => null])->where([
                'OR' => [
                    ['readState.lastSeenNumber!=' => null, 'number>:' => 'readState.lastSeenNumber'],
                    ['readState.lastSeenNumber' => null, 'createdAt>:' => 'readState.lastSeenAt'],
                ],
            ])->where([
                'OR' => [
                    $mention,
                    [
                        'opportunity.status!=' => ['Won', 'Lost'],
                        'OR' => [
                            ['opportunity.assignedUserId' => null],
                            ['opportunity.assignedUserId' => $userId],
                            ['readState.isParticipant' => true],
                        ],
                    ],
                ],
            ]);
        } else {
            // Like the conversation Mentions inbox, retain read mentions too.
            $posts->where($mention);
        }

        $queryBuilder->where(Cond::exists($posts->build()));
    }

    /** @return array<string, array<string, mixed>> */
    public function getReadStates(array $ids): array
    {
        $opportunities = $this->readableOpportunities($ids);
        $userId = $this->user->getId();
        $states = [];

        if (!$opportunities) {
            return [];
        }

        foreach ($this->entityManager->getRDBRepository('OpportunityReadState')
            ->where(['opportunityId' => array_keys($opportunities), 'userId' => $userId])->find() as $state) {
            $states[$state->get('opportunityId')] = $state;
        }

        $subscriptions = [];
        foreach ($this->entityManager->getRDBRepository('StreamSubscription')->where([
            'entityType' => 'Opportunity', 'entityId' => array_keys($opportunities), 'userId' => $userId,
        ])->find() as $subscription) {
            $subscriptions[$subscription->get('entityId')] = true;
        }

        $result = [];
        $unreadWhere = [];
        foreach ($opportunities as $id => $opportunity) {
            $state = $states[$id] ?? null;
            // Espo can insert subscriptions in bulk without firing entity hooks.
            if (($opportunity->get('assignedUserId') === $userId || isset($subscriptions[$id])) &&
                !$state?->get('isParticipant')) {
                $state = $this->addParticipant($id, $userId);
            }

            $lastSeenAt = $state?->get('lastSeenAt');
            $lastSeenNumber = $state?->get('lastSeenNumber');
            $result[$id] = [
                'lastSeenAt' => $lastSeenAt,
                'lastSeenNumber' => $lastSeenNumber,
                'version' => (int) ($state?->get('version') ?? 0),
                'isParticipant' => (bool) $state?->get('isParticipant'),
                'unreadCount' => 0,
                'hasUnreadMention' => false,
            ];

            // Like Conversation: no personal cutoff does NOT resurrect history.
            if ($lastSeenAt !== null) {
                $unreadWhere[] = ['parentId' => $id] + ($lastSeenNumber !== null
                    ? ['number>' => $lastSeenNumber]
                    : ['createdAt>' => $lastSeenAt]);
            }
        }

        if ($unreadWhere) {
            $where = $this->otherPostsWhere(array_keys($opportunities), $userId);
            $where['AND'] = [['OR' => $unreadWhere]];
            foreach ($this->countPosts($where) as $id => $count) {
                $result[$id]['unreadCount'] = $count;
            }
            // This column contains ONLY verified CRM user IDs, not arbitrary Note data.
            $where['opportunityMentionUserIds*'] = '%"' . $userId . '"%';
            foreach ($this->countPosts($where) as $id => $count) {
                $result[$id]['hasUnreadMention'] = $count > 0;
            }
        }

        return $result;
    }

    public function getReadState(string $id): array
    {
        return $this->getReadStates([$id])[$id];
    }

    public function markRead(string $id, ?string $lastPostId = null, ?int $expectedVersion = null): array
    {
        $this->readableOpportunities([$id]);
        $post = $this->entityManager->getRDBRepository('Note')->where([
            'parentType' => 'Opportunity', 'parentId' => $id, 'type' => Note::TYPE_POST,
        ] + ($lastPostId !== null ? ['id' => $lastPostId] : []))->order('number', 'DESC')->findOne();

        if ($lastPostId !== null && !$post) {
            throw new BadRequest('The read cutoff must reference a post on this Opportunity.');
        }

        $this->withState($id, $this->user->getId(), function (Entity $state) use ($post, $expectedVersion): void {
            if ($expectedVersion !== null && (int) $state->get('version') !== $expectedVersion) {
                return; // A newer read/unread action won. Never overwrite it with a stale view.
            }
            $this->advance($state, $post?->get('createdAt') ?? gmdate('Y-m-d H:i:s'), (int) ($post?->get('number') ?? 0));
        });

        return $this->getReadState($id);
    }

    public function markUnread(string $id): array
    {
        $opportunity = $this->readableOpportunities([$id])[$id];
        $state = $this->getReadState($id);
        if (in_array($opportunity->get('status'), ['Won', 'Lost'], true) || !$state['isParticipant']) {
            throw new Forbidden('Only an assignee or participant can mark an open Opportunity unread.');
        }

        $this->withState($id, $this->user->getId(), function (Entity $state) use ($id): void {
            $post = $this->latestOtherPost($id, $this->user->getId());
            $state->set('lastSeenAt', $post ? $this->before($post->get('createdAt')) : null);
            $state->set('lastSeenNumber', $post ? (int) $post->get('number') - 1 : null);
        }, true);

        // Calculate the real count/mentions/participation; never invent a successful state.
        return $this->getReadState($id);
    }

    /** One-time migration baseline; never move a newer read cutoff backwards. */
    public function initializeReadBaseline(
        string $id,
        string $userId,
        string $timestamp,
        int $number,
        bool $isParticipant,
    ): void {
        $this->withState($id, $userId, function (Entity $state) use ($timestamp, $number, $isParticipant): void {
            if ($isParticipant) {
                $state->set('isParticipant', true);
            }
            $this->advance($state, $timestamp, $number);
        });
    }

    /** Participation is explicit. Neither viewing nor marking unread sets this flag. */
    public function addParticipant(
        string $id,
        string $userId,
        ?string $anchor = null,
        ?int $number = null,
        bool $preserveExistingCutoff = false,
    ): Entity {
        return $this->withState($id, $userId, function (Entity $state) use (
            $id, $userId, $anchor, $number, $preserveExistingCutoff
        ): void {
            if ($state->get('isParticipant')) {
                return;
            }
            $state->set('isParticipant', true);
            if ($preserveExistingCutoff && !$state->isNew()) {
                return;
            }
            if ($anchor === null) {
                $post = $this->latestOtherPost($id, $userId);
                $anchor = $post ? $this->before($post->get('createdAt')) : gmdate('Y-m-d H:i:s');
                $number = $post ? (int) $post->get('number') - 1 : 0;
            }
            $this->advance($state, $anchor, $number);
        });
    }

    public function recordPost(Note $post): void
    {
        $id = $post->getParentId();
        $authorId = $post->get('createdById');
        $author = $authorId ? $this->entityManager->getEntityById('User', $authorId) : null;
        if ($author && ($author->isRegular() || $author->isAdmin())) {
            $this->withState($id, $authorId, function (Entity $state) use ($post): void {
                $state->set('isParticipant', true);
                $this->advance($state, $post->get('createdAt'), (int) $post->get('number'));
            });
        }
        foreach ($post->get('opportunityMentionUserIds') ?? [] as $userId) {
            if ($userId !== $authorId) {
                $this->addParticipant($id, $userId, $this->before($post->get('createdAt')), (int) $post->get('number') - 1);
            }
        }
    }

    public function anchorAssignee(string $id, string $userId): void
    {
        $this->withState($id, $userId, function (Entity $state) use ($id, $userId): void {
            $post = $this->latestOtherPost($id, $userId);
            $state->set('isParticipant', true);
            $this->advance($state, $post ? $this->before($post->get('createdAt')) : gmdate('Y-m-d H:i:s'),
                $post ? (int) $post->get('number') - 1 : 0);
        });
    }

    private function advance(Entity $state, string $timestamp, ?int $number): void
    {
        $currentNumber = $state->get('lastSeenNumber');
        $currentTimestamp = $state->get('lastSeenAt');
        if ($currentTimestamp !== null && ($currentNumber !== null && $number !== null
            ? $currentNumber >= $number
            : $currentTimestamp >= $timestamp)) {
            return;
        }
        $state->set('lastSeenAt', $timestamp);
        $state->set('lastSeenNumber', $number);
    }

    private function withState(string $id, string $userId, Closure $update, bool $forceVersion = false): Entity
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id, $userId, $update, $forceVersion): Entity {
            // Serialize first creation as well as updates, without touching the Opportunity.
            $opportunity = $this->entityManager->getRDBRepository('Opportunity')
                ->where(['id' => $id])->forUpdate()->findOne();
            if (!$opportunity) {
                throw new NotFound('Opportunity not found.');
            }
            $state = $this->entityManager->getRDBRepository('OpportunityReadState')
                ->where(['opportunityId' => $id, 'userId' => $userId])->forUpdate()->findOne();
            if (!$state) {
                $state = $this->entityManager->getNewEntity('OpportunityReadState');
                $state->set(['opportunityId' => $id, 'userId' => $userId, 'version' => 0, 'isParticipant' => false]);
            }
            $before = [$state->get('lastSeenAt'), $state->get('lastSeenNumber'), $state->get('isParticipant')];
            $update($state);
            $after = [$state->get('lastSeenAt'), $state->get('lastSeenNumber'), $state->get('isParticipant')];
            if ($forceVersion || $before !== $after) {
                $state->set('version', (int) $state->get('version') + 1);
                $this->entityManager->saveEntity($state);
            }
            return $state;
        });
    }

    private function readableOpportunities(array $ids): array
    {
        if (count($ids) > 100) {
            throw new BadRequest('At most 100 Opportunity IDs are allowed.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id)) {
                throw new BadRequest('Invalid Opportunity ID.');
            }
        }
        if (!$ids) {
            return [];
        }
        if (!$this->user->isRegular() && !$this->user->isAdmin()) {
            throw new Forbidden();
        }
        $tenantIds = $this->user->isAdmin() ? [] : $this->tenantResolver->resolveTenantIds($this->user);
        $result = [];
        foreach ($this->entityManager->getRDBRepository('Opportunity')->where(['id' => array_values(array_unique($ids))])->find() as $opportunity) {
            if (!$this->acl->checkEntityRead($opportunity) || !$this->acl->checkEntity($opportunity, 'stream') ||
                (!$this->user->isAdmin() && !in_array($opportunity->get('tenantId'), $tenantIds, true))) {
                throw new Forbidden('No access to this Opportunity stream.');
            }
            $result[$opportunity->getId()] = $opportunity;
        }
        if (count($result) !== count(array_unique($ids))) {
            throw new NotFound('Opportunity not found.');
        }
        return $result;
    }

    private function otherPostsWhere(array $ids, string $userId): array
    {
        return [
            'parentType' => 'Opportunity', 'parentId' => $ids, 'type' => Note::TYPE_POST,
            'OR' => [['createdById!=' => $userId], ['createdById' => null]],
        ];
    }

    private function latestOtherPost(string $id, string $userId): ?Entity
    {
        return $this->entityManager->getRDBRepository('Note')
            ->where($this->otherPostsWhere([$id], $userId))->order('number', 'DESC')->findOne();
    }

    private function countPosts(array $where): array
    {
        $query = $this->entityManager->getQueryBuilder()->select()->from('Note')
            ->select(['parentId', ['COUNT:id', 'postCount']])->where($where)->group('parentId')->build();
        $rows = $this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['parentId']] = (int) $row['postCount'];
        }
        return $counts;
    }

    private function before(string $timestamp): string
    {
        return (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->modify('-1 second')->format('Y-m-d H:i:s');
    }
}
