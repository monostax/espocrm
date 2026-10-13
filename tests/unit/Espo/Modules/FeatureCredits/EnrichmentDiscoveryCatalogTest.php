<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Enrichment\RateCard;
use Espo\Modules\FeatureCredits\Enrichment\RoutePlanner;
use Espo\Modules\FeatureCredits\Enrichment\RoutingPolicy;
use Espo\ORM\Entity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EnrichmentFixtures.php';

class EnrichmentDiscoveryCatalogTest extends TestCase
{
    public function testLegacySnapshotAndPricesSurviveTheNewPolicy(): void
    {
        $legacy = new RateCard(EnrichmentFixtures::definition());
        $this->assertSame('1f7298a707f5f2c238c21dbc328c4282d897cf93000c29350c5c037d4c01460f', $legacy->id);
        $input = EnrichmentFixtures::discoveryDefinition();
        $card = new RateCard($input);
        $this->assertCount(17, $card->definition['rates']);
        foreach ($legacy->definition['rates'] as $rate) $this->assertContains($rate, $card->definition['rates']);
        $routing = $card->receipt()['routing'];
        foreach ($legacy->receipt()['routing'] as $key => $value) {
            if ($key !== 'id') $this->assertSame($value, $routing[$key]);
        }
        $this->assertArrayNotHasKey('recordDiscovery', $legacy->receipt()['routing']);
        $this->assertSame('2.0000', $card->ratesFor('Account')['apollo']['employees']['credits']);
        $this->assertSame('6.0000', $card->ratesFor('Account')['cpfcnpj']['socios']['credits']);
        $this->assertSame('6.0000', $card->ratesFor('Account')['hub']['socios']['credits']);
        $this->assertSame('2.0000', $card->ratesFor('Account')['registrobr']['cnpj']['credits']);
        $this->assertArrayNotHasKey('registrobr', $card->ratesFor('Contact'));
        $this->assertArrayNotHasKey('employees', $card->ratesFor('Contact')['apollo']);
        $input['rates'] = array_reverse($input['rates']);
        $input['rates'][0]['credits'] = '2';
        $this->assertSame($card->receipt(), (new RateCard($input))->receipt());
        $input['rates'][0]['credits'] = '3';
        $this->assertNotSame($card->id, (new RateCard($input))->id);
        $this->assertSame($card->id, $card->ratesFor('Account')['registrobr']['cnpj']['version']);
    }

    public function testNewPolicyPreservesFieldPlanningWithoutMixingFinderRoutes(): void
    {
        $target = $this->createStub(Entity::class);
        $target->method('getEntityType')->willReturn('Account');
        $target->method('get')->with('cnpj')->willReturn('04252011000110');
        foreach ([false, true] as $waterfall) {
            $plans = [];
            foreach ([EnrichmentFixtures::definition(), EnrichmentFixtures::discoveryDefinition()] as $definition) {
                $card = new RateCard($definition);
                $plan = (new RoutePlanner())->plan($target, ['email', 'phone'], $waterfall, Amount::fromString('20'),
                    $card->ratesFor('Account'), $card->definition['routingPolicy']);
                foreach ($plan['routes'] as &$route) {
                    foreach ($route as &$step) {
                        $this->assertSame($card->id, $step['version']);
                        unset($step['version']);
                    }
                    unset($step);
                }
                unset($route);
                $plans[] = $plan;
            }
            $this->assertSame($plans[0], $plans[1]);
        }
    }

    public function testDiscoveryPolicyBindsDistinctProductsUnitsRoutesAndLimits(): void
    {
        $routing = RoutingPolicy::definition(RoutingPolicy::DISCOVERY_ID);
        $products = $routing['recordDiscovery'];
        $this->assertSame(['employees', 'socios', 'cnpj'], array_keys($products));
        $this->assertSame(['apollo'], $products['employees']['waterfallOff']);
        $this->assertSame(['apollo'], $products['employees']['waterfallOn']);
        $this->assertSame('candidate', $products['employees']['unit']);
        $this->assertSame(['cpfcnpj'], $products['socios']['waterfallOff']);
        $this->assertSame(['cpfcnpj', 'hub'], $products['socios']['waterfallOn']);
        $this->assertSame('useful-provider-contribution', $products['socios']['unit']);
        $this->assertSame('distinct-person-first-then-supplement', $products['socios']['strategy']);
        $this->assertSame(['registrobr'], $products['cnpj']['waterfallOff']);
        $this->assertSame('registrant-cnpj-reveal', $products['cnpj']['unit']);
        foreach ($products as $product) {
            $this->assertSame('Account', $product['target']);
            $this->assertFalse($product['waterfallDefault']);
        }
        $this->assertSame(['defaultCount' => 10, 'maximumCount' => 50, 'pagesPerProvider' => 5,
            'completionAttempts' => 'requested-count', 'operationDeadlineSeconds' => 300], $routing['finderLimits']);
        $this->assertSame(90, $routing['hiddenRetentionMaximumDays']);
        $this->assertFalse($routing['apolloNativeWaterfall']);
    }

    #[DataProvider('invalidDefinitions')]
    public function testRejectsCrossProductLookupAndIncompleteCatalog(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RateCard($input);
    }

    public static function invalidDefinitions(): iterable
    {
        $input = EnrichmentFixtures::discoveryDefinition();
        yield 'legacy policy with new products' => [[...$input, 'routingPolicy' => RoutingPolicy::ID]];
        yield 'new policy without all new products' => [[...$input, 'rates' => EnrichmentFixtures::definition()['rates']]];
        foreach ([13 => ['lookup' => 'organization', 'target' => 'Contact', 'provider' => 'hub', 'credits' => 2.0],
            14 => ['lookup' => '19', 'product' => 'employees'],
            15 => ['lookup' => 'cadastropf', 'target' => 'Contact'],
            16 => ['lookup' => 'cnpj', 'product' => 'email', 'credits' => '0']] as $index => $changes) {
            foreach ($changes as $field => $value) {
                $bad = $input; $bad['rates'][$index][$field] = $value;
                yield "$index $field" => [$bad];
            }
        }
        $bad = $input; $bad['rates'][14]['lookup'] = '25';
        yield 'unapproved ownership package' => [$bad];
        $bad = $input; $bad['rates'][16] = $bad['rates'][14];
        yield 'duplicate replacing RDAP' => [$bad];
        yield 'injected routes' => [[...$input, 'recordDiscovery' => ['socios' => ['hub']]]];
    }

    #[DataProvider('discoveryProducts')]
    public function testNewPricesCannotEnterTheFirstSuccessFieldExecutor(string $product): void
    {
        $target = $this->createStub(Entity::class);
        $target->method('getEntityType')->willReturn('Account');
        $card = new RateCard(EnrichmentFixtures::discoveryDefinition());
        $this->expectException(InvalidArgumentException::class);
        (new RoutePlanner())->plan($target, [$product], false, Amount::fromString('120'),
            $card->ratesFor('Account'), $card->definition['routingPolicy']);
    }

    public static function discoveryProducts(): iterable
    {
        foreach (['employees', 'socios', 'cnpj'] as $product) yield $product => [$product];
    }
}
