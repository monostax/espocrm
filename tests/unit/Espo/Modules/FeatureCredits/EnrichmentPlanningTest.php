<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Enrichment\RoutePlanner;
use Espo\Modules\Global\Hooks\Account\NormalizeCnpj;
use Espo\Modules\Global\Tools\BrazilianIdentifier;
use Espo\ORM\Entity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnrichmentPlanningTest extends TestCase
{
    #[DataProvider('identifiers')]
    public function testIdentifiers(string $kind, mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, BrazilianIdentifier::$kind($input));
    }

    public static function identifiers(): iterable
    {
        yield ['cpf', '529.982.247-25', '52998224725'];
        yield ['cpf', '01234567890', '01234567890'];
        yield ['cnpj', '04.252.011/0001-10', '04252011000110'];
        yield ['cnpj', ' 12.abc.345/01de-35 ', '12ABC34501DE35'];
        foreach (['', null, 52998224725, '11111111111', '52998224724', '529.982247-25', '52998224725x'] as $bad) {
            yield ['cpf', $bad, null];
        }
        foreach (['', null, 4252011000110, '00000000000000', '11111111111111',
            '04252011000111', '12ABC34501DE36', '12ABC34501DEAA', '04/252011000110', '０4252011000110'] as $bad) {
            yield ['cnpj', $bad, null];
        }
    }

    private function target(string $type, array $values): Entity
    {
        $target = $this->createMock(Entity::class);
        $target->method('getEntityType')->willReturn($type);
        $target->method('get')->willReturnCallback(static fn ($field) => $values[$field] ?? null);
        return $target;
    }

    private function rates(): array
    {
        return [
            'cpfcnpj' => ['email' => ['version' => 'cpf-email-v1', 'credits' => '2.7000'],
                'phone' => ['version' => 'cpf-phone-v1', 'credits' => '2.7000']],
            'hub' => ['email' => ['version' => 'hub-email-v1', 'credits' => '7.5000'],
                'phone' => ['version' => 'hub-phone-v1', 'credits' => '7.5000']],
            'apollo' => ['email' => ['version' => 'apollo-email-v1', 'credits' => '1.0000'],
                'phone' => ['version' => 'apollo-phone-v1', 'credits' => '8.0000'],
                'company' => ['version' => 'apollo-company-v1', 'credits' => '1.0000']],
        ];
    }

    public function testBudgetSkipsExpensiveProviderButRetainsLaterAffordableProvider(): void
    {
        $plan = (new RoutePlanner())->plan($this->target('Contact', ['cpf' => '52998224725']),
            ['email'], true, Amount::fromString('3'), $this->rates());
        $this->assertSame(['cpfcnpj', 'apollo'], array_column($plan['routes']['email'], 'provider'));
        $this->assertSame('2.7000', $plan['maximum']);
        $this->assertSame('cpf-email-v1', $plan['routes']['email'][0]['version']);
    }

    public function testCombinedCapAndRemainingBudgetAreShared(): void
    {
        $planner = new RoutePlanner();
        $plan = $planner->plan($this->target('Contact', ['cpf' => '52998224725']),
            ['email', 'phone'], true, Amount::fromString('8'), $this->rates());
        $this->assertSame(['email', 'phone'], array_keys($plan['routes']));
        $this->assertSame('8.0000', $plan['maximum']);
        $this->assertSame(['cpfcnpj'], array_column($planner->affordable(
            $plan['routes']['phone'], Amount::fromString('5.3')), 'provider'));
        $this->assertSame([], $planner->affordable($plan['routes']['phone'], Amount::fromString('0.5')));
    }

    public function testAccountOrderAndCompanyRestriction(): void
    {
        $target = $this->target('Account', ['cnpj' => '12ABC34501DE35']);
        $rates = $this->rates();
        foreach (['hub', 'cpfcnpj'] as $provider) {
            $rates[$provider]['email']['credits'] = '5.1000';
            $rates[$provider]['company'] = ['version' => 'unsupported', 'credits' => '1'];
        }
        $planner = new RoutePlanner();
        $plan = $planner->plan($target, ['email'], true, Amount::fromString('10'), $rates);
        $this->assertSame(['hub', 'cpfcnpj', 'apollo'], array_column($plan['routes']['email'], 'provider'));
        $this->assertSame('5.1000', $plan['maximum']);
        $company = $planner->plan($target, ['company'], true, Amount::fromString('10'), $rates);
        $this->assertSame(['apollo'], array_column($company['routes']['company'], 'provider'));
    }

    public function testOffModeAndInvalidOwnIdentityCannotUseBrazilianProviders(): void
    {
        $planner = new RoutePlanner();
        foreach ([
            ['Contact', ['cpf' => '52998224725'], false],
            ['Contact', ['cnpj' => '04252011000110', 'accountId' => 'related'], true],
            ['Contact', ['cpf' => '11111111111'], true],
            ['Account', ['cpf' => '52998224725'], true],
        ] as [$type, $values, $waterfall]) {
            $plan = $planner->plan($this->target($type, $values), ['email'], $waterfall,
                Amount::fromString('10'), $this->rates());
            $this->assertSame(['apollo'], array_column($plan['routes']['email'], 'provider'));
        }
    }

    public function testUnavailableProvidersAndZeroBudgetCannotCreateHold(): void
    {
        $planner = new RoutePlanner();
        foreach ([[Amount::fromString('10'), []], [Amount::fromString('0'), $this->rates()]] as [$budget, $rates]) {
            $plan = $planner->plan($this->target('Contact', ['cpf' => '52998224725']),
                ['email', 'phone'], true, $budget, $rates);
            $this->assertSame(['email' => [], 'phone' => []], $plan['routes']);
            $this->assertSame('0.0000', $plan['maximum']);
        }
    }

    #[DataProvider('invalidPlans')]
    public function testRejectsInvalidPlan(string $type, array $products, string $budget): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RoutePlanner())->plan($this->target($type, []), $products, true,
            Amount::fromString($budget), $this->rates());
    }

    public static function invalidPlans(): iterable
    {
        yield ['Lead', ['email'], '10'];
        yield ['Contact', ['company'], '10'];
        yield ['Contact', ['phone', 'email'], '10'];
        yield ['Contact', ['email', 'email'], '10'];
        yield ['Contact', [], '10'];
        yield ['Account', ['email'], '-1'];
    }

    public function testAccountSaveNormalizesCnpj(): void
    {
        $entity = $this->target('Account', ['cnpj' => '12.abc.345/01de-35']);
        $entity->method('isNew')->willReturn(true);
        $entity->expects($this->once())->method('set')->with('cnpj', '12ABC34501DE35');
        (new NormalizeCnpj())->beforeSave($entity, []);
    }

    public function testAccountCnpjCanBeCleared(): void
    {
        $entity = $this->target('Account', ['cnpj' => '  ']);
        $entity->method('isAttributeChanged')->with('cnpj')->willReturn(true);
        $entity->expects($this->once())->method('set')->with('cnpj', null);
        (new NormalizeCnpj())->beforeSave($entity, []);
    }

    public function testCombinedMaximumDoesNotSumAllProviderPrices(): void
    {
        $plan = (new RoutePlanner())->plan($this->target('Contact', ['cpf' => '52998224725']),
            ['email', 'phone'], true, Amount::fromString('100'), $this->rates());
        $this->assertSame('15.5000', $plan['maximum']);
    }

    public function testCombinedMaximumSaturatesWithoutDecimalOverflow(): void
    {
        $rates = ['apollo' => [
            'email' => ['version' => 'large-email', 'credits' => '9999999999.9999'],
            'phone' => ['version' => 'large-phone', 'credits' => '9999999999.9999'],
        ]];
        $plan = (new RoutePlanner())->plan($this->target('Contact', []),
            ['email', 'phone'], false, Amount::fromString('9999999999.9999'), $rates);
        $this->assertSame('9999999999.9999', $plan['maximum']);
    }

    #[DataProvider('invalidRates')]
    public function testInvalidCatalogRateFailsClosed(array $rate): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RoutePlanner())->plan($this->target('Contact', []), ['email'], false,
            Amount::fromString('10'), ['apollo' => ['email' => $rate]]);
    }

    public static function invalidRates(): iterable
    {
        yield [['credits' => '1']];
        yield [['version' => '', 'credits' => '1']];
        yield [['version' => 'v1', 'credits' => '-1']];
        yield [['version' => 'v1', 'credits' => '0']];
        yield [['version' => 'v1', 'credits' => 1.1]];
    }

    public function testAccountSaveRejectsInvalidCnpjWithoutEchoingIdentifier(): void
    {
        $entity = $this->target('Account', ['cnpj' => '04252011000111']);
        $entity->method('isAttributeChanged')->with('cnpj')->willReturn(true);
        $entity->expects($this->never())->method('set');
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Invalid CNPJ.');
        (new NormalizeCnpj())->beforeSave($entity, []);
    }

    public function testUnchangedLegacyIdentifierDoesNotBlockUnrelatedSave(): void
    {
        $entity = $this->target('Account', []);
        $entity->method('isNew')->willReturn(false);
        $entity->method('isAttributeChanged')->willReturn(false);
        $entity->expects($this->never())->method('get');
        $entity->expects($this->never())->method('set');
        (new NormalizeCnpj())->beforeSave($entity, []);
    }
}
