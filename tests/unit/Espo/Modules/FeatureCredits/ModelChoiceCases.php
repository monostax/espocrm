<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\AgreementModels;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\CutoverInput;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Pricing\AiCatalog;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/** Per-user model choice (2026-11-13): selectable agreement models, helper policy and the admission gap. */
trait ModelChoiceCases
{
    private function agreementModels(PDO $pdo): AgreementModels
    {
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now);
        return new AgreementModels($manager, $clock, new AiCatalog($manager));
    }

    /** main + stream + two OpenRouter models users may pick. */
    private function modelChoiceCatalog(PDO $pdo): array
    {
        $service = $this->configuration($pdo);
        return [
            'main' => $service->publishModel((object) ExecutionFixtures::policy()),
            'stream' => $service->publishModel((object) (['model' => 'fixture-stream-model', 'multiplier' => '2'] + ExecutionFixtures::policy())),
            'terra' => $service->publishModel((object) ['provider' => 'openrouter', 'model' => 'openai/gpt-6.1-terra', 'multiplier' => '1.21',
                'inputTokenBound' => 1000, 'outputTokenLimit' => 1000, 'boundProfile' => 'openrouter-incremental-bound-v1',
                'formula' => 'ai-per-10000-v2', 'displayName' => 'GPT-6.1 Terra', 'imageInput' => true]),
            'sonnet' => $service->publishModel((object) ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5.5', 'multiplier' => '2.42',
                'inputTokenBound' => 1000, 'outputTokenLimit' => 1000, 'boundProfile' => 'openrouter-incremental-bound-v1',
                'formula' => 'ai-per-10000-v2']),
            'native' => $service->publishModel((object) ['provider' => 'google', 'model' => 'gemini-3.8-flash',
                'multiplier' => '1', 'inputTokenBound' => 1000, 'outputTokenLimit' => 2048,
                'boundProfile' => \Espo\Modules\FeatureCredits\Accounting\NativeSearchDebt::PROFILE]),
        ];
    }

    #[DataProvider('dialects')]
    public function testModelDisplayMetadataNeverEntersThePolicyId(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $service = $this->configuration($pdo);
            $plain = ['provider' => 'openrouter', 'model' => 'openai/gpt-6-luna', 'multiplier' => '0.12',
                'inputTokenBound' => 1000, 'outputTokenLimit' => 1000, 'boundProfile' => 'openrouter-incremental-bound-v1'];
            $first = $service->publishModel((object) $plain);
            $this->assertSame([...$plain, 'formula' => 'ai-per-10000-v1'], $service->inspect('model', $first['id'])['data'],
                'Unlabelled models keep the exact inspect shape.');
            $labelled = $service->publishModel((object) ($plain + ['displayName' => 'GPT-6 Luna', 'imageInput' => true]));
            $this->assertSame($first, $labelled, 'Same priced policy, same record and policy id.');
            $data = $service->inspect('model', $first['id'])['data'];
            $this->assertSame('GPT-6 Luna', $data['displayName']);
            $this->assertTrue($data['imageInput']);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM ai_model_credit_rate')->fetchColumn());
            $definition = json_decode($pdo->query('SELECT definition FROM ai_model_credit_rate')->fetchColumn(), true);
            $this->assertArrayNotHasKey('displayName', $definition);
            $this->assertArrayNotHasKey('imageInput', $definition);
            foreach ([['displayName' => ''], ['displayName' => 5], ['imageInput' => 'yes'], ['displayName' => str_repeat('x', 201)]] as $bad) {
                try { $service->publishModel((object) ($plain + $bad)); $this->fail('Invalid display metadata accepted.'); }
                catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
            }
        });
    }

    #[DataProvider('dialects')]
    public function testSelectableModelsArePublishedImmutablyAndKeepExistingAgreementHashes(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $service = $this->configuration($pdo);
            $m = $this->modelChoiceCatalog($pdo);
            // Without the field (or with an empty list) an agreement hashes exactly as before.
            $plain = $this->agreement($m['main']['id']); $plain->aiStreamModelCreditRateId = $m['stream']['id'];
            $legacyHash = hash('sha256', json_encode(GrantInput::canonical((object) [...(array) $plain, 'creditUnitPrice' => '0.50000000',
                'monthlyCredits' => '0.0000', 'replacesId' => null]), JSON_THROW_ON_ERROR));
            $base = $service->publishAgreement($plain);
            $this->assertSame($legacyHash, $pdo->query("SELECT configuration_hash FROM tenant_credit_billing_rate WHERE id = '{$base['id']}'")->fetchColumn());
            $empty = clone $plain; $empty->aiSelectableModelCreditRateIds = [];
            $this->assertSame($base, $service->publishAgreement($empty));
            $this->assertSame([], $service->inspect('agreement', $base['id'], 'tenant')['data']['aiSelectableModelCreditRateIds']);
            $this->assertObjectNotHasProperty('aiSelectableModelCreditRateIds', $plain, 'The caller input is not mutated.');

            $invalid = [
                [[$m['main']['id']], Conflict::class], [[$m['stream']['id']], Conflict::class], [[$m['native']['id']], Conflict::class],
                [[$m['terra']['id'], $m['terra']['id']], InvalidArgumentException::class], [['unknownmodelrate1'], NotFound::class],
                [[5], InvalidArgumentException::class], [['bad id'], InvalidArgumentException::class],
            ];
            foreach ($invalid as $i => [$ids, $error]) {
                $agreement = clone $plain; $agreement->version = "invalid-$i"; $agreement->effectiveFrom = '2026-10-20 00:00:00';
                $agreement->aiSelectableModelCreditRateIds = $ids;
                try { $service->publishAgreement($agreement); $this->fail("Invalid selectable list $i accepted."); }
                catch (\Throwable $e) { $this->assertInstanceOf($error, $e); }
            }
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_billing_rate WHERE ai_selectable_model_credit_rate_ids IS NOT NULL')->fetchColumn());

            $next = clone $plain; $next->version = 'v2'; $next->effectiveFrom = '2026-10-11 00:00:00';
            $next->aiSelectableModelCreditRateIds = [$m['terra']['id'], $m['sonnet']['id']];
            $replaced = $service->publishAgreement($next, replacesId: $base['id']);
            $this->assertSame($replaced, $service->publishAgreement($next, replacesId: $base['id']));
            $reordered = clone $next; $reordered->aiSelectableModelCreditRateIds = [$m['sonnet']['id'], $m['terra']['id']];
            try { $service->publishAgreement($reordered, replacesId: $base['id']); $this->fail('A changed list replayed the same version.'); }
            catch (Conflict) { $this->addToAssertionCount(1); }
            $this->assertSame([$m['terra']['id'], $m['sonnet']['id']],
                $service->inspect('agreement', $replaced['id'], 'tenant')['data']['aiSelectableModelCreditRateIds']);
            $this->assertSame(json_encode([$m['terra']['id'], $m['sonnet']['id']]), $pdo->query(
                "SELECT ai_selectable_model_credit_rate_ids FROM tenant_credit_billing_rate WHERE id = '{$replaced['id']}'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testSelectableModelConfigurationAddsHelperPolicyOnlyAcrossProviders(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $service = $this->configuration($pdo);
            $m = $this->modelChoiceCatalog($pdo);
            $agreement = $this->agreement($m['main']['id']); $agreement->aiStreamModelCreditRateId = $m['stream']['id'];
            $agreement->aiNativeSearchModelCreditRateId = $m['native']['id'];
            $agreement->aiSelectableModelCreditRateIds = [$m['terra']['id'], $m['sonnet']['id']];
            $rate = $service->publishAgreement($agreement);
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['test' => true]));
            $command = ExecutionFixtures::admission();
            $command->operation = 'configuration'; unset($command->modelRateId, $command->billingRateId);
            // Same-provider selections: exactly the keys deployed callers parse today.
            foreach (['fixture-model' => 'main', 'fixture-stream-model' => 'stream'] as $model => $key) {
                $command->provider = 'fixture-provider'; $command->model = $model;
                $this->assertSame(['billingRateId' => $rate['id'], 'modelRateId' => $m[$key]['policyId'], 'maxRequests' => 4,
                    'nativeSearchPolicy' => $m['native']['policy']], $this->entityCommand($pdo, $command)['selection']);
            }
            $command->provider = 'openrouter'; $command->model = 'openai/gpt-6.1-terra';
            $selection = $this->entityCommand($pdo, $command)['selection'];
            $this->assertSame(['billingRateId' => $rate['id'], 'modelRateId' => $m['terra']['policyId'], 'maxRequests' => 4,
                'helperPolicy' => $m['main']['policy'], 'nativeSearchPolicy' => $m['native']['policy']], $selection);
            $command->model = 'openai/gpt-5.6-terra';
            try { $this->entityCommand($pdo, $command); $this->fail('Unlisted model configured.'); } catch (Conflict) {}

            // The picked model, its helper (main) and native search are all authorized; an unlinked policy is not.
            (new Funding($this->lock($pdo)))->grant($this->input('payment-1', '100'));
            $admit = ExecutionFixtures::admission(); $admit->billingRateId = $rate['id']; $admit->modelRateId = $m['terra']['policyId'];
            $usage = $this->entityCommand($pdo, $admit)['receipt']['usageId'];
            $authorize = (object) ['operation' => 'authorize', 'billingRegime' => $admit->billingRegime, 'tenantId' => 'tenant',
                'usageId' => $usage, 'runId' => $admit->runId, 'workflowRunId' => $admit->workflowRunId,
                'executionId' => $admit->executionId, 'requestKey' => 'main-0', 'modelRateId' => $m['terra']['policyId']];
            $this->assertTrue($this->entityCommand($pdo, $authorize)['receipt']['dispatchAllowed']);
            $authorize->requestKey = 'extraction-0'; $authorize->modelRateId = $m['main']['policyId'];
            $this->assertTrue($this->entityCommand($pdo, $authorize)['receipt']['dispatchAllowed']);
            $unlinked = $service->publishModel((object) (['model' => 'fixture-unlinked-model'] + ExecutionFixtures::policy()));
            $authorize->requestKey = 'unlinked-0'; $authorize->modelRateId = $unlinked['policyId'];
            try { $this->entityCommand($pdo, $authorize); $this->fail('An unlinked policy was authorized.'); } catch (Conflict) {}
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM credit_request WHERE request_key = 'unlinked-0'")->fetchColumn());
            $other = ExecutionFixtures::admission(); $other->billingRateId = $rate['id']; $other->modelRateId = $unlinked['policyId'];
            $other->runId = 'bbbbbbbbbbbbbbbbb';
            try { $this->entityCommand($pdo, $other); $this->fail('An unlinked policy was admitted.'); } catch (Conflict) {}
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testTenantModelListFollowsTheActiveUnifiedAgreement(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $service = $this->configuration($pdo);
            $m = $this->modelChoiceCatalog($pdo);
            $models = $this->agreementModels($pdo);
            $agreement = $this->agreement($m['main']['id']); $agreement->aiStreamModelCreditRateId = $m['stream']['id'];
            $agreement->aiSelectableModelCreditRateIds = [$m['terra']['id'], $m['sonnet']['id']];
            $service->publishAgreement($agreement);
            $this->assertNull($models->forTenant('tenant'), 'Legacy-billed before cutover.');
            $this->assertTrue($models->allows('tenant', null));
            $this->assertTrue($models->allows('tenant', ''));
            $this->assertFalse($models->allows('tenant', 'openrouter/openai/gpt-6.1-terra'));
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['test' => true]));
            $list = $models->forTenant('tenant');
            $this->assertSame(['fixture-provider/fixture-stream-model', 'fixture-provider/fixture-model',
                'openrouter/openai/gpt-6.1-terra', 'openrouter/anthropic/claude-sonnet-5.5'], array_column($list['models'], 'id'));
            $this->assertSame($list['models'][0], $list['default']);
            $this->assertSame([true, false, false, false], array_column($list['models'], 'isDefault'));
            $this->assertSame(['id' => 'openrouter/openai/gpt-6.1-terra', 'provider' => 'openrouter', 'model' => 'openai/gpt-6.1-terra',
                'displayName' => 'GPT-6.1 Terra', 'multiplier' => '1.21', 'holdCredits' => '0.2836', 'imageInput' => true, 'isDefault' => false],
                $list['models'][2]);
            $this->assertSame('fixture-model', $list['models'][1]['displayName'], 'Unlabelled models show their id.');
            $this->assertSame('0.4500', $list['models'][0]['holdCredits']);
            $this->assertTrue($models->allows('tenant', 'openrouter/anthropic/claude-sonnet-5.5'));
            $this->assertTrue($models->allows('tenant', 'fixture-provider/fixture-model'));
            foreach (['openrouter/openai/gpt-5.6-terra', 'openai/gpt-6.1-terra', 'garbage', 'openrouter/', '/x'] as $bad) {
                $this->assertFalse($models->allows('tenant', $bad), $bad);
            }
            $this->assertNull($models->forTenant('other'));
            $this->assertSame(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5.5'],
                AgreementModels::split('openrouter/anthropic/claude-sonnet-5.5'));
        });
    }
}
