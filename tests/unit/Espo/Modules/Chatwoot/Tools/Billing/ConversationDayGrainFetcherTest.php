<?php

namespace tests\unit\Espo\Modules\Chatwoot\Tools\Billing;

use Espo\Core\Select\OrmSelectBuilder;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Billing\ConversationDayGrainFetcher;
use Espo\Modules\Chatwoot\Tools\Billing\DayExpression;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use PDO;
use PHPUnit\Framework\TestCase;

class ConversationDayGrainFetcherTest extends TestCase
{
    public function testAggregationAndDrilldownIncludeOpportunityOnlyRunsWithinAclAndDateScope(): void
    {
        // Execute the real MySQL-composed queries against an in-memory fixture;
        // compile them with PostgreSQL too, since both CRM dialects are supported.
        $pdo = new PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('IF', static fn ($condition, $yes, $no) => $condition ? $yes : $no);
        $pdo->sqliteCreateFunction('DATE_FORMAT', static fn ($date, $format) => substr($date, 0, 10));
        $pdo->exec('CREATE TABLE chatwoot_ai_agent_run (id TEXT, deleted INTEGER DEFAULT 0, kind TEXT,
            conversation_id TEXT, opportunity_id TEXT, tenant_id TEXT, run_at TEXT)');
        $insert = $pdo->prepare('INSERT INTO chatwoot_ai_agent_run
            (id, kind, conversation_id, opportunity_id, tenant_id, run_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([
            ['opp-run', 'opportunity-mention', null, 'same-id', 'tenant', '2026-09-18 03:00:00'],
            ['conv-run', 'customer-message', 'same-id', null, 'tenant', '2026-09-18 03:00:00'],
            ['unscoped', 'opportunity-mention', null, null, 'tenant', '2026-09-18 03:00:00'],
            ['unresolved-conv', 'customer-message', null, 'same-id', 'tenant', '2026-09-18 03:00:00'],
            ['other-tenant', 'opportunity-mention', null, 'opp', 'other', '2026-09-18 03:00:00'],
            ['next-day', 'opportunity-mention', null, 'opp', 'tenant', '2026-09-19 03:00:00'],
        ] as $row) $insert->execute($row);

        $attributes = [];
        foreach (['id', 'kind', 'conversationId', 'opportunityId', 'tenantId'] as $name) {
            $attributes[$name] = ['type' => 'varchar'];
        }
        $attributes['runAt'] = ['type' => 'datetime'];
        $attributes['deleted'] = ['type' => 'bool'];
        $defs = ['attributes' => $attributes, 'relations' => []];
        $metadataProvider = $this->createStub(MetadataDataProvider::class);
        $metadataProvider->method('get')->willReturn(['ChatwootAiAgentRun' => $defs]);
        $metadata = new Metadata($metadataProvider);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('create')->willReturn(new BaseEntity('ChatwootAiAgentRun', $defs));
        $mysql = new MysqlQueryComposer($pdo, $entityFactory, $metadata);
        $postgres = new PostgresqlQueryComposer($pdo, $entityFactory, $metadata);

        $user = $this->createStub(User::class);
        $select = $this->createMock(SelectBuilder::class);
        $select->expects($this->exactly(2))->method('from')->with('ChatwootAiAgentRun')->willReturnSelf();
        $select->expects($this->exactly(2))->method('withStrictAccessControl')->willReturnSelf();
        $select->expects($this->exactly(2))->method('forUser')->with($user)->willReturnSelf();
        $select->method('withSearchParams')->willReturnSelf();
        // Represents the tenant restriction supplied by the existing ACL builder.
        $select->method('buildQueryBuilder')->willReturnCallback(
            static fn () => (new OrmSelectBuilder())->from('ChatwootAiAgentRun')->where(['tenantId' => 'tenant'])
        );
        $factory = $this->createStub(SelectBuilderFactory::class);
        $factory->method('create')->willReturn($select);
        $executor = $this->createMock(QueryExecutor::class);
        $executor->expects($this->exactly(2))->method('execute')->willReturnCallback(function ($query) use ($pdo, $mysql, $postgres) {
            $pgSql = $postgres->compose($query);
            $this->assertStringContainsString('opportunity_id', $pgSql);
            $this->assertStringContainsString('CASE', $pgSql);
            return $pdo->query($mysql->compose($query));
        });
        $em = $this->createStub(EntityManager::class);
        $em->method('getQueryExecutor')->willReturn($executor);
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn('UTC');
        $fetcher = new ConversationDayGrainFetcher($em, $factory, new DayExpression($config));

        $grains = $fetcher->fetch(null, $user);
        $this->assertCount(3, $grains);
        $this->assertSame(3, array_sum(array_map(static fn ($grain) => $grain->totalTurns(), $grains)));
        $ids = $fetcher->fetchRunIdsForBucket(SearchParams::create(), $user, '2026-09-18', 'tenant');
        sort($ids);
        $this->assertSame(['conv-run', 'opp-run'], $ids);
    }
}
