<?php

namespace tests\unit\Espo\Modules\Chatwoot\Tools\Billing;

use Espo\Core\Select\OrmSelectBuilder;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Database\ConfigDataProvider;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Billing\ConversationDayGrainFetcher;
use Espo\Modules\Chatwoot\Tools\Billing\DayExpression;
use Espo\Modules\Chatwoot\ORM\FunctionConverters\AgentRunOutcome;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use Espo\ORM\QueryComposer\Part\FunctionConverterFactory;
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
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value);
        $pdo->exec('CREATE TABLE chatwoot_ai_agent_run (id TEXT, deleted INTEGER DEFAULT 0, kind TEXT,
            conversation_id TEXT, opportunity_id TEXT, tenant_id TEXT, run_at TEXT, model_usage TEXT, billing_waived INTEGER DEFAULT 0)');
        $insert = $pdo->prepare('INSERT INTO chatwoot_ai_agent_run
            (id, kind, conversation_id, opportunity_id, tenant_id, run_at, model_usage) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['opp-run', 'opportunity-mention', null, 'same-id', 'tenant', '2026-09-18 03:00:00'],
            ['conv-run', 'customer-message', 'same-id', null, 'tenant', '2026-09-18 03:00:00'],
            ['unscoped', 'opportunity-mention', null, null, 'tenant', '2026-09-18 03:00:00'],
            ['unresolved-conv', 'customer-message', null, 'same-id', 'tenant', '2026-09-18 03:00:00'],
            ['other-tenant', 'opportunity-mention', null, 'opp', 'other', '2026-09-18 03:00:00'],
            ['next-day', 'opportunity-mention', null, 'opp', 'tenant', '2026-09-19 03:00:00'],
        ] as $row) $insert->execute([...$row, null]);
        foreach (['failed', 'completed', 'cancelled', 'superseded'] as $outcome) {
            $insert->execute([$outcome, 'customer-message', 'same-id', null, 'tenant', '2026-09-18 03:00:00', json_encode(['run' => ['outcome' => $outcome]])]);
        }
        $insert->execute(['failed-opp', 'opportunity-mention', null, 'same-id', 'tenant', '2026-09-18 03:00:00', '{"run":{"outcome":"failed"}}']);
        $insert->execute(['failed-only', 'customer-message', 'failed-only', null, 'tenant', '2026-09-18 03:00:00', '{"run":{"outcome":"failed"}}']);
        $insert->execute(['waived', 'customer-message', 'same-id', null, 'tenant', '2026-09-18 03:00:00', null]);
        $pdo->exec("UPDATE chatwoot_ai_agent_run SET billing_waived = 1 WHERE id = 'waived'");
        $pdo->exec("UPDATE chatwoot_ai_agent_run SET billing_waived = NULL WHERE id = 'conv-run'");

        $attributes = [];
        foreach (['id', 'kind', 'conversationId', 'opportunityId', 'tenantId'] as $name) {
            $attributes[$name] = ['type' => 'varchar'];
        }
        $attributes['runAt'] = ['type' => 'datetime'];
        $attributes['deleted'] = ['type' => 'bool'];
        $attributes['billingWaived'] = ['type' => 'bool'];
        $attributes['modelUsage'] = ['type' => 'jsonObject'];
        $entityMetadata = json_decode(file_get_contents('custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootAiAgentRun.json'), true);
        $attributes['runOutcome'] = array_intersect_key($entityMetadata['fields']['runOutcome'], array_flip(['type', 'notStorable', 'select']));
        $defs = ['attributes' => $attributes, 'relations' => []];
        $metadataProvider = $this->createStub(MetadataDataProvider::class);
        $metadataProvider->method('get')->willReturn(['ChatwootAiAgentRun' => $defs]);
        $metadata = new Metadata($metadataProvider);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('create')->willReturn(new BaseEntity('ChatwootAiAgentRun', $defs));
        $converterFactory = function ($platform) {
            $factory = $this->createStub(FunctionConverterFactory::class);
            $factory->method('isCreatable')->willReturnCallback(static fn ($name) => $name === 'AI_RUN_OUTCOME');
            $config = $this->createStub(ConfigDataProvider::class);
            $config->method('getPlatform')->willReturn($platform);
            $factory->method('create')->willReturn(new AgentRunOutcome($config));
            return $factory;
        };
        $mysql = new MysqlQueryComposer($pdo, $entityFactory, $metadata, $converterFactory('Mysql'));
        $postgres = new PostgresqlQueryComposer($pdo, $entityFactory, $metadata, $converterFactory('Postgresql'));

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
            $this->assertStringContainsString("#>> '{run,outcome}'", $pgSql);
            $this->assertStringContainsString('COALESCE', $pgSql);
            return $pdo->query($mysql->compose($query));
        });
        $em = $this->createStub(EntityManager::class);
        $em->method('getQueryExecutor')->willReturn($executor);
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn('UTC');
        $fetcher = new ConversationDayGrainFetcher($em, $factory, new DayExpression($config));

        $grains = $fetcher->fetch(null, $user);
        $this->assertCount(3, $grains);
        $this->assertSame('same-id', $grains[0]->conversationId);
        $this->assertSame('same-id', $grains[1]->opportunityId);
        $this->assertSame('2026-09-19', $grains[2]->dayBucket);
        $this->assertSame(6, array_sum(array_map(static fn ($grain) => $grain->totalTurns(), $grains)));
        $ids = $fetcher->fetchRunIdsForBucket(SearchParams::create(), $user, '2026-09-18', 'tenant');
        sort($ids);
        $this->assertSame(['cancelled', 'completed', 'conv-run', 'opp-run', 'superseded'], $ids);

        // The dashboard selects this non-stored field through the repository;
        // verify both SQL compilation and Entity hydration, not just raw aliases.
        $query = (new OrmSelectBuilder())->from('ChatwootAiAgentRun')->select(['id', 'runOutcome'])->build();
        $this->assertStringContainsString("#>> '{run,outcome}'", $postgres->compose($query));
        $outcomes = [];
        foreach ($pdo->query($mysql->compose($query))->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entity = new BaseEntity('ChatwootAiAgentRun', $defs);
            $entity->set($row);
            $outcomes[$entity->getId()] = $entity->get('runOutcome');
        }
        $this->assertSame('failed', $outcomes['failed']);
        $this->assertSame('failed', $outcomes['failed-opp']);
        $this->assertSame('cancelled', $outcomes['cancelled']);
        $this->assertSame('superseded', $outcomes['superseded']);
        $this->assertNull($outcomes['conv-run']);
    }
}
