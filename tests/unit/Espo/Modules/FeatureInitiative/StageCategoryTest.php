<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureInitiative;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureInitiative\Hooks\InitiativeStage\SyncInitiativeStatus;
use Espo\Modules\FeatureInitiative\Hooks\InitiativeStage\ValidateInitiativeType;
use Espo\Modules\FeatureInitiative\Rebuild\BackfillStageCategories;
use Espo\Modules\FeatureInitiative\Services\InitiativeTypeAccess;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\QueryBuilder;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use Espo\ORM\Repository\Option\SaveOptions;
use PDO;

class StageCategoryTest extends TestCase
{
    public function testNewStageDefaultsToOpen(): void
    {
        $access = $this->createMock(InitiativeTypeAccess::class);
        $access->method('requireParent')->willReturn($this->entity('InitiativeType'));
        $stage = $this->entity('InitiativeStage');
        (new ValidateInitiativeType($access))->beforeSave($stage, SaveOptions::fromAssoc([]));
        self::assertSame('Open', $stage->get('category'));
    }

    public function testInvalidCategoryIsRejectedOnOrmWrites(): void
    {
        $access = $this->createMock(InitiativeTypeAccess::class);
        $access->method('requireParent')->willReturn($this->entity('InitiativeType'));
        $this->expectException(BadRequest::class);
        (new ValidateInitiativeType($access))->beforeSave(
            $this->entity('InitiativeStage', ['category' => 'Done']), SaveOptions::fromAssoc([]),
        );
    }

    public function testBackfillAndCategoryChangesKeepStoredStatusesInSync(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE initiative_stage (id TEXT, category TEXT, deleted INTEGER DEFAULT 0)');
        $pdo->exec('CREATE TABLE initiative (id TEXT, stage_id TEXT, status TEXT, deleted INTEGER DEFAULT 0)');
        $pdo->exec("INSERT INTO initiative_stage (id, category) VALUES ('old', NULL), ('empty', ''), ('configured', 'Completed')");
        $pdo->exec("INSERT INTO initiative (id, stage_id, status, deleted) VALUES
            ('a', 'old', 'Doing', 0), ('b', 'configured', 'To Do', 0),
            ('c', 'old', 'Done', 1), ('d', 'empty', NULL, 0), ('e', 'old', 'On Hold', 0)");

        $defs = [
            'InitiativeStage' => ['attributes' => array_fill_keys(['id', 'category'], ['type' => 'varchar']) + ['deleted' => ['type' => 'bool']]],
            'Initiative' => ['attributes' => array_fill_keys(['id', 'stageId', 'status'], ['type' => 'varchar']) + ['deleted' => ['type' => 'bool']]],
        ];
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        // PostgreSQL UPDATE syntax also executes on SQLite; MySQL's qualified SET does not.
        $composer = new PostgresqlQueryComposer($pdo, $entities, new Metadata($provider));
        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('execute')->willReturnCallback(fn ($query) => $pdo->query($composer->compose($query)));
        $em = $this->createMock(EntityManager::class);
        $em->method('getQueryBuilder')->willReturn(new QueryBuilder());
        $em->method('getQueryExecutor')->willReturn($executor);

        $backfill = new BackfillStageCategories($em);
        $backfill->process();
        self::assertSame(['old' => 'Open', 'empty' => 'Open', 'configured' => 'Completed'],
            $pdo->query('SELECT id, category FROM initiative_stage')->fetchAll(PDO::FETCH_KEY_PAIR));
        $statuses = fn () => $pdo->query('SELECT id, status FROM initiative ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['a' => 'Open', 'b' => 'Completed', 'c' => 'Done', 'd' => 'Open', 'e' => 'Open'], $statuses());
        $backfill->process();
        self::assertSame(0, $pdo->query('SELECT changes()')->fetchColumn());

        $stage = $this->entity('InitiativeStage', ['id' => 'old', 'category' => 'Open'], true);
        $stage->set('category', 'Paused');
        (new SyncInitiativeStatus($em))->afterSave($stage, SaveOptions::fromAssoc([]));
        self::assertSame(['a' => 'Paused', 'b' => 'Completed', 'c' => 'Done', 'd' => 'Open', 'e' => 'Paused'], $statuses());
    }

    public function testRenamingStageDoesNotWriteInitiatives(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getQueryExecutor');
        $stage = $this->entity('InitiativeStage', ['id' => 'stage', 'category' => 'Open'], true);
        $stage->set('name', 'Renamed');
        (new SyncInitiativeStatus($em))->afterSave($stage, SaveOptions::fromAssoc([]));
    }
}
