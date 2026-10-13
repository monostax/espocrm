<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Enrichment\DiscoveryPlanner;
use Espo\Modules\FeatureCredits\Enrichment\QuoteInput;
use Espo\Modules\FeatureCredits\Enrichment\RateCard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EnrichmentFixtures.php';

class EnrichmentDiscoveryPlanningTest extends TestCase
{
    public static function request(array $changes = []): array
    {
        return [...['tenantId' => 'tenant', 'targetType' => 'Account', 'targetId' => 'account',
            'products' => ['socios'], 'requestKey' => 'finder'], ...$changes];
    }

    public function testDefaultsAndCanonicalEmployeeFiltersDoNotAlterLegacyRequests(): void
    {
        $input = new QuoteInput((object) self::request());
        $this->assertSame(10, $input->data['count']);
        $this->assertFalse($input->data['waterfall']);
        $this->assertSame([], $input->data['filters']);
        $this->assertTrue($input->isDiscovery());
        $filters = new QuoteInput((object) self::request(['products' => ['employees'], 'filters' => (object) [
            'seniorities' => [], 'titles' => [' CTO ', 'CEO', 'CTO']]]));
        $this->assertSame(['titles' => ['CEO', 'CTO']], $filters->data['filters']);
        $this->assertSame($filters->data, (new QuoteInput((object) $filters->data))->data);
        $legacy = new QuoteInput((object) self::request(['products' => ['email']]));
        $this->assertArrayNotHasKey('count', $legacy->data);
        $this->assertArrayNotHasKey('filters', $legacy->data);
        $this->assertFalse($legacy->isDiscovery());
    }

    #[DataProvider('bounds')]
    public function testSharedCapCoversContributionsInsteadOfFirstSuccess(array $settings, string $budget, string $maximum, array $providers): void
    {
        $card = new RateCard(EnrichmentFixtures::discoveryDefinition());
        $input = new QuoteInput((object) self::request($settings));
        $available = array_fill_keys(['apollo', 'cpfcnpj', 'hub', 'registrobr'], [$input->data['products'][0] => str_repeat('a', 64)]);
        $plan = (new DiscoveryPlanner())->plan($input, $card, $available);
        $this->assertSame($budget, $plan['budget']);
        $this->assertSame($maximum, $plan['maximum']);
        $this->assertSame($providers, array_column($plan['routes'][$input->data['products'][0]], 'provider'));
        $this->assertTrue($plan['discovery']['admissionSupported']);
        $this->assertFalse($plan['discovery']['personalContactDataIncluded']);
        foreach ($plan['routes'][$input->data['products'][0]] as $step) $this->assertSame($card->id, $step['version']);
    }

    public static function bounds(): iterable
    {
        yield 'default socios' => [[], '60.0000', '60.0000', ['cpfcnpj']];
        yield 'supplement budget' => [['waterfall' => true, 'budget' => '120'], '120.0000', '120.0000', ['cpfcnpj', 'hub']];
        yield 'partial shared cap' => [['waterfall' => true, 'budget' => '15'], '15.0000', '15.0000', ['cpfcnpj', 'hub']];
        yield 'cap above work' => [['waterfall' => true, 'budget' => '200'], '200.0000', '120.0000', ['cpfcnpj', 'hub']];
        yield 'unaffordable' => [['waterfall' => true, 'budget' => '5.9999'], '5.9999', '0.0000', []];
        yield 'employees' => [['products' => ['employees']], '20.0000', '20.0000', ['apollo']];
        yield 'max employees' => [['products' => ['employees'], 'count' => 50], '100.0000', '100.0000', ['apollo']];
        yield 'rdap' => [['products' => ['cnpj']], '2.0000', '2.0000', ['registrobr']];
    }

    public function testUnavailableDefaultIsNeverSilentlyReplacedAndRoutesAreDisclosed(): void
    {
        $card = new RateCard(EnrichmentFixtures::discoveryDefinition());
        $available = ['hub' => ['socios' => str_repeat('a', 64)]];
        $off = (new DiscoveryPlanner())->plan(new QuoteInput((object) self::request()), $card, $available);
        $this->assertSame(['socios' => []], $off['routes']);
        $this->assertSame(['cpfcnpj' => 'capability_unavailable'], $off['discovery']['unavailableProviders']);
        $this->assertSame('60.0000', $off['budget']);
        $this->assertSame('0.0000', $off['maximum']);
        $on = (new DiscoveryPlanner())->plan(new QuoteInput((object) self::request(['waterfall' => true, 'budget' => '120'])), $card, $available);
        $this->assertSame(['hub'], array_column($on['routes']['socios'], 'provider'));
        $this->assertSame('60.0000', $on['maximum']);
        $this->assertSame($available, $on['availability']);
    }

    public function testCountMultiplicationSaturatesWithoutOverflow(): void
    {
        $definition = EnrichmentFixtures::discoveryDefinition();
        $definition['rates'][14]['credits'] = '9999999999.9999';
        $card = new RateCard($definition);
        $plan = (new DiscoveryPlanner())->plan(new QuoteInput((object) self::request(['count' => 50])), $card,
            ['cpfcnpj' => ['socios' => str_repeat('a', 64)]]);
        $this->assertSame('9999999999.9999', $plan['maximum']);
        $this->assertSame($plan['maximum'], $plan['budget']);
    }

    public function testLegacyCatalogCannotInventDiscoveryRates(): void
    {
        $this->expectException(Conflict::class);
        (new DiscoveryPlanner())->plan(new QuoteInput((object) self::request()), new RateCard(EnrichmentFixtures::definition()), []);
    }

    #[DataProvider('invalidSettings')]
    public function testRejectsCombinedProductsUnboundedWorkAndUnsupportedFilters(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QuoteInput((object) self::request($changes));
    }

    public static function invalidSettings(): iterable
    {
        foreach ([0, 51, -1, 10.0, '10', null, true] as $index => $count) yield "count $index" => [['count' => $count]];
        yield 'mixed finders' => [['products' => ['employees', 'socios']]];
        yield 'mixed discovery fields' => [['products' => ['socios', 'email']]];
        yield 'contact finder' => [['targetType' => 'Contact']];
        yield 'rdap count' => [['products' => ['cnpj'], 'count' => 1]];
        yield 'fields filters' => [['products' => ['email'], 'filters' => []]];
        yield 'socios filters' => [['filters' => ['titles' => ['CEO']]]];
        yield 'scalar filters' => [['filters' => 'CEO']];
        yield 'null filters' => [['filters' => null]];
        yield 'no waterfall cap' => [['waterfall' => true]];
        foreach ([['department' => ['Sales']], ['titles' => 'CEO'], ['titles' => [null]], ['titles' => ['']],
            ['titles' => ["CEO\nCFO"]], ['titles' => [str_repeat('a', 129)]], ['titles' => array_fill(0, 51, 'CEO')]] as $index => $filters) {
            yield "filter $index" => [['products' => ['employees'], 'filters' => $filters]];
        }
    }
}
