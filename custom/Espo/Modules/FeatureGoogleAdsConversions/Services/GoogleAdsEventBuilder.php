<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionMapping;

/**
 * Builds one Google Ads offline conversion Event for Data Manager v1.
 */
class GoogleAdsEventBuilder
{
    private const CURRENCIES = ['USD', 'EUR', 'BRL', 'GBP', 'MXN', 'ARS'];

    /**
     * @param array{
     *   clickIds: array{gclid?: string, gbraid?: string, wbraid?: string},
     *   consent: array{adUserData: string, adPersonalization: string}
     * } $attribution
     * @param array{userIdentifiers: list<array<string, mixed>>}|array{} $userData
     * @return array<string, mixed>|null
     */
    public function build(
        Opportunity $opportunity,
        GoogleAdsConversionMapping $mapping,
        array $attribution,
        array $userData,
        string $transactionId,
        DateTimeImmutable $eventTime,
    ): ?array {
        $adUserDataConsent = $attribution['consent']['adUserData'];

        if ($adUserDataConsent !== 'Granted') {
            $userData = [];
        }

        $clickIds = $attribution['clickIds'];

        if ($clickIds === [] && $userData === []) {
            return null;
        }

        $event = [
            'transactionId' => $transactionId,
            'eventTimestamp' => $eventTime
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
            'eventSource' => 'WEB',
            'consent' => [
                'adUserData' => $this->consentStatus($adUserDataConsent),
                'adPersonalization' => $this->consentStatus(
                    $attribution['consent']['adPersonalization'],
                ),
            ],
        ];

        if ($clickIds !== []) {
            $event['adIdentifiers'] = $clickIds;
        }

        if ($userData !== []) {
            $event['userData'] = $userData;
        }

        $value = $this->value($opportunity, $mapping);
        $currency = $value !== null ? $this->currency($opportunity, $mapping) : null;

        if ($value !== null) {
            $event['conversionValue'] = $value;
        }

        if ($currency !== null) {
            $event['currency'] = $currency;
        }

        return $event;
    }

    private function value(
        Opportunity $opportunity,
        GoogleAdsConversionMapping $mapping,
    ): ?float {
        $raw = match ($mapping->get('valueSource')) {
            GoogleAdsConversionMapping::VALUE_SOURCE_OPPORTUNITY_AMOUNT => $opportunity->get('amount'),
            GoogleAdsConversionMapping::VALUE_SOURCE_FIXED => $mapping->get('fixedValue'),
            default => null,
        };

        if (!is_numeric($raw) || !is_finite((float) $raw) || (float) $raw < 0) {
            return null;
        }

        return (float) $raw;
    }

    private function currency(
        Opportunity $opportunity,
        GoogleAdsConversionMapping $mapping,
    ): ?string {
        $currency = $mapping->get('currencySource') === GoogleAdsConversionMapping::CURRENCY_SOURCE_FIXED
            ? $mapping->get('fixedCurrency')
            : $opportunity->get('amountCurrency');

        return is_string($currency) && in_array($currency, self::CURRENCIES, true)
            ? $currency
            : null;
    }

    private function consentStatus(string $value): string
    {
        return match ($value) {
            'Granted' => 'CONSENT_GRANTED',
            'Denied' => 'CONSENT_DENIED',
            default => 'CONSENT_STATUS_UNSPECIFIED',
        };
    }
}
