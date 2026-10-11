<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\CutoverInput;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Pricing\AiCatalog;
use Espo\Modules\FeatureCredits\Services\Configuration;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

trait ConfigurationCases
{
    #[DataProvider('dialects')]
    public function testCrmNativeSearchPolicyRequiresExplicitAgreementSelection(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $service = $this->configuration($pdo);
            $main = $service->publishModel((object) ExecutionFixtures::policy());
            $native = $service->publishModel((object) ['provider' => 'google', 'model' => 'gemini-3.8-flash',
                'multiplier' => '1', 'inputTokenBound' => 1000, 'outputTokenLimit' => 2048,
                'boundProfile' => \Espo\Modules\FeatureCredits\Accounting\NativeSearchDebt::PROFILE]);
            $agreement = $this->agreement($main['id']);
            $agreement->aiNativeSearchModelCreditRateId = $native['id'];
            $rate = $service->publishAgreement($agreement);
            $this->assertSame($rate, $service->publishAgreement($agreement));
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['test' => true]));
            $command = ExecutionFixtures::admission();
            $command->operation = 'configuration'; unset($command->modelRateId, $command->billingRateId);
            $command->provider = 'fixture-provider'; $command->model = 'fixture-model';
            $selection = $this->entityCommand($pdo, $command)['selection'];
            $this->assertSame($native['policy'], $selection['nativeSearchPolicy']);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $admit = ExecutionFixtures::admission(); $admit->billingRateId = $rate['id'];
            $usage = $this->entityCommand($pdo, $admit)['receipt']['usageId'];
            $authorize = (object) ['operation' => 'authorize', 'billingRegime' => $admit->billingRegime,
                'tenantId' => 'tenant', 'usageId' => $usage, 'runId' => $admit->runId,
                'workflowRunId' => $admit->workflowRunId, 'executionId' => $admit->executionId,
                'requestKey' => 'knowledge-0', 'modelRateId' => $native['policyId']];
            $this->assertTrue($this->entityCommand($pdo, $authorize)['receipt']['dispatchAllowed']);
            $this->assertFalse($this->entityCommand($pdo, $authorize)['receipt']['dispatchAllowed']);
            // Simulate corrupt/revoked selection; a globally published policy by
            // itself must not grant this tenant an overdraft privilege.
            $pdo->exec('UPDATE tenant_credit_billing_rate SET ai_native_search_model_credit_rate_id = NULL');
            $authorize->requestKey = 'knowledge-1';
            try { $this->entityCommand($pdo, $authorize); $this->fail('Unselected native debt policy authorized.'); } catch (Conflict) {}
        });
    }

    private function configuration(PDO $pdo): Configuration
    {
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now);
        return new Configuration($manager, $clock, new AiCatalog($manager));
    }

    private function agreement(string $modelId, string $version = 'v1', string $from = '2026-10-10 12:00:00', ?string $until = null): object
    {
        return (object) ['tenantId' => 'tenant', 'version' => $version, 'effectiveFrom' => $from, 'effectiveUntil' => $until,
            'currency' => 'BRL', 'creditUnitPrice' => '0.50', 'monthlyCredits' => '0',
            'aiModelCreditRateId' => $modelId, 'aiMaxRequests' => 4];
    }

    #[DataProvider('dialects')]
    public function testCrmConfigurationPublishesImmutableReplaySafeEntitiesWithoutFunding(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $service = $this->configuration($pdo);
            $definition = (object) ExecutionFixtures::policy();
            $model = $service->publishModel($definition);
            $this->assertSame($model, $service->publishModel($definition));
            $this->assertSame(17, strlen($model['id']));
            $agreement = $this->agreement($model['id']);
            $rate = $service->publishAgreement($agreement);
            $this->assertSame($rate, $service->publishAgreement($agreement));
            $changed = clone $agreement; $changed->creditUnitPrice = '0.08';
            try { $service->publishAgreement($changed); $this->fail('Repriced an existing agreement.'); } catch (Conflict) {}
            $changed->version = 'overlapping';
            try { $service->publishAgreement($changed); $this->fail('Overlapping agreement accepted.'); } catch (Conflict) {}
            $this->assertSame('0.50000000', $pdo->query('SELECT credit_unit_price FROM tenant_credit_billing_rate')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM ai_model_credit_rate')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_billing_rate')->fetchColumn());
            foreach (['tenant_credit_balance', 'credit_grant', 'credit_transaction', 'tenant_credit_cutover'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testCrmConfigurationUsesOriginalAdmittedVersionAcrossAgreementChanges(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $service = $this->configuration($pdo);
            $first = $service->publishModel((object) ExecutionFixtures::policy());
            $second = $service->publishModel((object) [...ExecutionFixtures::policy(), 'multiplier' => '2']);
            $a = $service->publishAgreement($this->agreement($first['id'], until: '2026-10-10 12:00:01'));
            $b = $service->publishAgreement($this->agreement($second['id'], 'v2', '2026-10-10 12:00:01'));
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['test' => true]));
            $command = ExecutionFixtures::admission();
            $command->operation = 'configuration'; unset($command->modelRateId, $command->billingRateId);
            $command->provider = 'fixture-provider'; $command->model = 'fixture-model';
            $selection = $this->entityCommand($pdo, $command)['selection'];
            $this->assertSame(['billingRateId' => $a['id'], 'modelRateId' => $first['policyId'], 'maxRequests' => 4], $selection);
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_balance')->fetchColumn());
            (new Funding($this->lock($pdo)))->grant($this->input());
            $admit = ExecutionFixtures::admission(); $admit->billingRateId = $a['id'];
            $this->entityCommand($pdo, $admit);
            $this->now = '2026-10-10 12:00:02';
            $this->assertSame($selection, $this->entityCommand($pdo, $command)['selection']);
            $new = clone $command; $new->runId = 'bbbbbbbbbbbbbbbbb';
            $this->assertSame($b['id'], $this->entityCommand($pdo, $new)['selection']['billingRateId']);
            $new->model = 'different-model';
            try { $this->entityCommand($pdo, $new); $this->fail('Wrong model selected.'); } catch (Conflict) {}
            foreach (['tenantId' => 'other', 'workflowRunId' => 'other', 'executionId' => '11111111-1111-4111-8111-111111111111'] as $field => $value) {
                $bad = clone $command; $bad->$field = $value;
                try { $this->entityCommand($pdo, $bad); $this->fail('Foreign configuration scope accepted.'); } catch (Conflict) {}
            }
        });
    }

    #[DataProvider('dialects')]
    public function testCrmAgreementReplacementPreservesPricesAndRollsBackConflictingWindows(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $service = $this->configuration($pdo);
            $model = $service->publishModel((object) ExecutionFixtures::policy());
            $first = $service->publishAgreement($this->agreement($model['id']));
            $next = $this->agreement($model['id'], 'v2', '2026-10-11 00:00:00');
            $next->creditUnitPrice = '0.60';
            $second = $service->publishAgreement($next, replacesId: $first['id']);
            $this->assertSame($second, $service->publishAgreement($next, replacesId: $first['id']));
            $rows = $pdo->query('SELECT version, effective_until, credit_unit_price FROM tenant_credit_billing_rate ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame('2026-10-11 00:00:00', $rows[0]['effective_until']);
            $this->assertSame('0.50000000', $rows[0]['credit_unit_price']);
            $this->assertSame('0.60000000', $rows[1]['credit_unit_price']);
            $conflict = $this->agreement($model['id'], 'v3', '2026-10-10 18:00:00');
            try { $service->publishAgreement($conflict, replacesId: $first['id']); $this->fail('Overlapping replacement accepted.'); } catch (Conflict) {}
            $this->assertSame($rows, $pdo->query('SELECT version, effective_until, credit_unit_price FROM tenant_credit_billing_rate ORDER BY version')->fetchAll(PDO::FETCH_ASSOC));
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentCrmAgreementPublicationSerializesReplayAndOverlappingVersions(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $service = $this->configuration($pdo);
            $model = $service->publishModel((object) ExecutionFixtures::policy());
            $payload = base64_encode(json_encode($this->agreement($model['id'])));
            $results = $this->race($pdo, $dialect, [['agreement', $payload, '', ''], ['agreement', $payload, '', '']]);
            $this->assertCount(2, array_column($results, 'result'));
            $this->assertSame($results[0], $results[1]);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_billing_rate')->fetchColumn());
            $other = base64_encode(json_encode($this->agreement($model['id'], 'v2')));
            $results = $this->race($pdo, $dialect, [['agreement', $payload, '', ''], ['agreement', $other, '', '']]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
        });
    }
}
