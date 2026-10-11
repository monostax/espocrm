<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\CutoverInput;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

trait WaivedTranscriptionCases
{
    private function transcriptionCommand(): array
    {
        $scope = ExecutionFixtures::admission();
        unset($scope->operation, $scope->billingRateId, $scope->modelRateId);
        return ['scope' => $scope, 'requestKey' => 'chatwoot-audio:1:3:4', 'requestHash' => str_repeat('a', 64)];
    }

    #[DataProvider('dialects')]
    public function testWaivedTranscriptionConcurrentClaimsAuthorizeOnlyOneProviderAttempt(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $command = base64_encode(json_encode(['operation' => 'claimTranscription', ...$this->transcriptionCommand()]));
            $results = $this->race($pdo, $dialect, [['dispatch', $command, '', ''], ['dispatch', $command, '', '']]);
            $allowed = array_column(array_column($results, 'result'), 'dispatchAllowed');
            sort($allowed);
            $this->assertSame([false, true], $allowed);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_waived_request')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_transaction')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testWaivedTranscriptionFundedAndUnfundedReplayNeverCreatesFinancialRows(string $dialect): void
    {
        foreach ([false, true] as $funded) $this->database($dialect, function (PDO $pdo) use ($funded): void {
            if ($funded) (new Funding($this->lock($pdo)))->grant($this->input());
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $command = $this->transcriptionCommand();
            $this->assertSame(['dispatchAllowed' => true, 'state' => 'claimed', 'billingState' => 'waived'], $this->dispatch($pdo, 'claimTranscription', $command));
            $this->assertFalse($this->dispatch($pdo, 'claimTranscription', $command)['dispatchAllowed']);
            $receipt = $this->dispatch($pdo, 'finishTranscription', [...$command, 'facts' => (object) ['outcome' => 'success', 'provider' => 'openai', 'model' => 'whisper-1']]);
            $this->assertSame('waived', $receipt['billingState']);
            $this->assertSame($receipt, $this->dispatch($pdo, 'finishTranscription', [...$command, 'facts' => (object) ['outcome' => 'success', 'provider' => 'openai', 'model' => 'whisper-1']]));
            $this->assertFalse($this->dispatch($pdo, 'claimTranscription', $command)['dispatchAllowed']);
            foreach (['credit_usage', 'credit_request', 'credit_reservation', 'credit_allocation', 'credit_execution_route'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
            if ($funded) $this->assertConsistent($pdo, '10.0000');
            else $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_balance')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testWaivedTranscriptionDeniesWrongRegimeOwnershipAndChangedContent(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $command = $this->transcriptionCommand();
            try { $this->dispatch($pdo, 'claimTranscription', $command); $this->fail('Legacy claim accepted.'); } catch (Conflict) {}
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $this->dispatch($pdo, 'claimTranscription', $command);
            try { $this->dispatch($pdo, 'claimTranscription', [...$command, 'requestHash' => str_repeat('b', 64)]); $this->fail('Changed bytes accepted.'); } catch (Conflict) {}
            $foreign = clone $command['scope']; $foreign->executionId = '22222222-2222-4222-8222-222222222222';
            $facts = (object) ['outcome' => 'failed', 'provider' => 'openai', 'model' => 'whisper-1'];
            try { $this->dispatch($pdo, 'finishTranscription', [...$command, 'scope' => $foreign, 'facts' => $facts]); $this->fail('Foreign completion accepted.'); } catch (Conflict) {}
            $this->assertSame('claimed', $pdo->query('SELECT state FROM credit_waived_request')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testWaivedTranscriptionCrashUnknownFailureAndCancellationStayFreeWithoutRedispatch(string $dialect): void
    {
        foreach ([null, 'unknown', 'failed', 'cancelled'] as $outcome) $this->database($dialect, function (PDO $pdo) use ($outcome): void {
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $command = $this->transcriptionCommand();
            $this->dispatch($pdo, 'claimTranscription', $command);
            if ($outcome !== null) $this->dispatch($pdo, 'finishTranscription', [...$command, 'facts' => (object) ['outcome' => $outcome, 'provider' => 'openai', 'model' => 'whisper-1']]);
            $this->now = '2027-10-10 12:00:00';
            $receipt = $this->dispatch($pdo, 'claimTranscription', $command);
            $this->assertFalse($receipt['dispatchAllowed']);
            $this->assertSame($outcome ?? 'claimed', $receipt['state']);
            $this->assertSame('waived', $receipt['billingState']);
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_transaction')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testWaivedTranscriptionCannotStartAfterParentSealAndDoesNotWaiveSuccessfulSibling(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->dispatchFixture($pdo);
            $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
            $this->dispatch($pdo, 'bind', ['binding' => $binding]);
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
            $scope = clone $intent->scope; unset($scope->usageId);
            $command = [...$this->transcriptionCommand(), 'scope' => $scope];
            $this->dispatch($pdo, 'claimTranscription', $command);
            $this->dispatch($pdo, 'finishTranscription', [...$command, 'facts' => (object) ['outcome' => 'failed', 'provider' => 'openai', 'model' => 'whisper-1']]);
            $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
            try { $this->dispatch($pdo, 'claimTranscription', [...$command, 'requestKey' => 'next']); $this->fail('Sealed dispatch accepted.'); } catch (Conflict) {}
            $this->assertSame('settled', $this->dispatch($pdo, 'recover', ['scope' => $intent->scope])['state']);
            $this->assertSame('9.9998', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
        });
    }
}
