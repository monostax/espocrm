<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Rebuild;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDb1043Platform;
use Doctrine\DBAL\Schema\Schema;
use Espo\Core\Utils\Database\Helper;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Rebuild\EnsureTransitionScopeColumn;
use PHPUnit\Framework\TestCase;

class EnsureTransitionScopeColumnTest extends TestCase
{
    public function testAddsAndBackfillsMissingScopeBeforeSchemaDiff(): void
    {
        $oldSchema = $this->createSchema(false);
        $newSchema = $this->createSchema(true);
        $queries = [];

        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MariaDb1043Platform());
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$queries): int {
                $queries[] = $sql;

                return str_starts_with($sql, 'UPDATE') ? 3 : 0;
            },
        );

        $helper = $this->createStub(Helper::class);
        $helper->method('getDbalConnection')->willReturn($connection);

        $log = $this->createStub(Log::class);
        $action = new EnsureTransitionScopeColumn($helper, $log);

        $action->process($oldSchema, $newSchema);

        $this->assertTrue($oldSchema->getTable('journey_transition')->hasColumn('scope'));
        $this->assertCount(2, $queries);
        $this->assertStringContainsString(
            'ALTER TABLE journey_transition ADD scope VARCHAR(255) DEFAULT NULL',
            $queries[0],
        );
        $this->assertStringContainsString("THEN 'stage'", $queries[1]);
        $this->assertStringContainsString("ELSE 'enrollment'", $queries[1]);
    }

    public function testExistingScopeOnlyRunsIdempotentBackfill(): void
    {
        $oldSchema = $this->createSchema(true);
        $newSchema = $this->createSchema(true);
        $queries = [];

        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MariaDb1043Platform());
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$queries): int {
                $queries[] = $sql;

                return 0;
            },
        );

        $helper = $this->createStub(Helper::class);
        $helper->method('getDbalConnection')->willReturn($connection);

        $action = new EnsureTransitionScopeColumn(
            $helper,
            $this->createStub(Log::class),
        );

        $action->process($oldSchema, $newSchema);

        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('UPDATE', $queries[0]);
    }

    private function createSchema(bool $withScope): Schema
    {
        $schema = new Schema();
        $table = $schema->createTable('journey_transition');
        $table->addColumn('id', 'string', ['length' => 17]);
        $table->addColumn('from_stage_id', 'string', ['length' => 17, 'notnull' => false]);

        if ($withScope) {
            $table->addColumn('scope', 'string', ['length' => 255, 'notnull' => false]);
        }

        return $schema;
    }
}
