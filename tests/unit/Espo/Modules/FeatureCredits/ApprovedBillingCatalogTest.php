<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Billing\{CatalogDefinition, Providers};
use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Billing\Asaas\Connections;
use PHPUnit\Framework\TestCase;

final class ApprovedBillingCatalogTest extends TestCase
{
    public function testApprovedPricesAndAllowancesAreFixedByMarket(): void
    {
        $input = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/billing/launch-2026-10-12.json'), false, 32, JSON_THROW_ON_ERROR);
        $input->effectiveFrom = '2099-01-01 00:00:00';
        $catalog = new CatalogDefinition($input);
        $this->assertCount(3, $catalog->products);
        $this->assertCount(6, $catalog->offers);
        foreach ([['2000', '997.00', '199.00'], ['7000', '2997.00', '500.00'], ['15000', '5897.00', '999.00']] as [$credits, $brl, $usd]) {
            foreach ([['br', 'BR', 'BRL', $brl], ['us', 'US', 'USD', $usd]] as [$suffix, $country, $currency, $price]) {
                $terms = $catalog->offers["monthly-$credits-$suffix"];
                $this->assertSame($price, $terms->price);
                $this->assertSame($credits . '.0000', $terms->credits);
                $this->assertSame([$country], $terms->countries);
                $this->assertSame($currency, $terms->currency);
                $this->assertSame('month', $terms->interval);
                $this->assertSame('subscription', $terms->operation);
            }
        }
        $config = $this->createStub(Config::class);
        $providers = new Providers($config, new Connections($config));
        $this->assertNull($providers->capability('asaas'));
        $this->assertNull($providers->capability('stripe'));
    }

    public function testPreparationRequiresExplicitFutureStartAndProducesReplayablePayload(): void
    {
        $run = function (array $args): array {
            $process = proc_open([PHP_BINARY, 'scripts/prepare-credit-billing-catalog.php', ...$args],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            return [proc_close($process), $out, $err];
        };
        foreach ([[], ['invalid'], ['2000-01-01 00:00:00'], ['2099-01-01 00:00:00', 'extra']] as $args) {
            [$code, $out, $err] = $run($args);
            $this->assertSame(2, $code); $this->assertSame('', $out); $this->assertNotSame('', $err);
        }
        [$code, $out, $err] = $run(['2099-01-01 00:00:00']);
        $this->assertSame(0, $code); $this->assertSame('', $err);
        $catalog = new CatalogDefinition(json_decode($out, false, 32, JSON_THROW_ON_ERROR));
        $this->assertSame('2099-01-01 00:00:00', $catalog->effectiveFrom);
        $this->assertSame($out, $run(['2099-01-01 00:00:00'])[1]);
    }
}
