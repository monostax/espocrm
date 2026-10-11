<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

require_once __DIR__ . '/ExecutionFixtures.php';

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Accounting\AuthorizationInput;
use Espo\Modules\FeatureCredits\Pricing\AiCatalog;
use Espo\Modules\FeatureCredits\Pricing\AiModelPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiCatalogTest extends TestCase
{
    private function catalog(?array $entry): AiCatalog
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchColumn')->willReturn($entry === null ? false : json_encode($entry));
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $manager = $this->createStub(\Espo\ORM\EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        return new AiCatalog($manager);
    }
    public function testCatalogIsExcludedFromSettingsReadsAndWritesByFrameworkAccess(): void
    {
        $definition = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/metadata/app/config.json'), true, 512, JSON_THROW_ON_ERROR);
        $config = $this->createStub(Config::class);
        $metadata = $this->createStub(\Espo\Core\Utils\Metadata::class);
        $metadata->method('get')->willReturnCallback(static fn ($path) =>
            $path === ['app', 'config', 'params'] ? $definition['params'] : []);
        $access = new \Espo\Core\Utils\Config\Access($config, $metadata, $this->createStub(\Espo\Core\Utils\FieldUtil::class));
        $this->assertContains('featureCreditsAiCatalog', $access->getSystemParamList());
        $this->assertContains('featureCreditsAiCatalog', $access->getReadOnlyParamList());
    }

    public static function entry(): array
    {
        return ExecutionFixtures::policy();
    }

    public static function admission(): object
    {
        return ExecutionFixtures::admission();
    }

    public function testContentAddressedPoliciesAndExactBounds(): void
    {
        $entry = self::entry(); $policy = new AiModelPolicy($entry);
        $entry['multiplier'] = '1.0000';
        $this->assertSame($policy->rate->id, (new AiModelPolicy(array_reverse($entry, true)))->rate->id);
        foreach (['provider' => 'other-provider', 'model' => 'other-model', 'multiplier' => '2', 'inputTokenBound' => 1001,
            'outputTokenLimit' => 1001, 'boundProfile' => 'fixture-text-v2'] as $key => $value) {
            $this->assertNotSame($policy->rate->id, (new AiModelPolicy([...self::entry(), $key => $value]))->rate->id);
        }
        $request = $policy->request('tenant', 'usage', 'execution', 'key');
        $this->assertSame('0.2250', $request->credits);
        $this->assertSame('fixture-text-v1', json_decode($request->snapshotJson)->boundProfile);
        $this->assertSame($policy->receipt(), $this->catalog(self::entry())->resolve($policy->rate->id)->receipt());
    }

    public static function invalidEntries(): iterable
    {
        foreach (['provider' => '', 'model' => 'unsafe alias ', 'multiplier' => 1.0, 'inputTokenBound' => 0,
            'outputTokenLimit' => 1.5, 'boundProfile' => '', 'unexpected' => true] as $key => $value) {
            yield $key => [[...self::entry(), $key => $value]];
        }
        $entry = self::entry(); unset($entry['inputTokenBound']); yield 'missing ceiling' => [$entry];
        yield 'unsafe integer' => [[...self::entry(), 'inputTokenBound' => 9007199254740992]];
        yield 'amount overflow' => [[...self::entry(), 'multiplier' => '9999999999', 'inputTokenBound' => 9007199254740991]];
    }

    #[DataProvider('invalidEntries')]
    public function testInvalidConfigurationFailsClosed(array $entry): void
    {
        $id = (new AiModelPolicy(self::entry()))->rate->id;
        $this->expectException(ServiceUnavailable::class);
        $this->catalog($entry)->resolve($id);
    }

    public function testMissingAndEditedPolicyReferencesCannotReprice(): void
    {
        $id = (new AiModelPolicy(self::entry()))->rate->id;
        foreach ([null, [], [...self::entry(), 'multiplier' => '2']] as $entry) {
            try { $this->catalog($entry)->resolve($id); $this->fail('Missing or mutated reference accepted.'); }
            catch (ServiceUnavailable) { $this->addToAssertionCount(1); }
        }
    }

    public static function invalidCommands(): iterable
    {
        foreach (['billingRegime' => 'legacy-engagement-v1', 'modelRateId' => 'mutable-alias', 'tenantId' => '../other',
            'executionId' => 'other', 'billingRateId' => null, 'inputTokenBound' => 1, 'outputTokenLimit' => 1,
            'multiplier' => '0', 'credits' => '0', 'sourceId' => 'arbitrary'] as $key => $value) {
            $input = self::admission(); $input->$key = $value; yield $key => [$input];
        }
        $input = self::admission(); $input->operation = 'authorize'; yield 'missing request' => [$input];
    }

    #[DataProvider('invalidCommands')]
    public function testNoCallerAmountsBoundsOrUntrustedSourceFields(object $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuthorizationInput($input);
    }
}
