<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureGoogleAdsConversions\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionMapping;
use Espo\Modules\FeatureGoogleAdsConversions\Services\GoogleAdsEventBuilder;
use PHPUnit\Framework\TestCase;

class GoogleAdsEventBuilderTest extends TestCase
{
    private GoogleAdsEventBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new GoogleAdsEventBuilder();
    }

    public function testUnknownConsentDoesNotPermitUserData(): void
    {
        $event = $this->builder->build(
            $this->opportunity(),
            $this->mapping(),
            [
                'clickIds' => [],
                'consent' => [
                    'adUserData' => 'Unknown',
                    'adPersonalization' => 'Unknown',
                ],
            ],
            ['userIdentifiers' => [['emailAddress' => str_repeat('a', 64)]]],
            'mstx-test',
            new DateTimeImmutable('2026-08-20 12:00:00', new DateTimeZone('UTC')),
        );

        self::assertNull($event);
    }

    public function testGrantedConsentIncludesHashedUserData(): void
    {
        $userData = ['userIdentifiers' => [['emailAddress' => str_repeat('a', 64)]]];
        $event = $this->builder->build(
            $this->opportunity(),
            $this->mapping(),
            [
                'clickIds' => [],
                'consent' => [
                    'adUserData' => 'Granted',
                    'adPersonalization' => 'Granted',
                ],
            ],
            $userData,
            'mstx-test',
            new DateTimeImmutable('2026-08-20 12:00:00', new DateTimeZone('UTC')),
        );

        self::assertIsArray($event);
        self::assertSame($userData, $event['userData']);
        self::assertSame('2026-08-20T12:00:00Z', $event['eventTimestamp']);
        self::assertSame('CONSENT_GRANTED', $event['consent']['adUserData']);
    }

    public function testDeniedConsentStillAllowsClickOnlyConversion(): void
    {
        $event = $this->builder->build(
            $this->opportunity(),
            $this->mapping(),
            [
                'clickIds' => ['gclid' => 'test-click-id'],
                'consent' => [
                    'adUserData' => 'Denied',
                    'adPersonalization' => 'Denied',
                ],
            ],
            ['userIdentifiers' => [['emailAddress' => str_repeat('a', 64)]]],
            'mstx-test',
            new DateTimeImmutable('2026-08-20 12:00:00', new DateTimeZone('UTC')),
        );

        self::assertIsArray($event);
        self::assertArrayNotHasKey('userData', $event);
        self::assertSame(['gclid' => 'test-click-id'], $event['adIdentifiers']);
        self::assertSame('CONSENT_DENIED', $event['consent']['adUserData']);
    }

    private function opportunity(): Opportunity
    {
        $opportunity = $this->createMock(Opportunity::class);
        $opportunity->method('get')->willReturnCallback(
            static fn (string $attribute) => match ($attribute) {
                'amount' => 100.0,
                'amountCurrency' => 'BRL',
                default => null,
            },
        );

        return $opportunity;
    }

    private function mapping(): GoogleAdsConversionMapping
    {
        $mapping = $this->createMock(GoogleAdsConversionMapping::class);
        $mapping->method('get')->willReturnCallback(
            static fn (string $attribute) => match ($attribute) {
                'valueSource' => GoogleAdsConversionMapping::VALUE_SOURCE_NONE,
                'currencySource' => GoogleAdsConversionMapping::CURRENCY_SOURCE_OPPORTUNITY,
                default => null,
            },
        );

        return $mapping;
    }
}
