<?php

namespace tests\unit\Espo\Modules\Chatwoot\Tools\Billing;

use DateTimeImmutable;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Database\ConfigDataProvider;
use Espo\Modules\Chatwoot\ORM\FunctionConverters\AgentRunOutcome;
use Espo\Modules\Chatwoot\Tools\Billing\AiBudget;
use Espo\ORM\{BaseEntity, EntityCollection, EntityFactory, EntityManager, Metadata, MetadataDataProvider, TransactionManager};
use Espo\ORM\Mapper\Mapper;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\{MysqlQueryComposer, PostgresqlQueryComposer};
use Espo\ORM\QueryComposer\Part\FunctionConverterFactory;
use Espo\ORM\Repository\RDBRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/** Real ORM select builders/composers and SQL balances, isolated from app hooks.
 * Set AI_BUDGET_TEST_DSN to an EMPTY disposable database to also test row locking.
 */
class AiBudgetTest extends TestCase
{
    private PDO $pdo;
    private AiBudget $budget;

    protected function setUp(): void
    {
        $this->pdo = $this->connect();
        foreach (['credit_execution_route', 'tenant_credit_cutover', 'ai_usage_reservation', 'chatwoot_ai_agent_run', 'tenant_ai_billing_rate', 'chatwoot_account', 'tenant'] as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS $table");
        }
        $this->pdo->exec('CREATE TABLE tenant (id VARCHAR(17) PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE)');
        $this->pdo->exec('CREATE TABLE tenant_credit_cutover (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE,
            tenant_id VARCHAR(24) UNIQUE, cutover_at TIMESTAMP, input_hash VARCHAR(64), evidence TEXT, created_at TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE credit_execution_route (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE,
            tenant_id VARCHAR(24), run_id VARCHAR(17) UNIQUE, workflow_run_id VARCHAR(64), execution_id VARCHAR(36),
            billing_regime VARCHAR(32), usage_id VARCHAR(24) UNIQUE, cutover_at TIMESTAMP, admitted_at TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE chatwoot_account (id VARCHAR(17) PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE, tenant_id VARCHAR(17), chatwoot_account_id INTEGER)');
        $this->pdo->exec('CREATE TABLE tenant_ai_billing_rate (id VARCHAR(17) PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE,
            tenant_id VARCHAR(17), effective_from DATE, effective_to DATE, overage_policy VARCHAR(16), billing_model VARCHAR(16), plan_included_credits INTEGER)');
        $this->pdo->exec('CREATE TABLE chatwoot_ai_agent_run (id VARCHAR(17) PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE,
            tenant_id VARCHAR(17), run_at TIMESTAMP, model_usage TEXT, billing_waived BOOLEAN DEFAULT FALSE)');
        $this->pdo->exec('CREATE TABLE ai_usage_reservation (id VARCHAR(17) PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE,
            tenant_id VARCHAR(17), run_id VARCHAR(17) UNIQUE, workflow_run_id VARCHAR(64), execution_id VARCHAR(36),
            period VARCHAR(7), state VARCHAR(16), auxiliary BOOLEAN, admitted_at TIMESTAMP, settled_at TIMESTAMP)');
        $this->pdo->exec("INSERT INTO tenant (id) VALUES ('tenant'), ('other')");
        $this->pdo->exec("INSERT INTO chatwoot_account (id, tenant_id, chatwoot_account_id) VALUES ('account1', 'tenant', 1), ('account2', 'tenant', 2), ('account3', 'other', 3)");
        $this->pdo->exec("INSERT INTO tenant_ai_billing_rate (id, tenant_id, effective_from, billing_model, overage_policy, plan_included_credits)
            VALUES ('rate', 'tenant', '2020-01-01', 'credit', 'block', 600)");
        $this->budget = $this->service($this->pdo);
    }

    private function connect(): PDO
    {
        $dsn = getenv('AI_BUDGET_TEST_DSN') ?: 'sqlite::memory:';
        if ($dsn !== 'sqlite::memory:' && !str_contains($dsn, 'dbname=ai_budget_test')) {
            throw new \RuntimeException('Only the disposable ai_budget_test database is permitted.');
        }
        return new PDO($dsn, getenv('AI_BUDGET_TEST_USER') ?: null, getenv('AI_BUDGET_TEST_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function service(PDO $pdo): AiBudget
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') $pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value);
        $definitions = [];
        foreach ([
            'Tenant' => [],
            'ChatwootAccount' => ['tenantId', 'chatwootAccountId'],
            'TenantAiBillingRate' => ['tenantId', 'effectiveFrom', 'effectiveTo', 'overagePolicy', 'billingModel', 'planIncludedCredits'],
            'ChatwootAiAgentRun' => ['tenantId', 'runAt', 'modelUsage', 'billingWaived'],
            'AiUsageReservation' => ['tenantId', 'runId', 'workflowRunId', 'executionId', 'period', 'state', 'auxiliary', 'admittedAt', 'settledAt'],
        ] as $type => $fields) {
            $attributes = [];
            foreach (['id', 'deleted', ...$fields] as $field) {
                $attributes[$field] = ['type' => in_array($field, ['deleted', 'auxiliary', 'billingWaived']) ? 'bool' : 'varchar'];
            }
            $definitions[$type] = ['attributes' => $attributes, 'relations' => []];
        }
        $definitions['AiUsageReservation']['relations']['run'] = [
            'type' => 'belongsTo', 'entity' => 'ChatwootAiAgentRun', 'key' => 'runId', 'foreignKey' => 'id',
        ];
        $provider = $this->createStub(MetadataDataProvider::class);
        $provider->method('get')->willReturn($definitions);
        $metadata = new Metadata($provider);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('create')->willReturnCallback(static fn ($type) => new BaseEntity($type, $definitions[$type]));
        $composers = [];
        foreach (['Mysql' => MysqlQueryComposer::class, 'Postgresql' => PostgresqlQueryComposer::class] as $platform => $class) {
            $converters = $this->createStub(FunctionConverterFactory::class);
            $converters->method('isCreatable')->willReturnCallback(static fn ($name) => $name === 'AI_RUN_OUTCOME');
            $config = $this->createStub(ConfigDataProvider::class);
            $config->method('getPlatform')->willReturn($platform);
            $converters->method('create')->willReturn(new AgentRunOutcome($config));
            $composers[$platform] = new $class($pdo, $factory, $metadata, $converters);
        }
        $composer = $composers[$driver === 'pgsql' ? 'Postgresql' : 'Mysql'];
        $query = function ($select) use ($pdo, $composer, $driver, $composers) {
            // Compile both dialects, even in the fast SQLite run.
            foreach ($composers as $candidate) $candidate->compose($select);
            $sql = $composer->compose($select);
            if (str_contains($sql, 'FOR UPDATE')) $this->assertTrue($pdo->inTransaction());
            if ($driver === 'sqlite') $sql = str_replace(' FOR UPDATE', '', $sql);
            return $pdo->query($sql);
        };
        $mapper = $this->createStub(Mapper::class);
        $mapper->method('select')->willReturnCallback(static function ($select) use ($query, $factory) {
            $rows = [];
            foreach ($query($select)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $entity = $factory->create($select->getFrom());
                $entity->set($row);
                $entity->setAsNotNew();
                $rows[] = $entity;
            }
            return new EntityCollection($rows);
        });
        $mapper->method('count')->willReturnCallback(static fn ($select) =>
            (int) $query(SelectBuilder::create()->clone($select)->select([["COUNT:id", 'value']])->build())->fetchColumn());
        $em = $this->createStub(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $em->method('getMapper')->willReturn($mapper);
        $em->method('getTransactionManager')->willReturn(new TransactionManager($pdo, $composer));
        $repository = static fn ($type) => new RDBRepository($type, $em, $factory);
        $em->method('getRDBRepository')->willReturnCallback($repository);
        $em->method('getRepository')->willReturnCallback($repository);
        $em->method('getNewEntity')->willReturnCallback(static fn ($type) => $factory->create($type));
        $em->method('saveEntity')->willReturnCallback(static function ($entity) use ($pdo) {
            $fields = ['tenantId', 'runId', 'workflowRunId', 'executionId', 'period', 'state', 'auxiliary', 'admittedAt', 'settledAt'];
            $column = static fn ($name) => strtolower(preg_replace('/[A-Z]/', '_$0', $name));
            $values = array_map(static fn ($name) => $entity->get($name), $fields);
            // PDO pgsql needs explicit boolean strings rather than empty-string false.
            $values[6] = $values[6] ? '1' : '0';
            if ($entity->isNew()) {
                $columns = implode(', ', array_map($column, $fields));
                $pdo->prepare("INSERT INTO ai_usage_reservation (id, $columns) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$entity->getId(), ...$values]);
            } else {
                $sets = implode(', ', array_map(static fn ($name) => $column($name) . ' = ?', $fields));
                $pdo->prepare("UPDATE ai_usage_reservation SET $sets WHERE id = ?")->execute([...$values, $entity->getId()]);
            }
        });
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn('UTC');
        return new AiBudget($em, $config);
    }

    private function admit(int $number, int $accountId = 1): object
    {
        return (object) ['operation' => 'admit', 'accountId' => $accountId,
            'runId' => substr(hash('sha256', (string) $number), 0, 17), 'workflowRunId' => "workflow-$number",
            'executionId' => '00000000-0000-4000-8000-000000000000'];
    }

    private function seed(int $count): void
    {
        $insert = $this->pdo->prepare('INSERT INTO chatwoot_ai_agent_run (id, tenant_id, run_at) VALUES (?, ?, ?)');
        for ($i = 0; $i < $count; $i++) $insert->execute(["legacy-$i", 'tenant', gmdate('Y-m-d H:i:s')]);
    }

    public function testLastEngagementFinishesAndOtherAccountsCannotTakeItsSlot(): void
    {
        $this->seed(599);
        $last = $this->admit(600);
        $this->assertTrue($this->budget->execute($last)->allowed);
        $this->assertTrue($this->budget->execute($last)->allowed, 'Lost HTTP response can recover authorization');
        $this->assertFalse($this->budget->execute($this->admit(601, 2))->allowed);
        $this->assertTrue($this->budget->execute($this->admit(601, 3))->allowed, 'Other tenant is independent');
        $duplicateWorker = clone $last;
        $duplicateWorker->executionId = '11111111-1111-4111-8111-111111111111';
        $this->assertFalse($this->budget->execute($duplicateWorker)->allowed);
        $receipt = $this->budget->execute((object) [...(array) $last, 'operation' => 'settle', 'billable' => true]);
        $this->assertTrue($receipt->settled);
        $this->assertFalse($this->budget->execute($this->admit(602))->allowed);
        $this->assertFalse($this->budget->execute($last)->allowed, 'Finished engagement cannot restart');
        // Event persistence must not double-count the already settled reservation.
        $this->pdo->prepare('INSERT INTO chatwoot_ai_agent_run (id, tenant_id, run_at) VALUES (?, ?, ?)')
            ->execute([$last->runId, 'tenant', $receipt->admittedAt]);
        $status = $this->budget->execute((object) ['operation' => 'status', 'accountId' => 1]);
        $this->assertSame(600, $status->consumed);
        $this->assertSame(0, $status->reserved);
        $this->pdo->prepare('UPDATE chatwoot_ai_agent_run SET billing_waived = TRUE WHERE id = ?')->execute([$last->runId]);
        $this->assertTrue($this->budget->execute($this->admit(603))->allowed);
    }

    public function testFailedAndAuxiliaryWorkReleaseCapacityButUnknownWorkDoesNotExpire(): void
    {
        $this->seed(599);
        $request = $this->admit(1);
        $this->assertTrue($this->budget->execute($request)->allowed);
        $this->pdo->exec("UPDATE ai_usage_reservation SET admitted_at = '2020-01-01 00:00:00'");
        $this->assertFalse($this->budget->execute($this->admit(2))->allowed, 'No unsafe TTL release');
        $this->budget->execute((object) [...(array) $request, 'operation' => 'settle', 'billable' => false]);
        $aux = (object) [...(array) $this->admit(3), 'auxiliary' => true];
        $this->assertTrue($this->budget->execute($aux)->allowed);
        $this->assertFalse($this->budget->execute($this->admit(4))->allowed);
        $this->budget->execute((object) [...(array) $aux, 'operation' => 'settle', 'billable' => true]);
        $this->assertTrue($this->budget->execute($this->admit(4))->allowed);
    }

    public function testAllowOverageZeroAllowanceAndPriorMonth(): void
    {
        $this->pdo->exec('UPDATE tenant_ai_billing_rate SET plan_included_credits = 0');
        $this->assertFalse($this->budget->execute($this->admit(1))->allowed);
        $this->pdo->exec("UPDATE tenant_ai_billing_rate SET overage_policy = 'allow'");
        $this->assertTrue($this->budget->execute($this->admit(2))->allowed);
        $this->pdo->exec("UPDATE tenant_ai_billing_rate SET overage_policy = 'block', plan_included_credits = 1");
        $this->assertFalse($this->budget->execute($this->admit(3))->allowed);
        $this->pdo->exec("UPDATE ai_usage_reservation SET period = '2020-01'");
        $this->assertTrue($this->budget->execute($this->admit(3))->allowed);
    }

    public function testLegacyFailuresWaiversAndAgreementEditsDoNotResetUsage(): void
    {
        $this->seed(600);
        $this->assertFalse($this->budget->execute($this->admit(1))->allowed);
        $this->pdo->exec("UPDATE chatwoot_ai_agent_run SET model_usage = '{\"run\":{\"outcome\":\"failed\"}}' WHERE id = 'legacy-0'");
        $this->assertTrue($this->budget->execute($this->admit(1))->allowed);
        $this->pdo->exec("UPDATE tenant_ai_billing_rate SET plan_included_credits = 601");
        $this->assertTrue($this->budget->execute($this->admit(2))->allowed);
        $this->assertFalse($this->budget->execute($this->admit(3))->allowed);
        $this->pdo->exec("UPDATE tenant_ai_billing_rate SET effective_to = '2020-01-02'");
        $this->assertSame('ai_budget_configuration_required', $this->budget->execute($this->admit(3))->reason);
        $this->pdo->exec("INSERT INTO tenant_ai_billing_rate (id, tenant_id, effective_from, billing_model, overage_policy, plan_included_credits)
            VALUES ('replacement', 'tenant', '2020-01-03', 'credit', 'block', 600)");
        $this->assertFalse($this->budget->execute($this->admit(3))->allowed, 'Changing agreement identity must not reset consumption');
    }

    public function testInternalEndpointRequiresFreshUntamperedSignature(): void
    {
        $previous = getenv('AI_BUDGET_SECRET');
        putenv('AI_BUDGET_SECRET=test-budget-secret');
        try {
            $controller = new \Espo\Modules\Chatwoot\Controllers\AiBudget($this->budget);
            $body = json_encode(['operation' => 'status', 'accountId' => 1]);
            $timestamp = (string) time();
            $signature = 'sha256=' . hash_hmac('sha256', "ai-budget-v1.$timestamp.$body", 'test-budget-secret');
            $request = function ($raw, $time, $sig) {
                $request = $this->createStub(\Espo\Core\Api\Request::class);
                $request->method('getBodyContents')->willReturn($raw);
                $request->method('getHeader')->willReturnCallback(static fn ($header) =>
                    $header === 'X-Ai-Budget-Timestamp' ? $time : $sig);
                return $request;
            };
            $this->assertTrue($controller->postActionExecute($request($body, $timestamp, $signature))->allowed);
            foreach ([[$body, $timestamp, ''], [$body . ' ', $timestamp, $signature], [$body, (string) (time() - 1000), $signature]] as $values) {
                try {
                    $controller->postActionExecute($request(...$values));
                    $this->fail('Invalid signed budget request accepted');
                } catch (\Espo\Core\Exceptions\Forbidden) { $this->addToAssertionCount(1); }
            }
        } finally { putenv($previous === false ? 'AI_BUDGET_SECRET' : "AI_BUDGET_SECRET=$previous"); }
    }

    public function testPeriodMatchesBillingOffsetAcrossYearBoundary(): void
    {
        $period = AiBudget::period(new DateTimeImmutable('2027-01-01T02:59:59Z'), 'America/Sao_Paulo');
        $this->assertSame('2026-12', $period['period']);
        $this->assertSame('2027-01-01 03:00:00', $period['end']);
        $this->assertSame('2027-01', AiBudget::period(new DateTimeImmutable('2027-01-01T03:00:00Z'), 'America/Sao_Paulo')['period']);
        $dst = AiBudget::period(new DateTimeImmutable('2026-03-15T12:00:00Z'), 'America/New_York');
        $this->assertSame('2026-03-01 05:00:00', $dst['start']);
        $this->assertSame('2026-04-01 04:00:00', $dst['end']);
    }

    public function testConcurrentWorkersAdmitExactlyOneOfEightAt599(): void
    {
        if (!getenv('AI_BUDGET_TEST_DSN') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires a disposable MySQL/PostgreSQL database and pcntl.');
        }
        $this->seed(599);
        // Never share PDO connections between processes.
        unset($this->budget, $this->pdo);
        $children = [];
        $start = microtime(true) + 0.2;
        for ($i = 0; $i < 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) throw new \RuntimeException('Unable to fork budget worker');
            if ($pid === 0) {
                try {
                    $service = $this->service($this->connect());
                    usleep((int) max(0, ($start - microtime(true)) * 1000000));
                    exit($service->execute($this->admit(700 + $i, 1 + $i % 2))->allowed ? 0 : 10);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e->getMessage() . "\n");
                    exit(20);
                }
            }
            $children[] = $pid;
        }
        $admitted = 0;
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $exit = pcntl_wexitstatus($status);
            $this->assertContains($exit, [0, 10]);
            if ($exit === 0) $admitted++;
        }
        $this->assertSame(1, $admitted);
        $this->pdo = $this->connect();
        $this->budget = $this->service($this->pdo);
        $status = $this->budget->execute((object) ['operation' => 'status', 'accountId' => 1]);
        $this->assertSame(599, $status->consumed);
        $this->assertSame(1, $status->reserved);
        $this->assertFalse($status->allowed);
    }

    public function testCutoverBlocksNewLegacyWorkButPreservesAdmittedExecutionAndSettlement(): void
    {
        $admission = $this->admit(1);
        $first = $this->budget->execute($admission);
        $this->assertSame('legacy-engagement-v1', $first->billingRegime);
        $this->pdo->exec("INSERT INTO tenant_credit_cutover (id, tenant_id, cutover_at) VALUES ('cutover', 'tenant', '2020-01-01 00:00:00')");
        $this->assertTrue($this->budget->execute($admission)->allowed);
        $blocked = $this->budget->execute($this->admit(2));
        $this->assertFalse($blocked->allowed);
        $this->assertSame('unified-prepaid-v1', $blocked->billingRegime);
        $this->assertSame('ai_credit_execution_required', $blocked->reason);
        $this->assertFalse($this->budget->execute((object) ['operation' => 'status', 'accountId' => 2])->allowed);
        $this->assertTrue($this->budget->execute($this->admit(2, 3))->allowed);
        $settled = $this->budget->execute((object) [...(array) $admission, 'operation' => 'settle', 'billable' => true]);
        $this->assertTrue($settled->settled);
        $this->assertSame('legacy-engagement-v1', $settled->billingRegime);
        $this->assertSame($first->admittedAt, $settled->admittedAt);
        $unknown = $this->budget->execute((object) [...(array) $this->admit(3), 'operation' => 'settle', 'billable' => false]);
        $this->assertFalse($unknown->settled);
        $this->assertNull($unknown->billingRegime);
        $this->assertSame('ai_budget_not_admitted', $unknown->reason);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM credit_execution_route')->fetchColumn());
    }

    public function testHistoricalAdmissionIsAdoptedAfterCutoverWithoutRepricing(): void
    {
        $admission = $this->admit(1);
        $this->budget->execute($admission);
        $this->pdo->exec('DELETE FROM credit_execution_route'); // Pre-route legacy fixture.
        $this->pdo->exec("UPDATE ai_usage_reservation SET admitted_at = '2020-01-01 00:00:00'");
        $this->pdo->exec("INSERT INTO tenant_credit_cutover (id, tenant_id, cutover_at) VALUES ('cutover', 'tenant', '2021-01-01 00:00:00')");
        $receipt = $this->budget->execute((object) [...(array) $admission, 'operation' => 'settle', 'billable' => true]);
        $this->assertTrue($receipt->settled);
        $this->assertSame('2020-01-01 00:00:00', $this->pdo->query('SELECT admitted_at FROM credit_execution_route')->fetchColumn());
        $this->assertSame('legacy-engagement-v1', $this->pdo->query('SELECT billing_regime FROM credit_execution_route')->fetchColumn());
    }

    public function testUnifiedRouteCannotBeSettledOrReadmittedThroughLegacyProtocol(): void
    {
        $input = $this->admit(1);
        $this->pdo->prepare('INSERT INTO credit_execution_route
            (id, tenant_id, run_id, workflow_run_id, execution_id, billing_regime, usage_id, admitted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute(['route', 'tenant', $input->runId, $input->workflowRunId,
                $input->executionId, 'unified-prepaid-v1', 'usage', '2020-01-01 00:00:00']);
        // No current cutover configuration: persisted route still wins.
        $this->assertFalse($this->budget->execute($input)->allowed);
        $receipt = $this->budget->execute((object) [...(array) $input, 'operation' => 'settle', 'billable' => true]);
        $this->assertFalse($receipt->settled);
        $this->assertSame('unified-prepaid-v1', $receipt->billingRegime);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_usage_reservation')->fetchColumn());
        foreach ([(object) [...(array) $input, 'accountId' => 3], (object) [...(array) $input, 'workflowRunId' => 'other']] as $forged) {
            try { $this->budget->execute($forged); $this->fail('Conflicting route accepted.'); }
            catch (\Espo\Core\Exceptions\Conflict) { $this->addToAssertionCount(1); }
        }
    }

    public function testDeletedCutoverAndRouteFailClosedAndExecutionOwnershipIsChecked(): void
    {
        $input = $this->admit(1);
        $this->budget->execute($input);
        foreach (['other-regime', 'unified-prepaid-v1'] as $regime) {
            try { $this->budget->execute((object) [...(array) $input, 'billingRegime' => $regime]); $this->fail('Wrong regime accepted.'); }
            catch (\Espo\Core\Exceptions\Conflict) { $this->addToAssertionCount(1); }
        }
        try {
            $this->budget->execute((object) [...(array) $input, 'operation' => 'settle', 'billable' => false,
                'executionId' => '11111111-1111-4111-8111-111111111111']);
            $this->fail('Wrong owner settled.');
        } catch (\Espo\Core\Exceptions\Conflict) { $this->addToAssertionCount(1); }
        $this->pdo->exec('UPDATE credit_execution_route SET deleted = TRUE');
        try { $this->budget->execute($input); $this->fail('Deleted route restored legacy admission.'); }
        catch (\Espo\Core\Exceptions\Conflict) { $this->addToAssertionCount(1); }
        $this->pdo->exec("INSERT INTO tenant_credit_cutover (id, tenant_id, cutover_at, deleted) VALUES ('cutover', 'tenant', '2020-01-01 00:00:00', TRUE)");
        try { $this->budget->execute($this->admit(2)); $this->fail('Deleted cutover restored legacy admission.'); }
        catch (\Espo\Core\Exceptions\Conflict) { $this->addToAssertionCount(1); }
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_usage_reservation')->fetchColumn());
    }

    public function testRouteWriteFailureRollsBackLegacyAdmissionAndHistoricalAdoption(): void
    {
        // A trigger aborts the route write on SQLite; supported production
        // dialects use a real CHECK constraint.
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $install = function () use ($driver): void {
            if ($driver === 'sqlite') {
                $this->pdo->exec("CREATE TRIGGER route_fault BEFORE INSERT ON credit_execution_route BEGIN SELECT RAISE(ABORT, 'route fault'); END");
            } else {
                $this->pdo->exec('ALTER TABLE credit_execution_route ADD CONSTRAINT route_fault CHECK (1 = 0)');
            }
        };
        $remove = function () use ($driver): void {
            $this->pdo->exec($driver === 'sqlite' ? 'DROP TRIGGER route_fault' :
                'ALTER TABLE credit_execution_route DROP ' . ($driver === 'mysql' ? 'CHECK' : 'CONSTRAINT') . ' route_fault');
        };
        $input = $this->admit(1);
        $install();
        try { $this->budget->execute($input); $this->fail('Route write failure ignored.'); }
        catch (\PDOException) {
            $this->assertFalse($this->pdo->inTransaction());
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_usage_reservation')->fetchColumn());
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM credit_execution_route')->fetchColumn());
        }
        $remove();
        $this->budget->execute($input);
        $this->pdo->exec('DELETE FROM credit_execution_route');
        $install();
        try { $this->budget->execute((object) [...(array) $input, 'operation' => 'settle', 'billable' => true]); $this->fail('Adoption failure ignored.'); }
        catch (\PDOException) {
            $this->assertFalse($this->pdo->inTransaction());
            $this->assertSame('reserved', $this->pdo->query('SELECT state FROM ai_usage_reservation')->fetchColumn());
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM credit_execution_route')->fetchColumn());
        }
        $remove();
        $this->assertTrue($this->budget->execute((object) [...(array) $input, 'operation' => 'settle', 'billable' => true])->settled);
    }
}
