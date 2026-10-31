<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Billing\CatalogDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

require_once __DIR__ . '/BillingFixtures.php';

final class BillingCatalogDefinitionTest extends TestCase
{
    public function testSharedPlanEntitlementsAndCanonicalOrder(): void
    {
        $input = BillingFixtures::definition();
        $catalog = new CatalogDefinition($input);
        $this->assertSame('100.0000', $catalog->offers['monthly-br']->credits);
        $this->assertSame('100.0000', $catalog->offers['monthly-us']->credits);
        $this->assertSame('BRL', $catalog->offers['monthly-br']->currency);
        $this->assertSame('USD', $catalog->offers['monthly-us']->currency);
        $input->products[0]->credits = '100.0000';
        $input->offers[0]->price = '50.00';
        $input->offers[0]->countries = ['BR', 'BR'];
        $input->products = array_reverse($input->products);
        $input->offers = array_reverse($input->offers);
        $input->products = array_map(static fn ($p) => (object) array_reverse((array) $p, true), $input->products);
        $input->offers = array_map(static fn ($o) => (object) array_reverse((array) $o, true), $input->offers);
        $this->assertSame($catalog->id, (new CatalogDefinition($input))->id);
        $restored = json_decode(json_encode($catalog->definition, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        $this->assertSame($catalog->receipt(), (new CatalogDefinition($restored))->receipt());
        $input->products[0]->credits = '99';
        $this->assertSame('50.0000', $catalog->products['pack']['credits']);
    }

    public function testEmptyCatalogDisablesSalesWithoutInventingOffers(): void
    {
        $input = BillingFixtures::definition();
        $input->products = $input->offers = [];
        $this->assertSame([], (new CatalogDefinition($input))->receipt()['offers']);
    }

    public function testPrivateScopeRoundTripsWithoutChangingExistingPublicHashes(): void
    {
        $input = BillingFixtures::definition();
        $public = new CatalogDefinition($input);
        $this->assertSame('3fdbca489fffa70aab4d63b3970555361a7fb4aa6687a33bfcadcf39a5421e86', $public->id);
        $this->assertSame('1ea32f3d6a759c38f5ddc27be2080ac713b857e02e60f219b9e3fc2f8807e447', $public->offers['monthly-br']->termsId);
        $input->offers[0]->tenantId = 'tenant';
        $private = new CatalogDefinition($input);
        $this->assertNotSame($public->id, $private->id);
        $this->assertSame('tenant', $private->offers['monthly-br']->tenantId);
        $this->assertNotSame($public->offers['monthly-br']->termsId, $private->offers['monthly-br']->termsId);
        $this->assertSame($public->offers['monthly-us']->termsId, $private->offers['monthly-us']->termsId);
        $this->assertSame($public->offers['pack-br']->termsId, $private->offers['pack-br']->termsId);
        $restored = new CatalogDefinition(json_decode(json_encode($private->definition, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR));
        $this->assertSame($private->receipt(), $restored->receipt());
        $input->offers[0]->tenantId = 'other';
        $this->assertNotSame($private->id, (new CatalogDefinition($input))->id);
    }

    public static function invalidDefinitions(): iterable
    {
        $changes = [
            'actor spoof' => static fn ($d) => $d->actorId = 'other',
            'tenant scope spoof' => static fn ($d) => $d->tenantId = 'other',
            'version type' => static fn ($d) => $d->version = [],
            'bad timestamp' => static fn ($d) => $d->effectiveFrom = '2026-02-30 00:00:00',
            'list required' => static fn ($d) => $d->products = (object) [],
            'too many products' => static fn ($d) => $d->products = array_fill(0, 101, $d->products[0]),
            'too many offers' => static fn ($d) => $d->offers = array_fill(0, 101, $d->offers[0]),
            'duplicate product' => static fn ($d) => $d->products[] = $d->products[0],
            'duplicate offer' => static fn ($d) => $d->offers[] = $d->offers[0],
            'bad product' => static fn ($d) => $d->products[0] = [],
            'case identity' => static fn ($d) => $d->products[0]->productId = 'Monthly',
            'missing name' => static function ($d) { unset($d->products[0]->name); },
            'empty name' => static fn ($d) => $d->products[0]->name = '',
            'bad operation' => static fn ($d) => $d->products[0]->operation = 'postpaid',
            'negative entitlement' => static fn ($d) => $d->products[0]->credits = '-1',
            'empty purchase' => static fn ($d) => $d->products[1]->credits = '0',
            'floating entitlement' => static fn ($d) => $d->products[0]->credits = 100.0,
            'missing product' => static fn ($d) => $d->offers[0]->productId = 'absent',
            'wrong version' => static fn ($d) => $d->offers[0]->productVersion = 'v2',
            'offer entitlement override' => static fn ($d) => $d->offers[0]->credits = '999',
            'empty country' => static fn ($d) => $d->offers[0]->countries = [],
            'country type' => static fn ($d) => $d->offers[0]->countries = 'BR',
            'currency type' => static fn ($d) => $d->offers[0]->currency = [],
            'interval type' => static fn ($d) => $d->offers[0]->interval = [],
            'yearly' => static fn ($d) => $d->offers[0]->interval = 'year',
            'free offer' => static fn ($d) => $d->offers[0]->price = '0',
            'null private tenant' => static fn ($d) => $d->offers[0]->tenantId = null,
            'empty private tenant' => static fn ($d) => $d->offers[0]->tenantId = '',
            'private tenant list' => static fn ($d) => $d->offers[0]->tenantId = ['tenant'],
            'private tenant number' => static fn ($d) => $d->offers[0]->tenantId = 123,
            'private tenant flag' => static fn ($d) => $d->offers[0]->tenantId = false,
            'private tenant whitespace' => static fn ($d) => $d->offers[0]->tenantId = ' tenant',
            'private tenant length' => static fn ($d) => $d->offers[0]->tenantId = str_repeat('a', 25),
            'unrecognized tenant list' => static fn ($d) => $d->offers[0]->tenantIds = ['tenant'],
        ];
        foreach ($changes as $name => $change) {
            $input = BillingFixtures::definition(); $change($input);
            yield $name => [$input];
        }
    }

    #[DataProvider('invalidDefinitions')]
    public function testRejectsAmbiguousOrUnapprovedCatalogInputs(stdClass $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CatalogDefinition($input);
    }
}
