<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Utils\Database\ConfigDataProvider;
use Espo\Core\Utils\Database\Dbal\Types\MediumtextType;
use Espo\Core\Utils\Database\Helper;
use Espo\Core\Utils\Database\MetadataProvider;
use Espo\Core\Utils\Database\Orm\Converter;
use Espo\Core\Utils\Database\Orm\IndexHelperFactory;
use Espo\Core\Utils\Database\Orm\IndexHelpers\MysqlIndexHelper;
use Espo\Core\Utils\Database\Orm\IndexHelpers\PostgresqlIndexHelper;
use Espo\Core\Utils\Database\Orm\RelationConverter;
use Espo\Core\Utils\Database\Schema\Builder;
use Espo\Core\Utils\Database\Schema\ColumnPreparatorFactory;
use Espo\Core\Utils\Database\Schema\ColumnPreparators\MysqlColumnPreparator;
use Espo\Core\Utils\Database\Schema\ColumnPreparators\PostgresqlColumnPreparator;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Metadata\Helper as MetadataHelper;
use Espo\Modules\FeatureCredits\Classes\Database\RequiredFields;
use Espo\Modules\FeatureCredits\Classes\RecordHooks\DenyMutation;
use Espo\ORM\Entity;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

trait SchemaFixture
{
    private const ROOT = 'custom/Espo/Modules/FeatureCredits/Resources/metadata/';

    private function metadata(): array
    {
        $data = [];
        foreach (['entityDefs', 'scopes', 'recordDefs'] as $category) {
            foreach (glob(self::ROOT . "$category/*.json") as $path) {
                $data[$category][basename($path, '.json')] = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        foreach (glob('application/Espo/Resources/metadata/fields/*.json') as $path) {
            $data['fields'][basename($path, '.json')] = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }
        return $data;
    }

    /** Exercise the real Espo metadata converter and schema builder, with no application DB/config. */
    private function schema(string $dialect): Schema
    {
        $data = $this->metadata();
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(static function ($path, $default = null) use ($data) {
            $value = $data;
            foreach (is_array($path) ? $path : explode('.', $path) as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    return $default;
                }
                $value = $value[$key];
            }
            return $value;
        });
        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->willReturnCallback(static fn ($class) =>
            $class === RequiredFields::class ? new RequiredFields($metadata) : new $class());
        $config = $this->createMock(ConfigDataProvider::class);
        $config->method('getPlatform')->willReturn($dialect);
        $provider = $this->createMock(MetadataProvider::class);
        // Production Espo defaults to 17, including every generated link column.
        // Using 24 here previously hid accounting writes that could not fit CRM.
        $provider->method('getIdLength')->willReturn(17);
        $provider->method('getIdDbType')->willReturn('string');
        $indexes = $this->createMock(IndexHelperFactory::class);
        $indexes->method('create')->willReturn($dialect === 'Mysql' ? new MysqlIndexHelper() : new PostgresqlIndexHelper());
        $log = $this->createMock(Log::class);
        $converter = new Converter(
            $metadata, new RelationConverter($metadata, $factory, $log), new MetadataHelper($metadata),
            $factory, $config, $indexes, $provider
        );
        $helper = $this->createMock(Helper::class);
        $columns = $this->createMock(ColumnPreparatorFactory::class);
        $columns->method('create')->willReturn($dialect === 'Mysql' ?
            new MysqlColumnPreparator($helper) : new PostgresqlColumnPreparator());
        if (!Type::hasType(MediumtextType::NAME)) {
            Type::addType(MediumtextType::NAME, MediumtextType::class);
        }
        return (new Builder($log, $factory, $config, $columns, $provider))->build($converter->process());
    }

}

class SchemaTest extends TestCase
{
    use SchemaFixture;

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['Mysql'];
        yield 'PostgreSQL' => ['Postgresql'];
    }

    #[DataProvider('dialects')]
    public function testGeneratedFinancialSchema(string $dialect): void
    {
        $schema = $this->schema($dialect);
        $this->assertCount(13, $schema->getTables());
        foreach ($schema->getTables() as $table) {
            if ($table->getName() !== 'ai_model_credit_rate') {
                $this->assertTrue($table->getColumn('tenant_id')->getNotnull(), $table->getName());
            }
            $this->assertSame(17, $table->getColumn('id')->getLength());
            $this->assertTrue($table->hasPrimaryKey());
        }
        $wallet = $schema->getTable('tenant_credit_balance');
        $this->assertSame(14, $wallet->getColumn('balance')->getPrecision());
        $this->assertSame(4, $wallet->getColumn('balance')->getScale());
        $this->assertSame('decimal', Type::lookupName($wallet->getColumn('balance')->getType()));
        $this->assertTrue($wallet->getColumn('balance')->getNotnull());
        $this->assertFalse($schema->getTable('credit_request')->getColumn('priced_credits_exact')->getNotnull());
        $this->assertFalse($schema->getTable('credit_usage')->getColumn('settled_credits')->getNotnull());
        $this->assertFalse($schema->getTable('credit_transaction')->getColumn('reversal_of_id')->getNotnull());
        $this->assertTrue($schema->getTable('credit_allocation')->getColumn('grant_id')->getNotnull());

        foreach ([
            'ai_model_credit_rate' => ['policy_id'],
            'tenant_credit_balance' => ['tenant_id'],
            'tenant_credit_cutover' => ['tenant_id'],
            'credit_execution_route' => ['run_id'],
            'credit_execution_attempt' => ['usage_id', 'request_key'],
            'credit_outcome_delivery' => ['attempt_id', 'phase'],
            'credit_usage' => ['tenant_id', 'operation_type', 'operation_key'],
            'credit_reservation' => ['usage_id'],
            'credit_request' => ['usage_id', 'request_key'],
            'credit_grant' => ['tenant_id', 'source_type', 'source_key'],
            'credit_allocation' => ['reservation_id', 'allocation_key', 'grant_id'],
            'credit_transaction' => ['tenant_id', 'type', 'idempotency_key'],
        ] as $table => $columns) {
            $matching = array_filter($schema->getTable($table)->getIndexes(),
                static fn ($index) => $index->isUnique() && $index->getColumns() === $columns);
            $this->assertCount(1, $matching, $table);
        }
        $sql = $schema->toSql($dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform());
        $this->assertNotEmpty($sql);
    }

