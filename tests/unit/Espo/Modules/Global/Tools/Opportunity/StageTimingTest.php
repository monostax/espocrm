<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Opportunity;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\DateTime\Clock;
use Espo\Modules\Global\Hooks\Opportunity\TrackStageTime;
use Espo\Modules\Global\Tools\Opportunity\StageHistory;
use Espo\Modules\Global\Tools\Opportunity\StageTiming;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\EntityFactory;
use Espo\ORM\Mapper\RDBMapper;
use Espo\ORM\QueryComposer\QueryComposer;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\Repository\HookMediator;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\TransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real ORM entities and SQLite transactions; repository queries are adapted to the isolated test store. */
class StageTimingTest extends TestCase
{
    private PDO $pdo;
    private EntityManager $em;
    private TransactionManager $transactions;
    private StageTiming $timing;
    private RDBRepository $repository;
    private Clock $clock;
    private string $now = '2026-09-17 10:00:00';
    private bool $failHistoryInsert = false;
    private int $locks = 0;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE records (type TEXT, id TEXT, body TEXT, PRIMARY KEY (type, id))');
        $composer = $this->createMock(QueryComposer::class);
        $composer->method('composeCreateSavepoint')->willReturnCallback(fn ($name) => "SAVEPOINT $name");
        $composer->method('composeReleaseSavepoint')->willReturnCallback(fn ($name) => "RELEASE SAVEPOINT $name");
        $composer->method('composeRollbackToSavepoint')->willReturnCallback(fn ($name) => "ROLLBACK TO SAVEPOINT $name");
        $this->transactions = new TransactionManager($this->pdo, $composer);
        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('getTransactionManager')->willReturn($this->transactions);
        $this->em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $this->load($type, $id));
        $this->em->method('getNewEntity')->willReturnCallback(fn ($type) => $this->entity($type, [], true));
        $this->em->method('saveEntity')->willReturnCallback(function (Entity $entity): void {
            if ($this->failHistoryInsert && $entity->getEntityType() === 'OpportunityStageHistory' && $entity->isNew()) {
                throw new RuntimeException('Simulated ledger insert failure');
            }
            $this->store($entity);
            $entity->setAsFetched();
        });
        $this->em->method('getRDBRepository')->willReturnCallback(function ($type) {
            $repo = $this->createMock(RDBRepository::class);
            $repo->method('where')->willReturnCallback(function ($where) use ($type) {
                $builder = $this->createMock(RDBSelectBuilder::class);
                $builder->method('forUpdate')->willReturnCallback(function () use ($builder) {
                    $this->assertTrue($this->transactions->isStarted());
                    $this->locks++;
                    return $builder;
                });
                $builder->method('sth')->willReturnSelf();
                $builder->method('order')->willReturnSelf();
                $builder->method('findOne')->willReturnCallback(fn () => $this->load($type, $where['id']));
                $matches = function () use ($type, $where): array {
                    return array_values(array_filter($this->all($type), fn ($e) =>
                        !isset($where['opportunityId']) || $e->get('opportunityId') === $where['opportunityId']));
                };
                $builder->method('max')->willReturnCallback(fn ($field) =>
                    max([0, ...array_map(fn ($e) => (int) $e->get($field), $matches())]));
                $builder->method('find')->willReturnCallback(fn () => new EntityCollection(array_reverse($matches())));
                return $builder;
            });
            return $repo;
        });
        $this->clock = $this->createMock(Clock::class);
        $this->clock->method('now')->willReturnCallback(fn () => new DateTimeImmutable($this->now . 'Z'));
        $this->timing = new StageTiming($this->em, $this->clock);
        $hook = new TrackStageTime($this->timing);
        $mediator = $this->createMock(HookMediator::class);
        $mediator->method('beforeSave')->willReturnCallback(fn ($entity, $options) =>
            $hook->beforeSave($entity, SaveOptions::fromAssoc($options)));
        $mediator->method('afterSave')->willReturnCallback(fn ($entity, $options) =>
            $hook->afterSave($entity, SaveOptions::fromAssoc($options)));
        $mapper = $this->createMock(RDBMapper::class);
        $mapper->method('insert')->willReturnCallback(function ($entity): void {
            if (!$entity->get('id')) $entity->set('id', 'opportunity');
            $this->store($entity);
        });
        $mapper->method('update')->willReturnCallback(fn ($entity) => $this->store($entity));
        $this->em->method('getMapper')->willReturn($mapper);
        $this->repository = new class('Opportunity', $this->em, $this->createMock(EntityFactory::class), $mediator) extends RDBRepository {
            protected bool $transactionalSave = true;
        };
        $this->store($this->entity('Funnel', ['id' => 'funnel', 'name' => 'Sales']));
        foreach (['a' => 3600, 'b' => 7200, 'closed' => null] as $id => $target) {
            $this->store($this->entity('OpportunityStage', ['id' => $id, 'name' => strtoupper($id), 'targetTimeSeconds' => $target]));
        }
    }

    public function testCreateWithoutIdSnapshotsTargetAndIgnoresCopiedTimingFields(): void
    {
        $entity = $this->opportunity(true);
        $entity->set(['stageEnteredAt' => '2000-01-01 00:00:00', 'currentStageVisitId' => 'copied']);
        $this->save($entity);
        $this->assertSame($this->now, $entity->get('stageEnteredAt'));
        $this->assertSame('2026-09-17 11:00:00', $entity->get('stageDueAt'));
        $this->assertFalse($entity->get('stageTimingIsPartial'));
        $visit = $this->all('OpportunityStageHistory')[0];
        $this->assertSame($entity->getId(), $visit->get('opportunityId'));
        $this->assertSame(3600, $visit->get('targetTimeSeconds'));
        $this->assertNull($visit->get('exitedAt'));
    }

    public function testRepeatedVisitsFreezeTargetsAndNamesAndIgnoreOrdinaryEdits(): void
    {
        $entity = $this->opportunity(true);
        $this->save($entity);
        $first = $entity->get('currentStageVisitId');
        $stage = $this->load('OpportunityStage', 'a');
        $stage->set(['name' => 'Renamed A', 'targetTimeSeconds' => 10800]);
        $this->store($stage);
        $entity->set('name', 'Unrelated edit');
        $this->now = '2026-09-17 10:30:00';
        $this->save($entity);
        $this->assertSame($first, $entity->get('currentStageVisitId'));
        $this->assertSame(3600, $entity->get('stageTargetTimeSeconds'));
        $entity->set('opportunityStageId', 'b');
        $this->save($entity);
        $this->now = '2026-09-17 11:30:00';
        $entity->set('opportunityStageId', 'a');
        $this->save($entity);
        $rows = $this->all('OpportunityStageHistory');
        $this->assertCount(3, $rows);
        $this->assertSame(1800, $rows[0]->get('durationSeconds'));
        $this->assertSame('A', $rows[0]->get('stageName'));
        $this->assertSame(3600, $rows[1]->get('durationSeconds'));
        $this->assertSame(10800, $rows[2]->get('targetTimeSeconds'));
        $this->assertSame('Renamed A', $rows[2]->get('stageName'));
        $this->assertNotSame($first, $entity->get('currentStageVisitId'));
        $this->assertSame('2026-09-17 14:30:00', $entity->get('stageDueAt'));
        $this->assertSame([1, 2, 3], array_map(fn ($row) => $row->get('sequence'), $rows));
        $this->assertGreaterThan(0, $this->locks);
    }

    public function testCloseAndReopenWithoutChangingStageId(): void
    {
        $entity = $this->opportunity(true);
        $this->save($entity);
        $this->now = '2026-09-17 12:00:00';
        $entity->set('status', 'Won');
        $this->save($entity);
        $this->assertNull($entity->get('currentStageVisitId'));
        $this->assertNull($entity->get('stageDueAt'));
        $rows = $this->all('OpportunityStageHistory');
        $this->assertSame(7200, $rows[0]->get('durationSeconds'));
        $this->assertSame('Won', $rows[1]->get('kind'));
        $this->assertSame(0, $rows[1]->get('durationSeconds'));
        $this->now = '2026-09-18 12:00:00';
        $entity->set('status', 'Open');
        $this->save($entity);
        $this->assertSame('2026-09-18 13:00:00', $entity->get('stageDueAt'));
        $this->assertCount(3, $this->all('OpportunityStageHistory'));
    }

    public function testNoTargetAndCreateClosed(): void
    {
        $entity = $this->opportunity(true);
        $entity->set('opportunityStageId', 'closed');
        $this->save($entity);
        $this->assertNotNull($entity->get('currentStageVisitId'));
        $this->assertNull($entity->get('stageDueAt'));
        $closed = $this->opportunity(true);
        $closed->set(['id' => 'another', 'status' => 'Lost', 'opportunityStageId' => 'closed']);
        $this->save($closed);
        $this->assertNull($closed->get('currentStageVisitId'));
        $rows = $this->all('OpportunityStageHistory');
        $this->assertSame('Lost', $rows[1]->get('kind'));
    }

    public function testRolloutIsPartialIdempotentAndDoesNotInventClosedMilestones(): void
    {
        $entity = $this->opportunity();
        $this->store($entity);
        $this->timing->initialize($entity->getId(), $this->now);
        $this->timing->initialize($entity->getId(), '2026-09-18 00:00:00');
        $rows = $this->all('OpportunityStageHistory');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->get('isPartial'));
        $this->assertSame($this->now, $rows[0]->get('enteredAt'));
        $closed = $this->opportunity();
        $closed->set(['id' => 'closed-opportunity', 'status' => 'Won']);
        $this->store($closed);
        $this->timing->initialize($closed->getId(), $this->now);
        $this->assertCount(1, $this->all('OpportunityStageHistory'));
        $this->assertNotNull($this->load('Opportunity', $closed->getId())->get('stageTrackingStartedAt'));
    }

    public function testTransitionBeforeInitializationMarksOnlyTheOldVisitPartial(): void
    {
        $entity = $this->opportunity();
        $this->store($entity);
        $entity->set('opportunityStageId', 'b');
        $this->save($entity);
        $rows = $this->all('OpportunityStageHistory');
        $this->assertTrue($rows[0]->get('isPartial'));
        $this->assertSame(0, $rows[0]->get('durationSeconds'));
        $this->assertFalse($rows[1]->get('isPartial'));
        $this->assertNull($rows[1]->get('exitedAt'));
    }

    public function testStaleSavesCannotOverwriteAnotherTransitionOrAnAbaRoundTrip(): void
    {
        $entity = $this->opportunity(true);
        $this->save($entity);
        $stale = $this->load('Opportunity', $entity->getId());
        $entity->set('opportunityStageId', 'b');
        $this->save($entity);
        $entity->set('opportunityStageId', 'a');
        $this->save($entity);
        $stale->set('name', 'An unrelated stale save');
        try {
            $this->save($stale);
            $this->fail('A stale save should conflict.');
        } catch (Conflict) {
            $this->assertCount(3, $this->all('OpportunityStageHistory'));
            $this->assertSame($entity->get('currentStageVisitId'),
                $this->load('Opportunity', $entity->getId())->get('currentStageVisitId'));
        }
    }

    public function testHistoryFailureRollsBackParentAndClosingVisit(): void
    {
        $entity = $this->opportunity(true);
        $this->save($entity);
        $first = $entity->get('currentStageVisitId');
        $this->failHistoryInsert = true;
        $entity->set('opportunityStageId', 'b');
        try {
            $this->save($entity);
            $this->fail('Expected an insert failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated ledger insert failure', $e->getMessage());
        }
        $this->assertSame('a', $this->load('Opportunity', $entity->getId())->get('opportunityStageId'));
        $this->assertSame($first, $this->load('Opportunity', $entity->getId())->get('currentStageVisitId'));
        $this->assertNull($this->load('OpportunityStageHistory', $first)->get('exitedAt'));
        $this->assertCount(1, $this->all('OpportunityStageHistory'));
    }

    public function testHistoryIncludesActiveTimeAndUsesParentAccess(): void
    {
        $entity = $this->opportunity(true);
        $this->save($entity);
        $this->now = '2026-09-17 12:00:00';
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkEntityRead')->willReturn(true);
        $acl->method('checkField')->willReturn(true);
        $history = new StageHistory($this->em, $acl, $this->clock);
        $result = $history->get($entity->getId());
        $this->assertSame(7200, $result->list[0]->elapsedSeconds);
        $this->assertSame(3600, $result->list[0]->overdueSeconds);
        $this->assertSame(7200, $result->summary[0]['elapsedSeconds']);
        $denied = $this->createMock(Acl::class);
        $denied->method('checkScope')->willReturn(true);
        $denied->method('checkEntityRead')->willReturn(false);
        $this->expectException(Forbidden::class);
        (new StageHistory($this->em, $denied, $this->clock))->get($entity->getId());
    }

    private function opportunity(bool $new = false): Entity
    {
        return $this->entity('Opportunity', [
            'id' => $new ? null : 'opportunity', 'name' => 'Deal', 'funnelId' => 'funnel',
            'opportunityStageId' => 'a', 'status' => 'Open', 'currentStageVisitId' => null,
        ], $new);
    }

    private function entity(string $type, array $data, bool $new = false): BaseEntity
    {
        $fields = [
            'id', 'name', 'opportunityId', 'opportunityStageId', 'funnelId', 'status',
            'stageId', 'stageName', 'funnelName', 'enteredAt', 'exitedAt', 'dueAt', 'kind',
            ...StageTiming::CACHE_FIELDS,
        ];
        $attributes = array_fill_keys($fields, ['type' => 'varchar']);
        foreach (['sequence', 'durationSeconds', 'targetTimeSeconds', 'stageTargetTimeSeconds'] as $field) {
            $attributes[$field] = ['type' => 'int'];
        }
        foreach (['isPartial', 'stageTimingIsPartial'] as $field) {
            $attributes[$field] = ['type' => 'bool'];
        }
        $entity = new BaseEntity($type, ['attributes' => $attributes]);
        $entity->set($data);
        if (!$new) $entity->setAsFetched();
        return $entity;
    }

    private function save(Entity $entity): void
    {
        $this->repository->save($entity);
    }

    private function store(Entity $entity): void
    {
        $statement = $this->pdo->prepare('INSERT OR REPLACE INTO records VALUES (?, ?, ?)');
        $statement->execute([$entity->getEntityType(), $entity->getId(), json_encode($entity->getValueMap())]);
    }

    private function load(string $type, string $id): ?Entity
    {
        $statement = $this->pdo->prepare('SELECT body FROM records WHERE type = ? AND id = ?');
        $statement->execute([$type, $id]);
        $body = $statement->fetchColumn();
        return $body === false ? null : $this->entity($type, json_decode($body, true));
    }

    private function all(string $type): array
    {
        $statement = $this->pdo->prepare('SELECT body FROM records WHERE type = ?');
        $statement->execute([$type]);
        $rows = array_map(fn ($body) => $this->entity($type, json_decode($body, true)), $statement->fetchAll(PDO::FETCH_COLUMN));
        usort($rows, fn ($a, $b) => ($a->get('sequence') ?? 0) <=> ($b->get('sequence') ?? 0));
        return $rows;
    }
}
