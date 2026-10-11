<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

require_once __DIR__ . '/ExecutionFixtures.php';

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\CutoverInput;
use Espo\Modules\FeatureCredits\Accounting\Cutovers;
use Espo\Modules\FeatureCredits\Accounting\ExecutionIdentity;
use Espo\Modules\FeatureCredits\Accounting\ExecutionRouting;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait ExecutionRoutingCases
{
    #[DataProvider('dialects')]
    public function testConcurrentCatalogAuthorizationRetainsSingleDispatchAndNoOverdraft(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '0.5'));
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $input = ExecutionFixtures::admission();
            $receipt = $this->terminalCommand($pdo, $input, [$input->modelRateId => ExecutionFixtures::policy()]);
            $usage = $receipt['receipt']['usageId'];
            $results = $this->race($pdo, $dialect, [
                ['request-execution', $usage, '1000', 'first'], ['request-execution', $usage, '1000', 'first'],
            ]);
            $this->assertCount(2, array_column($results, 'result'));
            $this->assertSame(1, count(array_filter(array_column($results, 'result'), static fn ($r) => $r['dispatchAllowed'])));
            $results = $this->race($pdo, $dialect, [
                ['request-execution', $usage, '1000', 'second'], ['request-execution', $usage, '1000', 'third'],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $this->assertHeld($pdo, '0.5000'); $this->assertConsistent($pdo, '0.5000');
        });
    }

    #[DataProvider('dialects')]
    public function testSignedAdmissionAndRequestAuthorizationUseOnlyApprovedCatalogBounds(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '0.5'));
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $input = ExecutionFixtures::admission();
            $catalog = [$input->modelRateId => ExecutionFixtures::policy()];
            $admitted = $this->terminalCommand($pdo, $input, $catalog);
            $this->assertSame($admitted, $this->terminalCommand($pdo, $input, $catalog));
            $this->assertSame('0.5000', $admitted['receipt']['reservedCredits']);
            $this->assertArrayNotHasKey('dispatchAllowed', $admitted['receipt']);
            $otherEntry = [...ExecutionFixtures::policy(), 'model' => 'other-fixture-model'];
            $otherPolicy = new \Espo\Modules\FeatureCredits\Pricing\AiModelPolicy($otherEntry);
            $changed = clone $input; $changed->modelRateId = $otherPolicy->rate->id;
            try { $this->terminalCommand($pdo, $changed, [...$catalog, $changed->modelRateId => $otherEntry]); $this->fail('Initial model policy changed on admission replay.'); }
            catch (Conflict) { $this->assertHeld($pdo, '0.5000'); }
            $authorize = clone $input; unset($authorize->billingRateId);
            $authorize->operation = 'authorize'; $authorize->usageId = $admitted['receipt']['usageId']; $authorize->requestKey = 'first';
            $authorized = $this->terminalCommand($pdo, $authorize, $catalog);
            $this->assertTrue($authorized['receipt']['dispatchAllowed']);
            $this->assertSame('0.2250', $authorized['receipt']['authorizedCredits']);
            $this->assertSame($admitted['policy'], $authorized['policy']);
            $authorized['receipt']['dispatchAllowed'] = false;
            $this->assertSame($authorized, $this->terminalCommand($pdo, $authorize, $catalog));
            $snapshot = json_decode($pdo->query('SELECT pricing_snapshot FROM credit_request')->fetchColumn(), true);
            $this->assertSame('fixture-text-v1', $snapshot['boundProfile']);
            $this->assertSame($input->modelRateId, $snapshot['id']);
            $this->assertSame(1000, $snapshot['inputTokenBound']);
            foreach (['outputTokenLimit' => 1, 'credits' => '0', 'multiplier' => '0'] as $key => $value) {
                $bad = clone $authorize; $bad->$key = $value;
                try { $this->terminalCommand($pdo, $bad, $catalog); $this->fail('Caller supplied a financial bound.'); }
                catch (\Espo\Core\Exceptions\BadRequest) { $this->assertHeld($pdo, '0.5000'); }
            }
            $authorize->requestKey = 'second'; $this->terminalCommand($pdo, $authorize, $catalog);
            $authorize->requestKey = 'third';
            try { $this->terminalCommand($pdo, $authorize, $catalog); $this->fail('Unfunded extension authorized.'); }
            catch (Conflict) { $this->assertHeld($pdo, '0.5000'); }
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
            $this->assertConsistent($pdo, '0.5000');
        });
    }

    #[DataProvider('dialects')]
    public function testSignedAuthorizationMissingConfigurationAndRouteFailClosed(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $input = ExecutionFixtures::admission(); $catalog = [$input->modelRateId => ExecutionFixtures::policy()];
            foreach ([null, [], [$input->modelRateId => [...ExecutionFixtures::policy(), 'multiplier' => '2']]] as $invalid) {
                try { $this->terminalCommand($pdo, $input, $invalid); $this->fail('Unapproved pricing admitted.'); }
                catch (\Espo\Core\Exceptions\ServiceUnavailable) { $this->assertHeld($pdo, '0.0000'); }
            }
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
            $admitted = $this->terminalCommand($pdo, $input, $catalog);
            $authorize = clone $input; unset($authorize->billingRateId);
            $authorize->operation = 'authorize'; $authorize->usageId = $admitted['receipt']['usageId']; $authorize->requestKey = 'first';
            foreach (['workflowRunId' => 'other', 'tenantId' => 'other', 'executionId' => '11111111-1111-4111-8111-111111111111'] as $key => $value) {
                $bad = clone $authorize; $bad->$key = $value;
                try { $this->terminalCommand($pdo, $bad, $catalog); $this->fail('Foreign execution authorized.'); }
                catch (Conflict | NotFound) { $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn()); }
            }
            $request = $this->terminalCommand($pdo, $authorize, $catalog);
            $pdo->exec('UPDATE credit_execution_route SET deleted = TRUE');
            foreach (['first', 'second'] as $key) {
                $authorize->requestKey = $key;
                try { $this->terminalCommand($pdo, $authorize, $catalog); $this->fail('Deleted route authorized/replayed.'); }
                catch (Conflict) { $this->assertHeld($pdo, '0.5000'); }
            }
            $pdo->exec('UPDATE credit_execution_route SET deleted = FALSE');
            // Recorded request outcomes and settlement survive removing the admission catalog.
            $outcome = ExecutionFixtures::outcome(); $outcome->usageId = $authorize->usageId;
            $outcome->requestId = $request['receipt']['requestId'];
            $this->terminalCommand($pdo, $outcome);
            $this->terminalCommand($pdo, $this->terminalOnly($outcome, 'settle'));
            $this->assertConsistent($pdo, '9.9998');
        });
    }

    private function terminalCommand(PDO $pdo, object $input, ?array $catalog = null): array
    {
        // Existing transport cases supply an explicit fixture catalog. Store it
        // in the real CRM entity table; production no longer reads Config maps.
        $pdo->exec('DELETE FROM ai_model_credit_rate');
        foreach ($catalog ?? [] as $id => $definition) {
            $pdo->prepare('INSERT INTO ai_model_credit_rate (id, name, policy_id, definition, created_at) VALUES (?,?,?,?,?)')
                ->execute([substr($id, 0, 17), 'fixture', $id, json_encode($definition), $this->now]);
        }
        return $this->entityCommand($pdo, $input);
    }

    private function entityCommand(PDO $pdo, object $input): array
    {
        $lock = $this->lock($pdo);
        $funding = new Funding($lock);
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $catalog = new \Espo\Modules\FeatureCredits\Pricing\AiCatalog($manager);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(fn () => $this->now);
        $controller = new \Espo\Modules\FeatureCredits\Controllers\CreditExecution(
            new \Espo\Modules\FeatureCredits\Accounting\Outcomes($lock),
            new \Espo\Modules\FeatureCredits\Accounting\Settlements($lock, $funding),
            new \Espo\Modules\FeatureCredits\Accounting\Reservations($lock, $funding),
            $catalog,
            new \Espo\Modules\FeatureCredits\Accounting\Requests($lock, $funding),
            new \Espo\Modules\FeatureCredits\Accounting\AdmissionConfiguration($manager, $clock, $catalog));
        $raw = json_encode($input, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $request = $this->createStub(\Espo\Core\Api\Request::class);
        $request->method('getBodyContents')->willReturn($raw);
        $request->method('getHeader')->willReturnCallback(static fn ($header) => match ($header) {
            'X-Credit-Execution-Timestamp' => $timestamp,
            'X-Credit-Execution-Signature' => 'sha256=' . hash_hmac('sha256', "credit-execution-v1.$timestamp.$raw", 'test-only-secret'),
            default => null,
        });
        $previous = getenv('AI_CREDIT_EXECUTION_SECRET');
        putenv('AI_CREDIT_EXECUTION_SECRET=test-only-secret');
        try { return json_decode(json_encode($controller->postActionExecute($request), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR); }
        finally { putenv($previous === false ? 'AI_CREDIT_EXECUTION_SECRET' : 'AI_CREDIT_EXECUTION_SECRET=' . $previous); }
    }

    private function terminalFixture(PDO $pdo): object
    {
        $reservations = $this->reservations($pdo);
        (new Funding($this->lock($pdo)))->grant($this->input());
        $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
        $input = ExecutionFixtures::outcome();
        $input->usageId = $reservations->reserve($this->executionAdmission())['usageId'];
        return $input;
    }

    private function terminalOnly(object $input, string $operation): object
    {
        $input = clone $input;
        foreach (['requestId', 'outcome', 'inputTokens', 'cachedInputTokens', 'outputTokens', 'evidence', 'providerRequestId'] as $field) unset($input->$field);
        $input->operation = $operation;
        return $input;
    }

    #[DataProvider('dialects')]
    public function testSignedTerminalSettlementPreservesRequestOutcomesAndReceipts(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            $input = $this->terminalFixture($pdo);
            $requests = new \Espo\Modules\FeatureCredits\Accounting\Requests($this->lock($pdo), new Funding($this->lock($pdo)));
            $authorize = fn (string $key) => $requests->authorize(new \Espo\Modules\FeatureCredits\Accounting\RequestInput(
                'tenant', $input->usageId, $input->executionId, $key,
                new \Espo\Modules\FeatureCredits\Pricing\AiRate('model-v1', 'provider', 'model', '1'), 0, 1000))['requestId'];
            $input->requestId = $authorize('successful');
            $success = $this->terminalCommand($pdo, $input);
            $this->assertSame($success, $this->terminalCommand($pdo, $input));
            $failed = clone $input; $failed->requestId = $authorize('failed');
            $failed->outcome = 'infrastructureFailure'; $failed->outputTokens = null;
            $this->terminalCommand($pdo, $failed);
            $cancelled = clone $input; $cancelled->requestId = $authorize('cancelled');
            $cancelled->outcome = 'cancelled'; $cancelled->outputTokens = null;
            $this->terminalCommand($pdo, $cancelled);
            $settle = $this->terminalOnly($input, 'settle');
            foreach ([$settle, $this->terminalOnly($input, 'release')] as $blocked) {
                try { $this->terminalCommand($pdo, $blocked); $this->fail('Unknown request was released.'); }
                catch (Conflict) { $this->assertHeld($pdo, '1.0000'); }
            }
            $cancelled->outputTokens = 0; $cancelled->evidence = (object) ['source' => 'authoritative-lookup'];
            $this->terminalCommand($pdo, $cancelled);
            $pdo->exec("ALTER TABLE credit_transaction ADD CONSTRAINT terminal_fault CHECK (type <> 'debit')");
            try { $this->terminalCommand($pdo, $settle); $this->fail('Posting fault ignored.'); }
            catch (PDOException) { $this->assertHeld($pdo, '1.0000'); $this->assertConsistent($pdo, '10.0000'); }
            $pdo->exec('ALTER TABLE credit_transaction DROP ' . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' terminal_fault');
            // Cutover/configuration changes must not rewrite an admitted route.
            $pdo->exec('UPDATE tenant_credit_cutover SET deleted = TRUE');
            $settled = $this->terminalCommand($pdo, $settle);
            $this->assertSame($settled, $this->terminalCommand($pdo, $settle));
            $this->assertSame($success, $this->terminalCommand($pdo, $input));
            $this->assertSame('unified-prepaid-v1', $settled['billingRegime']);
            $this->assertHeld($pdo, '0.0000');
            $this->assertConsistent($pdo, '9.9998');
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'debit'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testSignedTerminalRouteGuardsApplyBeforeWritesAndReplays(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $input = $this->terminalFixture($pdo);
            $request = new \Espo\Modules\FeatureCredits\Accounting\RequestInput('tenant', $input->usageId, $input->executionId,
                'request', new \Espo\Modules\FeatureCredits\Pricing\AiRate('model-v1', 'provider', 'model', '1'), 0, 1000);
            $input->requestId = (new \Espo\Modules\FeatureCredits\Accounting\Requests($this->lock($pdo), new Funding($this->lock($pdo))))->authorize($request)['requestId'];
            $commands = [$input, $this->terminalOnly($input, 'settle'), $this->terminalOnly($input, 'release')];
            $route = $pdo->query('SELECT * FROM credit_execution_route')->fetch(PDO::FETCH_ASSOC);
            foreach (['tenant_id' => 'other', 'workflow_run_id' => 'different', 'execution_id' => 'different',
                'usage_id' => 'different', 'billing_regime' => 'legacy-engagement-v1', 'deleted' => true] as $field => $value) {
                $pdo->prepare("UPDATE credit_execution_route SET $field = ?")->execute([$value]);
                foreach ($commands as $command) {
                    try { $this->terminalCommand($pdo, $command); $this->fail('Conflicting route accepted.'); }
                    catch (Conflict) { $this->assertFalse($pdo->inTransaction()); }
                }
                $pdo->prepare("UPDATE credit_execution_route SET $field = ?")->execute([$field === 'deleted' ? (int) $route[$field] : $route[$field]]);
            }
            foreach (['tenantId' => 'other', 'workflowRunId' => 'different', 'runId' => 'bbbbbbbbbbbbbbbbb',
                'executionId' => '11111111-1111-4111-8111-111111111111', 'usageId' => 'missing', 'requestId' => 'missing'] as $field => $value) {
                $bad = clone $input; $bad->$field = $value;
                try { $this->terminalCommand($pdo, $bad); $this->fail('Wrong command owner accepted.'); }
                catch (Conflict | NotFound) { $this->assertFalse($pdo->inTransaction()); }
            }
            $pdo->exec("INSERT INTO ai_usage_reservation (id, tenant_id) VALUES ('aaaaaaaaaaaaaaaaa', 'tenant')");
            try { $this->terminalCommand($pdo, $input); $this->fail('Dual billing accepted.'); }
            catch (Conflict) { $this->assertHeld($pdo, '1.0000'); }
            $pdo->exec('DELETE FROM ai_usage_reservation');
            $this->assertNull($pdo->query('SELECT outcome_record FROM credit_request')->fetchColumn());
            $this->terminalCommand($pdo, $input);
            $this->terminalCommand($pdo, $commands[1]);
            $pdo->exec('DELETE FROM credit_execution_route');
            foreach ([$input, $commands[1]] as $replay) {
                try { $this->terminalCommand($pdo, $replay); $this->fail('Missing route accepted on replay.'); }
                catch (Conflict) { $this->assertConsistent($pdo, '9.9998'); }
            }
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_execution_route')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testSignedPreRequestReleaseIsReplaySafe(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $input = $this->terminalFixture($pdo);
            $release = $this->terminalOnly($input, 'release');
            $result = $this->terminalCommand($pdo, $release);
            $this->assertSame($result, $this->terminalCommand($pdo, $release));
            $this->assertSame('released', $result['receipt']['state']);
            $this->assertHeld($pdo, '0.0000');
            $this->assertConsistent($pdo, '10.0000');
            $pdo->exec('UPDATE credit_execution_route SET deleted = TRUE');
            try { $this->terminalCommand($pdo, $release); $this->fail('Deleted route accepted on release replay.'); }
            catch (Conflict) { $this->assertConsistent($pdo, '10.0000'); }
        });
    }

    private function cutovers(PDO $pdo): Cutovers
    {
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(fn () => $this->now);
        return new Cutovers($manager, $clock);
    }

    private function executionDatabase(string $dialect, callable $test): void
    {
        $this->database($dialect, function (PDO $pdo) use ($test): void {
            $pdo->exec('CREATE TABLE ai_usage_reservation (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            try { $test($pdo); }
            finally {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $pdo->exec('DROP TABLE ai_usage_reservation');
            }
        });
    }

    private function executionAdmission(string $run = 'aaaaaaaaaaaaaaaaa', string $workflow = 'workflow'): ReservationInput
    {
        $identity = new ExecutionIdentity($run, $workflow, '00000000-0000-4000-8000-000000000000');
        return new ReservationInput('tenant', 'ai', $identity->operationKey(), $identity->executionId, 'rate', '1', execution: $identity);
    }

    #[DataProvider('dialects')]
    public function testCutoverSchedulingReplayOwnershipAndTransactionGuards(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $service = $this->cutovers($pdo);
            $input = new CutoverInput('tenant', '2026-10-11 00:00:00', (object) ['approved' => true]);
            $receipt = $service->schedule($input);
            $this->now = '2026-10-12 00:00:00';
            $this->assertSame($receipt, $service->schedule($input), 'Original receipt survives the cutover boundary.');
            foreach ([new CutoverInput('tenant', '2026-10-13 00:00:00', (object) ['approved' => true]),
                new CutoverInput('tenant', $input->cutoverAt, (object) ['approved' => false]),
                new CutoverInput('other', '2026-10-11 00:00:00', (object) ['approved' => true])] as $invalid) {
                try { $service->schedule($invalid); $this->fail('Conflicting or backdated cutover accepted.'); }
                catch (Conflict) { $this->assertFalse($pdo->inTransaction()); }
            }
            $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'other'");
            foreach (['missing', 'other'] as $tenant) {
                try { $service->schedule(new CutoverInput($tenant, $this->now, (object) ['approved' => true])); $this->fail('Unknown tenant accepted.'); }
                catch (NotFound) { $this->assertFalse($pdo->inTransaction()); }
            }
            $pdo->beginTransaction();
            try { $service->schedule($input); $this->fail('Nested transaction accepted.'); }
            catch (LogicException) { $this->assertTrue($pdo->inTransaction()); }
            $pdo->rollBack();
            $pdo->exec('UPDATE tenant_credit_cutover SET deleted = TRUE');
            try { $service->schedule($input); $this->fail('Deleted cutover restored.'); }
            catch (Conflict) { $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_cutover')->fetchColumn()); }
            foreach (['tenant_credit_balance', 'credit_grant', 'credit_transaction', 'credit_execution_route'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testUnifiedExecutionUsesCutoverBoundaryAndImmutableRoute(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $service = $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $input = $this->executionAdmission();
            $cutover = new CutoverInput('tenant', '2026-10-10 12:00:01', (object) ['approved' => true]);
            foreach ([false, true] as $configured) {
                if ($configured) $this->cutovers($pdo)->schedule($cutover);
                try { $service->reserve($input); $this->fail('Pre-cutover unified admission accepted.'); }
                catch (Conflict) { $this->assertHeld($pdo, '0.0000'); }
            }
            $this->now = $cutover->cutoverAt;
            $receipt = $service->reserve($input);
            $this->assertHeld($pdo, '1.0000');
            $route = $pdo->query('SELECT * FROM credit_execution_route')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(ExecutionRouting::UNIFIED, $route['billing_regime']);
            $this->assertSame($receipt['usageId'], $route['usage_id']);
            $this->assertSame($cutover->cutoverAt, $route['cutover_at']);
            $this->assertSame($receipt, $service->reserve($input));
            // Corrupted/deleted current configuration must not reroute committed work.
            $pdo->exec('UPDATE tenant_credit_cutover SET deleted = TRUE');
            $this->assertSame($receipt, $service->reserve($input));
            $released = $service->release('tenant', $receipt['usageId'], $input->executionId);
            $this->assertSame($released, $service->reserve($input));
            $this->assertHeld($pdo, '0.0000');
            $this->assertSame($route, $pdo->query('SELECT * FROM credit_execution_route')->fetch(PDO::FETCH_ASSOC));
            $this->assertConsistent($pdo, '10.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testLegacyAndConflictingRoutesCannotAdmitUnifiedWork(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $service = $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $input = $this->executionAdmission();
            $pdo->exec("INSERT INTO ai_usage_reservation (id, tenant_id) VALUES ('aaaaaaaaaaaaaaaaa', 'tenant')");
            foreach ([false, true] as $deleted) {
                if ($deleted) $pdo->exec('UPDATE ai_usage_reservation SET deleted = TRUE');
                try { $service->reserve($input); $this->fail('Legacy identity rebilled.'); }
                catch (Conflict) { $this->assertHeld($pdo, '0.0000'); }
            }
            $pdo->exec('DELETE FROM ai_usage_reservation');
            $receipt = $service->reserve($input);
            foreach ([['tenant_id', 'other'], ['workflow_run_id', 'different'], ['execution_id', '11111111-1111-4111-8111-111111111111'],
                ['billing_regime', 'legacy-engagement-v1'], ['usage_id', 'different']] as [$field, $value]) {
                $original = $pdo->query("SELECT $field FROM credit_execution_route")->fetchColumn();
                $pdo->prepare("UPDATE credit_execution_route SET $field = ?")->execute([$value]);
                try { $service->reserve($input); $this->fail('Conflicting route accepted.'); }
                catch (Conflict) { $this->assertHeld($pdo, '1.0000'); }
                $pdo->prepare("UPDATE credit_execution_route SET $field = ?")->execute([$original]);
            }
            $this->assertSame($receipt, $service->reserve($input));
            $pdo->exec('UPDATE credit_execution_route SET deleted = TRUE');
            try { $service->reserve($input); $this->fail('Deleted route recreated.'); }
            catch (Conflict) { $this->assertHeld($pdo, '1.0000'); }
            $pdo->exec('DELETE FROM credit_execution_route');
            try { $service->reserve($input); $this->fail('Missing route recreated.'); }
            catch (Conflict) { $this->assertHeld($pdo, '1.0000'); }
        });
    }

    #[DataProvider('dialects')]
    public function testRouteInsertFailureRollsBackEntireAdmissionAndCutoverFailureRollsBack(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            $cutover = new CutoverInput('tenant', $this->now, (object) ['approved' => true]);
            $pdo->exec('ALTER TABLE tenant_credit_cutover ADD CONSTRAINT cutover_fault CHECK (1 = 0)');
            try { $this->cutovers($pdo)->schedule($cutover); $this->fail('Cutover fault ignored.'); }
            catch (PDOException) { $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_cutover')->fetchColumn()); }
            $pdo->exec('ALTER TABLE tenant_credit_cutover DROP ' . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' cutover_fault');
            $this->cutovers($pdo)->schedule($cutover);
            $service = $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $pdo->exec('ALTER TABLE credit_execution_route ADD CONSTRAINT route_fault CHECK (1 = 0)');
            try { $service->reserve($this->executionAdmission()); $this->fail('Route fault ignored.'); }
            catch (PDOException) {
                $this->assertFalse($pdo->inTransaction());
                foreach (['credit_usage', 'credit_reservation', 'credit_allocation', 'credit_execution_route'] as $table) {
                    $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
                }
                $this->assertHeld($pdo, '0.0000');
                $this->assertConsistent($pdo, '10.0000');
            }
            $pdo->exec('ALTER TABLE credit_execution_route DROP ' . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' route_fault');
            $this->assertSame('held', $service->reserve($this->executionAdmission())['state']);
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentCutoverAndExecutionReplaysAndConflicts(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $results = $this->race($pdo, $dialect, [['cutover', '', 'approved', ''], ['cutover', '', 'approved', '']]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->assertSame($results[0], $results[1]);
            $results = $this->race($pdo, $dialect, [['cutover', '', 'approved', ''], ['cutover', '', 'different', '']]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->assertSame(Conflict::class, $results[1]['error']);
            $results = $this->race($pdo, $dialect, [
                ['reserve-execution', 'aaaaaaaaaaaaaaaaa', '1', 'workflow'], ['reserve-execution', 'aaaaaaaaaaaaaaaaa', '1', 'workflow'],
            ]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->assertSame($results[0], $results[1]);
            $results = $this->race($pdo, $dialect, [
                ['reserve-execution', 'bbbbbbbbbbbbbbbbb', '1', 'one'], ['reserve-execution', 'bbbbbbbbbbbbbbbbb', '1', 'two'],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_execution_route')->fetchColumn());
            $this->assertHeld($pdo, '2.0000');
            $this->assertConsistent($pdo, '10.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testCutoverRacingUnifiedAdmissionCannotAdmitBeforeSelection(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            $service = $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $results = $this->race($pdo, $dialect, [
                ['reserve-execution', 'aaaaaaaaaaaaaaaaa', '1', 'workflow'], ['cutover', '', 'approved', ''],
            ]);
            $this->assertArrayHasKey('result', $results[1]);
            if (isset($results[0]['error'])) {
                $this->assertSame(Conflict::class, $results[0]['error']);
                $this->assertHeld($pdo, '0.0000');
            } else {
                $this->assertHeld($pdo, '1.0000');
            }
            $receipt = $service->reserve($this->executionAdmission());
            $this->assertSame($receipt, $service->reserve($this->executionAdmission()));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_execution_route')->fetchColumn());
            $this->assertHeld($pdo, '1.0000');
        });
    }
}
