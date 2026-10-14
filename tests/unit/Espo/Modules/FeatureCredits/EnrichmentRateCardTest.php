<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Brick\Math\BigDecimal;
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
    public function testExplicitContactPricesAndAccountMarginFloor(): void
    {
        $resources = __DIR__ . '/../../../../../custom/Espo/Modules/FeatureCredits/Resources/';
        $card = new RateCard(json_decode(file_get_contents($resources . 'enrichment/integer-2026-10-14.json'), true, 32, JSON_THROW_ON_ERROR));
        foreach (['email', 'phone'] as $product) {
            $this->assertSame('3.0000', $card->ratesFor('Contact')['cpfcnpj'][$product]['credits']);
            $this->assertSame('8.0000', $card->ratesFor('Contact')['hub'][$product]['credits']);
        }
        $billing = json_decode(file_get_contents($resources . 'billing/launch-2026-10-13.json'), true, 32, JSON_THROW_ON_ERROR);
        $products = [];
        foreach ($billing['products'] as $product) $products[$product['productId'] . ':' . $product['productVersion']] = $product['credits'];
        $offers = array_values(array_filter($billing['offers'], static fn ($offer) => $offer['currency'] === 'BRL'));
        $this->assertNotEmpty($offers);
        // Account draft retains its margin floor; Contact uses explicit user-approved prices.
        $costs = ['cpfcnpj:6' => '0.51', 'hub:cnpj' => '0.03'];
        foreach ($card->definition['rates'] as $rate) {
            if ($rate['target'] !== 'Account' || !in_array($rate['provider'], ['cpfcnpj', 'hub'], true)) continue;
            $credits = BigDecimal::of($rate['credits']);
            $this->assertMatchesRegularExpression('/^[1-9][0-9]*\.0000$/D', $rate['credits']);
            $cost = BigDecimal::of($costs[$rate['provider'] . ':' . $rate['lookup']]);
            $lowerWouldMissTarget = false;
            foreach ($offers as $offer) {
                $quantity = $products[$offer['productId'] . ':' . $offer['productVersion']];
                // Revenue >= 5 * cost means margin >= 80%. Cross-multiply to avoid rounded unit prices.
                $required = $cost->multipliedBy('5')->multipliedBy($quantity);
                $this->assertTrue($credits->multipliedBy($offer['price'])->isGreaterThanOrEqualTo($required),
                    $rate['provider'] . ':' . $rate['lookup'] . ':' . $rate['product'] . ' misses margin on ' . $offer['offerId']);
                $lowerWouldMissTarget = $lowerWouldMissTarget ||
                    $credits->minus('1')->multipliedBy($offer['price'])->isLessThan($required);
            }
            $this->assertTrue($lowerWouldMissTarget, 'Use the smallest whole-credit price that meets the target.');
        }
        $this->assertSame('1.0000', $card->ratesFor('Contact')['apollo']['email']['credits']);
        $this->assertSame('8.0000', $card->ratesFor('Contact')['apollo']['phone']['credits']);
    }

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
