<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\CutoverInput;
use Espo\Modules\FeatureCredits\Accounting\DispatchInput;
use Espo\Modules\FeatureCredits\Accounting\DispatchStore;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\Outcomes;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait DispatchStoreCases
{
    private function dispatchStore(PDO $pdo): DispatchStore
    {
        $manager = $this->createMock(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturnCallback(fn () => $this->now);
        $lock = $this->lock($pdo); $funding = new Funding($lock);
        return new DispatchStore($manager, $clock, new Outcomes($lock),
            new \Espo\Modules\FeatureCredits\Accounting\Reservations($lock, $funding),
            new \Espo\Modules\FeatureCredits\Accounting\Settlements($lock, $funding));
    }

    private function dispatchFixture(PDO $pdo): array
    {
        $this->reservations($pdo);
        (new Funding($this->lock($pdo)))->grant($this->input());
        $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
        $command = ExecutionFixtures::admission(); $catalog = [$command->modelRateId => ExecutionFixtures::policy()];
        $admitted = $this->terminalCommand($pdo, $command, $catalog);
        unset($command->billingRateId); $command->operation = 'authorize';
        $command->usageId = $admitted['receipt']['usageId']; $command->requestKey = 'first';
        $authorized = $this->terminalCommand($pdo, $command, $catalog);
        $scope = clone $command; unset($scope->operation, $scope->modelRateId, $scope->requestKey);
        $intent = (object) ['scope' => $scope, 'policy' => (object) $authorized['policy'], 'requestKey' => 'first',
            'requestHash' => str_repeat('a', 64), 'kind' => 'generate'];
        $binding = clone $intent; $binding->requestId = $authorized['receipt']['requestId'];
        $facts = (object) ['requestId' => $binding->requestId, 'outcome' => 'success', 'inputTokens' => 0,
            'cachedInputTokens' => 0, 'outputTokens' => 1, 'evidence' => (object) ['source' => 'sdk', 'requestHash' => $intent->requestHash]];
        return [$intent, $binding, $facts];
    }

    private function dispatch(PDO $pdo, string $operation, array $fields): mixed
    {
        $raw = json_encode((object) ['operation' => $operation, ...$fields], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $request = $this->createStub(\Espo\Core\Api\Request::class);
        $request->method('getBodyContents')->willReturn($raw);
        $request->method('getHeader')->willReturnCallback(static fn ($header) => match ($header) {
            'X-Credit-Execution-Timestamp' => $timestamp,
            'X-Credit-Execution-Signature' => 'sha256=' . hash_hmac('sha256', "credit-dispatch-v1.$timestamp.$raw", 'test-only-secret'),
            default => null,
        });
        $previous = getenv('AI_CREDIT_EXECUTION_SECRET'); putenv('AI_CREDIT_EXECUTION_SECRET=test-only-secret');
        try {
            $response = (new \Espo\Modules\FeatureCredits\Controllers\CreditDispatch($this->dispatchStore($pdo)))->postActionExecute($request);
            $this->assertSame($operation, $response->operation);
            return $response->result;
        } finally { putenv($previous === false ? 'AI_CREDIT_EXECUTION_SECRET' : 'AI_CREDIT_EXECUTION_SECRET=' . $previous); }
    }

    private function delivery(PDO $pdo): string
    {
        return $this->dispatch($pdo, 'deliver', ['tenantId' => 'tenant', 'leaseMs' => 1000, 'retryMs' => 1000]);
    }

    #[DataProvider('dialects')]
    public function testCrmDispatchLifecycleAndMeasuredDeliveryReplay(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->assertTrue($this->dispatch($pdo, 'claimIntent', ['intent' => $intent]));
            $this->assertFalse($this->dispatch($pdo, 'claimIntent', ['intent' => $intent]));
            $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $loaded = $this->dispatch($pdo, 'load', ['scope' => $intent->scope, 'requestKey' => 'first']);
            $this->assertEquals($intent, $loaded->intent); $this->assertSame($binding->requestId, $loaded->requestId);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $this->assertSame('delivered', $this->delivery($pdo));
            $this->assertSame('empty', $this->delivery($pdo));
            $this->assertSame('0.0001875', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT attempts FROM credit_outcome_delivery')->fetchColumn());
            $this->assertSame('delivered', $pdo->query('SELECT state FROM credit_outcome_delivery')->fetchColumn());
            $this->assertConsistent($pdo, '10.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testCrmDispatchRejectsChangedIdentityScopePolicyAndFacts(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
            foreach (['requestHash' => str_repeat('b', 64), 'kind' => 'stream'] as $field => $value) {
                $bad = clone $intent; $bad->$field = $value;
                try { $this->dispatch($pdo, 'claimIntent', ['intent' => $bad]); $this->fail('Changed intent accepted.'); }
                catch (Conflict) {}
            }
            foreach (['tenantId' => 'other', 'workflowRunId' => 'foreign', 'executionId' => '11111111-1111-4111-8111-111111111111'] as $field => $value) {
                $bad = clone $intent->scope; $bad->$field = $value;
                try { $this->dispatch($pdo, 'load', ['scope' => $bad, 'requestKey' => 'first']); $this->fail('Foreign load accepted.'); }
                catch (Conflict | NotFound) {}
            }
            $bad = clone $binding; $bad->requestId = 'foreign';
            try { $this->dispatch($pdo, 'bind', ['binding' => $bad]); $this->fail('Foreign request bound.'); } catch (Conflict) {}
            $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $bad = clone $facts; $bad->outputTokens = 2;
            try { $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $bad, 'phase' => 0]); $this->fail('Changed outcome accepted.'); } catch (Conflict) {}
            $pdo->exec('UPDATE credit_execution_route SET deleted=TRUE');
            try { $this->delivery($pdo); $this->fail('Deleted route delivered.'); } catch (Conflict) {}
            $this->assertSame(0, (int) $pdo->query('SELECT attempts FROM credit_outcome_delivery')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testCrmDispatchPendingCancellationAndOrderedAuthoritativeResolution(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]); $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $facts->outcome = 'cancelled'; $measured = clone $facts;
            $facts->inputTokens = $facts->cachedInputTokens = $facts->outputTokens = null;
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            try { $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $measured, 'phase' => 1]); $this->fail('Resolution preceded original.'); } catch (Conflict) {}
            $this->assertSame('delivered', $this->delivery($pdo));
            $this->assertSame('unknown', $pdo->query('SELECT metering_state FROM credit_request')->fetchColumn());
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $measured, 'phase' => 1]);
            $this->assertSame('delivered', $this->delivery($pdo));
            $this->assertSame('measured', $pdo->query('SELECT metering_state FROM credit_request')->fetchColumn());
            $original = json_decode($pdo->query('SELECT facts FROM credit_outcome_delivery WHERE phase=0')->fetchColumn());
            $this->assertNull($original->outputTokens);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $measured, 'phase' => 1]);
            $this->assertSame('empty', $this->delivery($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testCrmDispatchAcknowledgementFailureRecoversWithoutDoubleAccrual(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]); $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $pdo->exec("ALTER TABLE credit_outcome_delivery ADD CONSTRAINT dispatch_ack_fault CHECK (state <> 'delivered')");
            try { $this->delivery($pdo); $this->fail('Injected acknowledgement fault ignored.'); } catch (PDOException) {}
            $pdo->exec('ALTER TABLE credit_outcome_delivery DROP CONSTRAINT dispatch_ack_fault');
            $this->assertSame('0.0001875', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
            $this->assertSame('empty', $this->delivery($pdo));
            $this->now = '2026-10-10 12:00:02';
            $this->assertSame('delivered', $this->delivery($pdo));
            $this->assertSame('0.0001875', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
            $this->assertSame(2, (int) $pdo->query('SELECT attempts FROM credit_outcome_delivery')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testCrmDispatchOutcomeFailureRetriesAndRequestPolicyBindingFailsClosed(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]); $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $facts->outputTokens = 1001; // Authorization's output bound is 1000.
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $this->assertSame('retry', $this->delivery($pdo));
            $this->assertSame('inFlight', $pdo->query('SELECT outcome FROM credit_request')->fetchColumn());
            $this->assertSame('empty', $this->delivery($pdo));
            $this->now = '2026-10-10 12:00:02';
            $this->assertSame('retry', $this->delivery($pdo));
            $pdo->exec("UPDATE credit_request SET pricing_snapshot='{}'");
            try { $this->dispatch($pdo, 'bind', ['binding' => $binding]); $this->fail('Invalid stored policy accepted.'); } catch (Conflict) {}
        });
    }

    #[DataProvider('dialects')]
    public function testCrmDispatchIndependentProcessClaimsAndDelivery(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $command = base64_encode(json_encode(['operation' => 'claimIntent', 'intent' => $intent]));
            $results = $this->race($pdo, $dialect, [['dispatch', $command, '', ''], ['dispatch', $command, '', '']]);
            $this->assertCount(2, array_column($results, 'result'));
            $this->assertSame(1, count(array_filter(array_column($results, 'result'))));
            $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $command = base64_encode(json_encode(['operation' => 'deliver', 'tenantId' => 'tenant', 'leaseMs' => 1000, 'retryMs' => 1000]));
            $results = $this->race($pdo, $dialect, [['dispatch', $command, '', ''], ['dispatch', $command, '', '']]);
            $values = array_column($results, 'result'); sort($values);
            $this->assertSame(['delivered', 'empty'], $values);
            $this->assertSame('0.0001875', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
        });
    }
}
