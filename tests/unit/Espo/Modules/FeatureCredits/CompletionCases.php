<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\CutoverInput;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait CompletionCases
{
    private function emptyCompletion(PDO $pdo, string $runId = 'bbbbbbbbbbbbbbbbb'): object
    {
        $command = ExecutionFixtures::admission(); $command->runId = $runId;
        $result = $this->terminalCommand($pdo, $command, [$command->modelRateId => ExecutionFixtures::policy()]);
        $scope = clone $command; unset($scope->operation, $scope->billingRateId, $scope->modelRateId);
        $scope->usageId = $result['receipt']['usageId'];
        return $scope;
    }

    private function pollCompletion(PDO $pdo): string
    {
        return $this->dispatch($pdo, 'recoverOne', ['tenantId' => 'tenant', 'leaseMs' => 1000, 'retryMs' => 2000]);
    }

    #[DataProvider('dialects')]
    public function testCompletionSealIsReplaySafeAndBlocksNewClaimsAndAuthorization(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
            $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            $sealed = $pdo->query('SELECT completion_requested_at FROM credit_execution_route')->fetchColumn();
            $this->now = '2026-10-10 12:00:01';
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            $this->assertSame($sealed, $pdo->query('SELECT completion_requested_at FROM credit_execution_route')->fetchColumn());
            $this->assertFalse($this->dispatch($pdo, 'claimIntent', ['intent' => $intent]));
            try { $this->dispatch($pdo, 'bind', ['binding' => $binding]); $this->fail('Sealed binding replay accepted.'); } catch (Conflict) {}
            $next = clone $intent; $next->requestKey = 'next';
            try { $this->dispatch($pdo, 'claimIntent', ['intent' => $next]); $this->fail('Sealed claim accepted.'); } catch (Conflict) {}
            $command = (object) [...get_object_vars($intent->scope), 'operation' => 'authorize', 'requestKey' => 'next',
                'modelRateId' => $intent->policy->modelRateId];
            $catalog = [$command->modelRateId => ExecutionFixtures::policy()];
            try { $this->terminalCommand($pdo, $command, $catalog); $this->fail('Sealed authorization accepted.'); } catch (Conflict) {}
            $command->requestKey = 'first';
            $this->assertFalse($this->terminalCommand($pdo, $command, $catalog)['receipt']['dispatchAllowed']);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
            $this->assertConsistent($pdo, '10.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionRecoversSavedFactsAcrossRestartAndDeliveryReplay(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]); $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            try { $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]); $this->fail('Unsealed recovery accepted.'); } catch (Conflict) {}
            $this->assertSame('empty', $this->pollCompletion($pdo));
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            // Every dispatch invocation constructs a new service; only CRM state survives.
            $result = $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]);
            $this->assertSame('settled', $result['state']); $this->assertSame('0.0002', $result['receipt']['settledCredits']);
            $this->assertSame($result, $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]));
            $this->assertSame('delivered', $this->delivery($pdo));
            $this->assertSame('empty', $this->pollCompletion($pdo));
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
            $this->assertSame('9.9998', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionReleasesOnlyAnEmptySealedOperation(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $this->reservations($pdo); (new Funding($this->lock($pdo)))->grant($this->input());
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $scope = $this->emptyCompletion($pdo);
            $this->dispatch($pdo, 'seal', ['scope' => $scope]);
            $this->assertSame('completed', $this->pollCompletion($pdo));
            $result = $this->dispatch($pdo, 'recover', ['scope' => $scope]);
            $this->assertSame('released', $result['state']);
            $this->assertSame('0.0000', $result['receipt']['reservedCredits']);
            $this->assertSame('empty', $this->pollCompletion($pdo));
            $this->assertConsistent($pdo, '10.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionAmbiguousAttemptsAndUntrackedRequestsNeverRelease(string $dialect): void
    {
        foreach (['untracked', 'unbound', 'missingFacts'] as $stage) {
            $this->executionDatabase($dialect, function (PDO $pdo) use ($stage): void {
                [$intent, $binding] = $this->dispatchFixture($pdo);
                if ($stage !== 'untracked') $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
                if ($stage === 'missingFacts') $this->dispatch($pdo, 'bind', ['binding' => $binding]);
                $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
                $this->assertSame(['state' => 'pending', 'reason' => 'request_recovery'], $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]));
                if ($stage === 'unbound') {
                    try { $this->dispatch($pdo, 'bind', ['binding' => $binding]); $this->fail('Sealed first binding accepted.'); } catch (Conflict) {}
                }
                $this->assertSame('held', $pdo->query('SELECT state FROM credit_reservation')->fetchColumn());
                $this->assertNull($pdo->query('SELECT completion_finished_at FROM credit_execution_route')->fetchColumn());
            });
        }
    }

    #[DataProvider('dialects')]
    public function testCompletionRecoversLostBindingButRequiresOriginalOutcomeFacts(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            for ($retry = 0; $retry < 2; $retry++) {
                $this->assertSame(['state' => 'pending', 'reason' => 'request_recovery'],
                    $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]));
                $loaded = $this->dispatch($pdo, 'load', ['scope' => $intent->scope, 'requestKey' => 'first']);
                $this->assertEquals($intent, $loaded->intent);
                $this->assertSame($binding->requestId, $loaded->requestId);
            }
            $this->assertSame('inFlight', $pdo->query('SELECT outcome FROM credit_request')->fetchColumn());
            $this->assertSame('held', $pdo->query('SELECT state FROM credit_reservation')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_outcome_delivery')->fetchColumn());
            $this->assertFalse($this->dispatch($pdo, 'claimIntent', ['intent' => $intent]));
            try { $this->dispatch($pdo, 'bind', ['binding' => $binding]); $this->fail('Recovered binding restored dispatch prerequisite.'); } catch (Conflict) {}
            $command = (object) [...get_object_vars($intent->scope), 'operation' => 'authorize', 'requestKey' => 'first',
                'modelRateId' => $intent->policy->modelRateId];
            $this->assertFalse($this->terminalCommand($pdo, $command, [$command->modelRateId => ExecutionFixtures::policy()])['receipt']['dispatchAllowed']);
            $this->assertConsistent($pdo, '10.0000');
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $result = $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]);
            $this->assertSame('settled', $result['state']);
            $this->assertSame($result, $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]));
            $this->assertSame('9.9998', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionBindingRecoveryRejectsCorruptOwnershipAndPolicy(string $dialect): void
    {
        foreach (["UPDATE credit_request SET deleted=TRUE", "UPDATE credit_request SET tenant_id='other'",
            "UPDATE credit_request SET pricing_snapshot='{}'", "UPDATE credit_reservation SET deleted=TRUE",
            "UPDATE credit_reservation SET execution_id='foreign'"] as $corruption) {
            $this->executionDatabase($dialect, function (PDO $pdo) use ($corruption): void {
                [$intent] = $this->dispatchFixture($pdo);
                $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
                $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
                $pdo->exec($corruption);
                try { $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]); $this->fail('Corrupt request recovered.'); } catch (Conflict) {}
                $this->assertFalse($pdo->inTransaction());
                $this->assertNull($pdo->query('SELECT request_id FROM credit_execution_attempt')->fetchColumn());
                $this->assertNull($pdo->query('SELECT completion_finished_at FROM credit_execution_route')->fetchColumn());
                $this->assertSame('10.0000', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            });
        }
    }

    #[DataProvider('dialects')]
    public function testCompletionBindingRecoveryRequiresSameUsageAndOriginalKey(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent] = $this->dispatchFixture($pdo);
            $other = clone $intent; $other->scope = $this->emptyCompletion($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $other]);
            $this->dispatch($pdo, 'seal', ['scope' => $other->scope]);
            $this->assertSame('released', $this->dispatch($pdo, 'recover', ['scope' => $other->scope])['state']);
            $next = clone $intent; $next->requestKey = 'not-authorized';
            $this->dispatch($pdo, 'claimIntent', ['intent' => $next]);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            $this->assertSame(['state' => 'pending', 'reason' => 'request_recovery'],
                $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]));
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_execution_attempt WHERE request_id IS NOT NULL')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
            $this->assertConsistent($pdo, '10.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionBindingRecoveryWriteFailureRollsBackAndRetries(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            $pdo->exec('ALTER TABLE credit_execution_attempt ADD CONSTRAINT binding_recovery_fault CHECK (request_id IS NULL)');
            try { $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]); $this->fail('Binding write fault ignored.'); } catch (PDOException) {}
            $this->assertFalse($pdo->inTransaction());
            $this->assertNull($pdo->query('SELECT request_id FROM credit_execution_attempt')->fetchColumn());
            $this->assertConsistent($pdo, '10.0000');
            $pdo->exec('ALTER TABLE credit_execution_attempt DROP CONSTRAINT binding_recovery_fault');
            $this->assertSame('pending', $this->pollCompletion($pdo));
            $this->assertSame($binding->requestId, $pdo->query('SELECT request_id FROM credit_execution_attempt')->fetchColumn());
            $this->assertSame('held', $pdo->query('SELECT state FROM credit_reservation')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionConcurrentBindingRecoveryAndBindReplayCannotReopenDispatch(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            [$intent, $binding] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            $recover = base64_encode(json_encode(['operation' => 'recover', 'scope' => $intent->scope]));
            $results = $this->race($pdo, $dialect, [['dispatch', $recover, '', ''], ['dispatch', $recover, '', '']]);
            $this->assertSame([['state' => 'pending', 'reason' => 'request_recovery'], ['state' => 'pending', 'reason' => 'request_recovery']], array_column($results, 'result'));
            $bind = base64_encode(json_encode(['operation' => 'bind', 'binding' => $binding]));
            $results = $this->race($pdo, $dialect, [['dispatch', $recover, '', ''], ['dispatch', $bind, '', '']]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame($binding->requestId, $pdo->query('SELECT request_id FROM credit_execution_attempt')->fetchColumn());
            $this->assertSame('inFlight', $pdo->query('SELECT outcome FROM credit_request')->fetchColumn());
            $this->assertConsistent($pdo, '10.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionUnknownUsageCanResolveAfterSeal(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]); $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $facts->outcome = 'cancelled'; $resolution = clone $facts;
            $facts->inputTokens = $facts->cachedInputTokens = $facts->outputTokens = null;
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            $this->assertSame(['state' => 'pending', 'reason' => 'metering'], $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]));
            $this->assertSame('delivered', $this->delivery($pdo));
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $resolution, 'phase' => 1]);
            $this->assertSame('settled', $this->dispatch($pdo, 'recover', ['scope' => $intent->scope])['state']);
            $this->assertSame('9.9998', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionPollingDefersAmbiguousWorkAndMakesProgressOnNextOperation(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            $this->assertSame('pending', $this->pollCompletion($pdo));
            $scope = $this->emptyCompletion($pdo);
            $this->dispatch($pdo, 'seal', ['scope' => $scope]);
            $this->assertSame('completed', $this->pollCompletion($pdo));
            $this->assertSame('empty', $this->pollCompletion($pdo));
            $this->now = '2026-10-10 12:00:02';
            $this->assertSame('pending', $this->pollCompletion($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionSealRollbackAndPostSettlementAcknowledgementCrash(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]); $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $pdo->exec('ALTER TABLE credit_execution_route ADD CONSTRAINT seal_fault CHECK (completion_requested_at IS NULL)');
            try { $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]); $this->fail('Seal fault ignored.'); } catch (PDOException) {}
            $this->assertFalse($pdo->inTransaction());
            $this->assertNull($pdo->query('SELECT completion_due_at FROM credit_execution_route')->fetchColumn());
            $pdo->exec('ALTER TABLE credit_execution_route DROP CONSTRAINT seal_fault');
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            $pdo->exec('ALTER TABLE credit_execution_route ADD CONSTRAINT completion_ack_fault CHECK (completion_finished_at IS NULL)');
            $this->assertSame('pending', $this->pollCompletion($pdo));
            $this->assertSame('settled', $pdo->query('SELECT state FROM credit_usage')->fetchColumn());
            $this->assertSame('9.9998', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $pdo->exec('ALTER TABLE credit_execution_route DROP CONSTRAINT completion_ack_fault');
            $this->now = '2026-10-10 12:00:02';
            $this->assertSame('completed', $this->pollCompletion($pdo));
            $this->assertSame('9.9998', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionIndependentProcessSealingAuthorizationAndRecovery(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]); $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $seal = base64_encode(json_encode(['operation' => 'seal', 'scope' => $intent->scope]));
            $results = $this->race($pdo, $dialect, [['dispatch', $seal, '', ''], ['dispatch', $seal, '', '']]);
            $this->assertCount(2, array_column($results, 'result'));
            $poll = base64_encode(json_encode(['operation' => 'recoverOne', 'tenantId' => 'tenant', 'leaseMs' => 1000, 'retryMs' => 1000]));
            $results = $this->race($pdo, $dialect, [['dispatch', $poll, '', ''], ['dispatch', $poll, '', '']]);
            $values = array_column($results, 'result'); sort($values);
            $this->assertSame(['completed', 'empty'], $values);
            $this->assertSame('9.9998', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
        });
        $this->executionDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            [$intent] = $this->dispatchFixture($pdo);
            $seal = base64_encode(json_encode(['operation' => 'seal', 'scope' => $intent->scope]));
            $results = $this->race($pdo, $dialect, [['dispatch', $seal, '', ''],
                ['request-execution', $intent->scope->usageId, '1000', 'next']]);
            $allowed = array_values(array_filter(array_column($results, 'result'), 'is_array'));
            $count = (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn();
            $this->assertSame($allowed ? 2 : 1, $count);
            $this->assertNotNull($pdo->query('SELECT completion_requested_at FROM credit_execution_route')->fetchColumn());
            $this->assertSame(['state' => 'pending', 'reason' => 'request_recovery'], $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]));
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionScopeAndStoredOwnershipAreRevalidated(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]); $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            foreach (['tenantId' => 'other', 'workflowRunId' => 'other', 'executionId' => '11111111-1111-4111-8111-111111111111'] as $field => $value) {
                $scope = clone $intent->scope; $scope->$field = $value;
                foreach (['seal', 'recover'] as $operation) {
                    try { $this->dispatch($pdo, $operation, ['scope' => $scope]); $this->fail('Foreign completion accepted.'); } catch (Conflict | NotFound) {}
                }
            }
            foreach (['credit_execution_route', 'credit_execution_attempt', 'credit_outcome_delivery', 'credit_request'] as $table) {
                $pdo->exec("UPDATE $table SET deleted=TRUE");
                try { $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]); $this->fail('Deleted completion parent accepted.'); } catch (Conflict) {}
                $pdo->exec("UPDATE $table SET deleted=FALSE");
            }
            $this->assertSame('inFlight', $pdo->query('SELECT outcome FROM credit_request')->fetchColumn());
            $this->assertNull($pdo->query('SELECT completion_finished_at FROM credit_execution_route')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionMissingOrFailedSiblingStillAppliesKnownDurableFacts(string $dialect): void
    {
        foreach (['missing', 'failed'] as $mode) {
            $this->executionDatabase($dialect, function (PDO $pdo) use ($mode): void {
                [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
                $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]); $this->dispatch($pdo, 'bind', ['binding' => $binding]);
                $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
                $next = clone $intent; $next->requestKey = 'next';
                $this->dispatch($pdo, 'claimIntent', ['intent' => $next]);
                $command = (object) [...get_object_vars($intent->scope), 'operation' => 'authorize', 'requestKey' => 'next',
                    'modelRateId' => $intent->policy->modelRateId];
                $result = $this->terminalCommand($pdo, $command, [$command->modelRateId => ExecutionFixtures::policy()]);
                if ($mode === 'failed') {
                    $bound = clone $next; $bound->requestId = $result['receipt']['requestId'];
                    $this->dispatch($pdo, 'bind', ['binding' => $bound]);
                    $bad = clone $facts; $bad->requestId = $bound->requestId; $bad->outputTokens = 1001;
                    $this->dispatch($pdo, 'enqueue', ['binding' => $bound, 'facts' => $bad, 'phase' => 0]);
                }
                $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
                $this->assertSame(['state' => 'pending', 'reason' => $mode === 'missing' ? 'request_recovery' : 'outcome_delivery'],
                    $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]));
                $this->assertSame('0.0001875', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
                $this->assertSame('held', $pdo->query('SELECT state FROM credit_reservation')->fetchColumn());
                $this->assertSame('10.0000', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            });
        }
    }

    #[DataProvider('dialects')]
    public function testCompletionReleasesClaimAfterExpiredFundsRejectAuthorization(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input(expires: '2026-10-10 12:00:01'));
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $command = ExecutionFixtures::admission();
            $catalog = [$command->modelRateId => ExecutionFixtures::policy()];
            $admitted = $this->terminalCommand($pdo, $command, $catalog);
            $scope = clone $command; unset($scope->operation, $scope->billingRateId, $scope->modelRateId);
            $scope->usageId = $admitted['receipt']['usageId'];
            $intent = (object) ['scope' => $scope, 'policy' => (object) $admitted['policy'], 'requestKey' => 'first',
                'requestHash' => str_repeat('a', 64), 'kind' => 'stream'];
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
            $this->now = '2026-10-10 12:00:01';
            $authorize = (object) [...get_object_vars($scope), 'operation' => 'authorize', 'requestKey' => 'first',
                'modelRateId' => $command->modelRateId];
            try { $this->terminalCommand($pdo, $authorize, $catalog); $this->fail('Expired funds authorized.'); }
            catch (Conflict) {}
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
            $this->dispatch($pdo, 'seal', ['scope' => $scope]);
            $result = $this->dispatch($pdo, 'recover', ['scope' => $scope]);
            $this->assertSame('released', $result['state']);
            $this->assertSame($result, $this->dispatch($pdo, 'recover', ['scope' => $scope]));
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
            $this->assertConsistent($pdo, '0.0000');
            $this->assertHeld($pdo, '0.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionUnstartedClaimsDoNotStrandFundsOrWaiveSuccessfulSiblings(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
            $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $unstarted = clone $intent; $unstarted->requestKey = 'denied';
            $this->dispatch($pdo, 'claimIntent', ['intent' => $unstarted]);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            $result = $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]);
            $this->assertSame('settled', $result['state']);
            $this->assertSame('0.0002', $result['receipt']['settledCredits']);
            $this->assertSame($result, $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
            $this->assertConsistent($pdo, '9.9998');
            $command = (object) [...get_object_vars($intent->scope), 'operation' => 'authorize', 'requestKey' => 'denied',
                'modelRateId' => $intent->policy->modelRateId];
            try { $this->terminalCommand($pdo, $command, [$command->modelRateId => ExecutionFixtures::policy()]); $this->fail('Late authorization after seal.'); }
            catch (Conflict) {}
            $this->assertHeld($pdo, '0.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionExpiredLeaseAndConcurrentDirectRecoveryCannotDoubleCharge(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo) use ($dialect): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]); $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            // Persisted state left by a worker killed immediately after lease commit.
            $pdo->prepare('UPDATE credit_execution_route SET completion_lease_token=?,completion_due_at=?')
                ->execute([str_repeat('a', 48), '2026-10-10 12:00:01']);
            $this->assertSame('empty', $this->pollCompletion($pdo));
            $this->now = '2026-10-10 12:00:01';
            $recover = base64_encode(json_encode(['operation' => 'recover', 'scope' => $intent->scope]));
            $poll = base64_encode(json_encode(['operation' => 'recoverOne', 'tenantId' => 'tenant', 'leaseMs' => 1000, 'retryMs' => 1000]));
            $results = $this->race($pdo, $dialect, [['dispatch', $recover, '', ''], ['dispatch', $poll, '', '']]);
            $this->assertCount(2, array_column($results, 'result'));
            $this->assertSame('settled', $results[0]['result']['state']);
            $this->assertContains($results[1]['result'], ['empty', 'completed']);
            $this->assertSame('9.9998', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
            $this->assertSame('empty', $this->pollCompletion($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testCompletionBoundsOversizedRecoveryWithoutPartialFinancialEffects(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent] = $this->dispatchFixture($pdo);
            for ($i = 0; $i < 101; $i++) {
                $next = clone $intent; $next->requestKey = 'step:' . $i;
                $this->dispatch($pdo, 'claimIntent', ['intent' => $next]);
            }
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            try { $this->dispatch($pdo, 'recover', ['scope' => $intent->scope]); $this->fail('Oversized recovery accepted.'); } catch (Conflict) {}
            $this->assertSame('pending', $this->pollCompletion($pdo));
            $this->assertSame('held', $pdo->query('SELECT state FROM credit_reservation')->fetchColumn());
            $this->assertSame('10.0000', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
        });
    }
}
