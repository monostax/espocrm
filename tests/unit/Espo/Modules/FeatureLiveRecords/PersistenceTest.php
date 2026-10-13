<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureLiveRecords;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureLiveRecords\Persistence\Observer;
use Espo\Modules\FeatureLiveRecords\Persistence\Routing;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\CollectionFactory;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\DefaultQueryExecutor;
use Espo\ORM\Executor\SqlExecutor;
use Espo\ORM\Mapper\BaseMapper;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use Espo\ORM\QueryComposer\QueryComposerWrapper;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\TransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;

/** Real SQL/transactions through the modified ORM and production observer. */
final class PersistenceTest extends TestCase
{
    private PDO $pdo;
    private RDBRepository $repository;
    private EntityFactory $factory;

    protected function setUp(): void
    {
        $this->pdo = new PDO(getenv('LIVE_RECORD_TEST_DSN') ?: 'sqlite::memory:',
            getenv('LIVE_RECORD_TEST_USER') ?: null, getenv('LIVE_RECORD_TEST_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE live_test_record (id VARCHAR(17) PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE,
            tenant_id VARCHAR(64), name VARCHAR(255), version_number INTEGER DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE live_record_outbox (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64),
            intent TEXT, created_at TIMESTAMP, due_at TIMESTAMP, attempts INTEGER)');
        $defs = ['concurrencyAttribute' => 'versionNumber', 'attributes' => [
            'id' => ['type' => 'varchar'], 'deleted' => ['type' => 'bool'],
            'tenantId' => ['type' => 'varchar'], 'name' => ['type' => 'varchar'],
            'versionNumber' => ['type' => 'int'],
        ]];
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn(['LiveTestRecord' => $defs]);
        $metadata = new Metadata($provider);
        $this->factory = $this->createMock(EntityFactory::class);
        $this->factory->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs));
        $composerClass = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? MysqlQueryComposer::class : PostgresqlQueryComposer::class;
        $composer = new $composerClass($this->pdo, $this->factory, $metadata);
        $sql = $this->createMock(SqlExecutor::class);
        $sql->method('execute')->willReturnCallback(fn ($query) => $this->pdo->query($query));
        $executor = new DefaultQueryExecutor($sql, new QueryComposerWrapper($composer));
        $mapper = new BaseMapper($this->pdo, $this->factory, $this->createMock(CollectionFactory::class), $metadata, $executor);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);
        $em->method('getMapper')->willReturn($mapper);
        $em->method('getQueryBuilder')->willReturn(new QueryBuilder());
        $em->method('getTransactionManager')->willReturn(new TransactionManager($this->pdo, $composer));
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) =>
            $mapper->selectOne(SelectBuilder::create()->from($type)->where(['id' => $id])->build()));
        $routing = new Routing($em, $this->createMock(TenantResolver::class), $this->createMock(UserTenantResolver::class), ['LiveTestRecord']);
        $this->repository = new RDBRepository('LiveTestRecord', $em, $this->factory);
        $this->repository->setMutationObserver(new Observer($em, $routing));
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE live_record_outbox');
        $this->pdo->exec('DROP TABLE live_test_record');
    }

    private function createRecord(): void
    {
        $record = $this->factory->create('LiveTestRecord');
        $record->set(['id' => 'record', 'tenantId' => 'tenant-a', 'name' => 'original', 'deleted' => false]);
        $this->repository->save($record, ['skipAll' => true]);
    }

    public function testCompetingSnapshotsCannotOverwriteAndSkipHooksCannotBypassOutbox(): void
    {
        $this->createRecord();
        $first = $this->repository->getById('record');
        $second = $this->repository->getById('record');
        $first->set('name', 'winner');
        $this->repository->save($first, ['skipAll' => true]);
        $second->set('name', 'loser');
        try {
            $this->repository->save($second, ['skipAll' => true]);
            self::fail('Competing save was accepted.');
        } catch (Conflict $e) {
            self::assertStringNotContainsString('winner', $e->getMessage());
        }
        self::assertSame('winner', $this->repository->getById('record')->get('name'));
        self::assertSame(2, $this->repository->getById('record')->get('versionNumber'));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn());
    }

    public function testOutboxFailureRollsBackTheRepositoryMutation(): void
    {
        $this->createRecord();
        $this->pdo->exec('ALTER TABLE live_record_outbox RENAME TO live_record_outbox_unavailable');
        $record = $this->repository->getById('record');
        $record->set('name', 'must roll back');
        try {
            $this->repository->save($record);
            self::fail('Outbox failure did not abort save.');
        } catch (\PDOException) {}
        $this->pdo->exec('ALTER TABLE live_record_outbox_unavailable RENAME TO live_record_outbox');
        self::assertSame('original', $this->repository->getById('record')->get('name'));
        self::assertSame(1, $this->repository->getById('record')->get('versionNumber'));
    }

    public function testRepeatedSaveWithoutRecordChangesDoesNotAppendInvalidations(): void
    {
        $this->createRecord();
        for ($i = 0; $i < 5; $i++) {
            $record = $this->repository->getById('record');
            $record->set('name', 'original');
            $this->repository->save($record, ['skipAll' => true]);
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn());
        $record = $this->repository->getById('record');
        $record->set('name', 'changed');
        $this->repository->save($record);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn());
    }

    public function testTenantTransferProducesSeparateTenantLocalIntents(): void
    {
        $this->createRecord();
        $record = $this->repository->getById('record');
        $record->set('tenantId', 'tenant-b');
        $this->repository->save($record);
        $rows = $this->pdo->query('SELECT tenant_id, intent FROM live_record_outbox')->fetchAll(PDO::FETCH_ASSOC);
        $tenants = array_column($rows, 'tenant_id');
        sort($tenants);
        self::assertSame(['tenant-a', 'tenant-a', 'tenant-b'], $tenants);
        foreach ($rows as $row) {
            $intent = json_decode($row['intent'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([['type' => 'LiveTestRecord', 'id' => 'record']], $intent['records']);
            self::assertArrayNotHasKey('tenantId', $intent);
            self::assertArrayNotHasKey('name', $intent);
        }
    }

    public function testDeletionPreventsAStaleSaveFromRecreatingTheRecord(): void
    {
        $this->createRecord();
        $stale = $this->repository->getById('record');
        $this->repository->remove($this->repository->getById('record'));
        $stale->set('name', 'recreated');
        $this->expectException(Conflict::class);
        $this->repository->save($stale);
    }
}