    public function testGenericMutationHooksCannotBeBypassedWithParameters(): void
    {
        $entity = $this->createMock(Entity::class);
        foreach ([new CreateParams(), new UpdateParams(), new DeleteParams(), 'grant'] as $params) {
            try {
                (new DenyMutation())->process($entity, $params, $entity);
                $this->fail('Financial mutation was allowed.');
            } catch (Forbidden $e) {
                $this->assertStringContainsString('accounting services', $e->getMessage());
            }
        }
    }

    public function testEveryFinancialEntityHasCrudAndRelationshipGuards(): void
    {
        $metadata = $this->metadata();
        foreach ($metadata['entityDefs'] as $name => $defs) {
            $scope = $metadata['scopes'][$name];
            $this->assertFalse($scope['object']);
            $this->assertFalse($scope['importable']);
            $this->assertFalse($scope['exportable']);
            $this->assertTrue($scope['aclStrict']);
            foreach (['Create', 'Update', 'Delete', 'Link', 'Unlink'] as $operation) {
                $this->assertContains(DenyMutation::class,
                    $metadata['recordDefs'][$name]['before' . $operation . 'HookClassNameList']);
            }
        }
    }

    /** Opt-in: only an empty, disposable database with this exact name is accepted. */
    #[DataProvider('dialects')]
    public function testDisposableDatabaseSchema(string $dialect): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) {
            $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        }
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = $pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn();
        $this->assertSame('feature_credits_schema_test', $database);
        $count = $pdo->query($dialect === 'Mysql' ?
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" :
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'")->fetchColumn();
        $this->assertSame(0, (int) $count, 'Refusing to modify a nonempty database.');

        $schema = $this->schema($dialect);
        $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) {
                $pdo->exec($sql);
            }
            foreach ($schema->getTables() as $table) {
                $name = $table->getName();
                $row = [];
                foreach ($table->getColumns() as $column) {
                    if (!$column->getNotnull()) {
                        continue;
                    }
                    $row[$column->getName()] = match (Type::lookupName($column->getType())) {
                        'decimal' => '0.0001',
                        'boolean', 'integer' => '0',
                        'datetime' => '2026-10-10 12:00:00',
                        'mediumtext', 'text', 'json' => '{}',
                        default => 'a',
                    };
                }
                $insert = static function (array $values) use ($pdo, $platform, $name): void {
                    $columns = array_map($platform->quoteIdentifier(...), array_keys($values));
                    $sql = 'INSERT INTO ' . $platform->quoteIdentifier($name) . ' (' . implode(',', $columns) .
                        ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')';
                    $pdo->prepare($sql)->execute(array_values($values));
                };
                if ($name === 'credit_execution_route') $row['usage_id'] = 'a';
                if ($name === 'credit_execution_attempt') $row['request_id'] = 'a';
                $insert($row);

                // Each unique index must reject a conflicting row independently of the primary key.
                foreach ($table->getIndexes() as $index) {
                    if (!$index->isUnique() || $index->isPrimary()) {
                        continue;
                    }
                    $duplicate = $row;
                    foreach ($duplicate as $key => &$value) {
                        if ($key === 'id' || str_ends_with($key, '_id') ||
                            in_array($key, ['operation_key', 'request_key', 'allocation_key', 'source_key', 'idempotency_key', 'version'])) {
                            $value = 'b';
                        }
                    }
                    unset($value);
                    foreach ($index->getColumns() as $column) {
                        $duplicate[$column] = $row[$column];
                    }
                    try {
                        $insert($duplicate);
                        $this->fail("Unique constraint not enforced: $name/{$index->getName()}");
                    } catch (PDOException $e) {
                        $this->assertContains((string) $e->getCode(), ['23000', '23505']);
                    }
                }

                if ($name === 'ai_model_credit_rate') continue; // Global catalog, not tenant finance.
                $missingTenant = $row;
                $missingTenant['id'] = 'c';
                $missingTenant['tenant_id'] = null;
                try {
                    $insert($missingTenant);
                    $this->fail("Tenant ownership is nullable: $name");
                } catch (PDOException $e) {
                    $this->assertContains((string) $e->getCode(), ['23000', '23502']);
                }
            }

            foreach (['9999999999.9999', '-9999999999.9999', '0.0001', '0.0000'] as $amount) {
                $pdo->prepare('UPDATE tenant_credit_balance SET balance = ? WHERE id = ?')->execute([$amount, 'a']);
                $this->assertSame($amount, $pdo->query("SELECT balance FROM tenant_credit_balance WHERE id = 'a'")->fetchColumn());
            }
            $pdo->beginTransaction();
            $pdo->exec("UPDATE tenant_credit_balance SET balance = 1.0000 WHERE id = 'a'");
            $pdo->rollBack();
            $this->assertSame('0.0000', $pdo->query("SELECT balance FROM tenant_credit_balance WHERE id = 'a'")->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach (array_reverse($schema->getTables()) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
            }
        }
    }
}
