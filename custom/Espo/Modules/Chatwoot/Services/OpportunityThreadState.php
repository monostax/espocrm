<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\NotFound;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\SelectBuilder;

/** Callers authorize the opportunity/root before using these batched queries. */
class OpportunityThreadState
{
    public function __construct(private EntityManager $entityManager, private User $user) {}

    public static function unreadWhere(string $userId, string $alias = 'note'): array
    {
        $seen = SelectBuilder::create()->from('OpportunityThreadReadState', 'threadRead')->select('id')->where([
            'rootNoteId:' => "$alias.opportunityThreadRootId",
            'userId' => $userId,
            'lastSeenNumber>=:' => "$alias.number",
        ])->build();

        return [
            'opportunityThreadRootId!=' => null,
            'OR' => [['createdById!=' => $userId], ['createdById' => null]],
            Cond::not(Cond::exists($seen))->getRaw(),
        ];
    }

    public function readState(string $rootId): array
    {
        $state = $this->entityManager->getRDBRepository('OpportunityThreadReadState')->where([
            'rootNoteId' => $rootId, 'userId' => $this->user->getId(),
        ])->findOne();

        return [
            'lastSeenNumber' => (int) ($state?->get('lastSeenNumber') ?? 0),
            'version' => (int) ($state?->get('version') ?? 0),
        ];
    }

    public function markRead(Note $root, int $number, ?int $expectedVersion = null, ?string $userId = null): array
    {
        $userId ??= $this->user->getId();
        $this->entityManager->getTransactionManager()->run(function () use ($root, $number, $expectedVersion, $userId): void {
            // Use the opportunity lock, like OpportunityReadState, to serialize first creation.
            $parent = $this->entityManager->getRDBRepository('Opportunity')
                ->where(['id' => $root->getParentId()])->forUpdate()->findOne();
            if (!$parent) {
                throw new NotFound();
            }
            $state = $this->entityManager->getRDBRepository('OpportunityThreadReadState')->where([
                'rootNoteId' => $root->getId(), 'userId' => $userId,
            ])->forUpdate()->findOne();
            $version = (int) ($state?->get('version') ?? 0);
            if ($expectedVersion !== null && $expectedVersion !== $version) {
                return;
            }
            if (!$state) {
                $state = $this->entityManager->getNewEntity('OpportunityThreadReadState');
                $state->set(['rootNoteId' => $root->getId(), 'opportunityId' => $root->getParentId(), 'userId' => $userId]);
            }
            if ($number <= (int) $state->get('lastSeenNumber')) {
                return;
            }
            $state->set(['lastSeenNumber' => $number, 'version' => $version + 1]);
            $this->entityManager->saveEntity($state);
        });

        return $this->readState($root->getId());
    }

    /** Explicit opportunity-wide read, never used by the visible stream's cursor write. */
    public function markAllRead(string $opportunityId): void
    {
        $query = SelectBuilder::create()->from('Note')->select([
            'opportunityThreadRootId', ['MAX:number', 'lastNumber'],
        ])->where(['parentType' => 'Opportunity', 'parentId' => $opportunityId, 'opportunityThreadRootId!=' => null])
            ->group('opportunityThreadRootId')->build();
        foreach ($this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $root = $this->entityManager->getEntityById('Note', $row['opportunityThreadRootId']);
            if ($root instanceof Note) {
                $this->markRead($root, (int) $row['lastNumber']);
            }
        }
    }

    /** @return array<string, array> */
    public function unreadByOpportunity(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $query = SelectBuilder::create()->from('Note')->select([
            'parentId', 'opportunityThreadRootId', ['COUNT:id', 'count'],
        ])->where(['parentType' => 'Opportunity', 'parentId' => $ids, 'type' => Note::TYPE_POST])
            ->where(self::unreadWhere($this->user->getId()))->group(['parentId', 'opportunityThreadRootId']);
        $result = [];
        foreach ($this->entityManager->getQueryExecutor()->execute($query->build())->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = $row['parentId'];
            $result[$id] ??= ['count' => 0, 'rootIds' => [], 'hasMention' => false];
            $result[$id]['count'] += (int) $row['count'];
            $result[$id]['rootIds'][] = $row['opportunityThreadRootId'];
        }
        $query->where(['opportunityMentionUserIds*' => '%"' . $this->user->getId() . '"%']);
        foreach ($this->entityManager->getQueryExecutor()->execute($query->build())->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[$row['parentId']]['hasMention'] = true;
        }
        return $result;
    }

    /** One aggregate query and one participant query for the entire stream page. */
    public function summaries(array $rootIds): array
    {
        if (!$rootIds) {
            return [];
        }
        $base = SelectBuilder::create()->from('Note')->where([
            'parentType' => 'Opportunity', 'type' => Note::TYPE_POST, 'opportunityThreadRootId' => $rootIds,
        ]);
        $query = (clone $base)->select([
            'opportunityThreadRootId', ['COUNT:id', 'replyCount'], ['MAX:createdAt', 'lastReplyAt'],
        ])->group('opportunityThreadRootId')->build();
        $result = array_fill_keys($rootIds, ['replyCount' => 0, 'participants' => [], 'lastReplyAt' => null, 'unreadCount' => 0]);
        foreach ($this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[$row['opportunityThreadRootId']]['replyCount'] = (int) $row['replyCount'];
            $result[$row['opportunityThreadRootId']]['lastReplyAt'] = $row['lastReplyAt'];
        }
        $query = (clone $base)->select([
            'opportunityThreadRootId', 'createdById', 'createdByName', ['MAX:number', 'lastNumber'],
        ])->group(['opportunityThreadRootId', 'createdById', 'createdByName'])->order('MAX:number', 'DESC')->build();
        foreach ($this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $participants = &$result[$row['opportunityThreadRootId']]['participants'];
            if (count($participants) < 3) {
                $participants[] = ['id' => $row['createdById'], 'name' => $row['createdByName']];
            }
            unset($participants);
        }
        $query = (clone $base)->select(['opportunityThreadRootId', ['COUNT:id', 'count']])
            ->where(self::unreadWhere($this->user->getId()))->group('opportunityThreadRootId')->build();
        foreach ($this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[$row['opportunityThreadRootId']]['unreadCount'] = (int) $row['count'];
        }
        return $result;
    }
}
