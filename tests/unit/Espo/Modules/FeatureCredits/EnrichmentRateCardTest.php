<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Enrichment\RateCard;
use Espo\Modules\FeatureCredits\Enrichment\RoutePlanner;
use Espo\ORM\Entity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EnrichmentFixtures.php';

class EnrichmentRateCardTest extends TestCase
{
    public function testCanonicalSnapshotsAreImmutableAndKeepEntitySpecificRates(): void
    {
        $input = EnrichmentFixtures::definition();
        $card = new RateCard($input);
        $input['rates'] = array_reverse($input['rates']);
        $input['rates'][0]['credits'] = '1';
        $reordered = new RateCard($input);
        $this->assertSame($card->receipt(), $reordered->receipt());
        $this->assertSame('2.7000', $card->ratesFor('Contact')['cpfcnpj']['phone']['credits']);
        $this->assertSame('5.1000', $card->ratesFor('Account')['cpfcnpj']['phone']['credits']);
        $this->assertArrayNotHasKey('company', $card->ratesFor('Contact')['apollo']);
        $input['rates'][0]['credits'] = '2';
        $this->assertNotSame($card->id, (new RateCard($input))->id);
        $this->assertSame('1.0000', $card->ratesFor('Account')['apollo']['company']['credits']);
        $this->assertFalse($card->receipt()['routing']['apolloNativeWaterfall']);
    }

    public function testCatalogRoutesFeedTheExistingBudgetPlanner(): void
    {
        $card = new RateCard(EnrichmentFixtures::definition());
        $target = $this->createStub(Entity::class);
        $target->method('getEntityType')->willReturn('Account');
        $target->method('get')->with('cnpj')->willReturn('04252011000110');
        $plan = (new RoutePlanner())->plan($target, ['email', 'phone'], true, Amount::fromString('6'),
            $card->ratesFor('Account'), $card->definition['routingPolicy']);
        $this->assertSame('6.0000', $plan['maximum']);
        $this->assertSame(['hub', 'cpfcnpj', 'apollo'], array_column($plan['routes']['email'], 'provider'));
        $this->assertSame(['hub', 'cpfcnpj'], array_column($plan['routes']['phone'], 'provider'));
        foreach ($plan['routes'] as $route) {
            foreach ($route as $step) $this->assertSame($card->id, $step['version']);
        }
    }

    #[DataProvider('invalidDefinitions')]
    public function testRejectsIncompleteOrUnsafeDefinition(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RateCard($input);
    }

    public static function invalidDefinitions(): iterable
    {
        $valid = EnrichmentFixtures::definition();
        foreach (['tenantId' => 'tenant', 'active' => true, 'apolloNativeWaterfall' => true, 'effectiveUntil' => null] as $key => $value) {
            yield "extra $key" => [[...$valid, $key => $value]];
        }
        foreach (['version' => '', 'effectiveFrom' => '2026-02-30 00:00:00', 'routingPolicy' => 'arbitrary'] as $key => $value) {
            yield "invalid $key" => [[...$valid, $key => $value]];
        }
        yield 'case-ambiguous version' => [[...$valid, 'version' => 'V1']];
        yield 'missing provider' => [[...$valid, 'rates' => array_slice($valid['rates'], 1)]];
        yield 'non-list' => [[...$valid, 'rates' => ['foo' => $valid['rates'][0]]]];
        foreach (['credits' => 2.7, 'lookup' => '6', 'target' => 'Lead', 'provider' => 'unknown',
            'product' => 'company', 'extra' => true] as $key => $value) {
            $input = $valid; $input['rates'][0][$key] = $value;
            yield "bad rate $key" => [$input];
        }
        foreach (['0', '-1', '2.70001'] as $price) {
            $input = $valid; $input['rates'][0]['credits'] = $price;
            yield "bad price $price" => [$input];
        }
        $input = $valid; $input['rates'][1] = $input['rates'][0];
        yield 'duplicate replacing required rate' => [$input];
    }
}
