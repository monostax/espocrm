<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Espo\Core\Exceptions\Conflict;
use InvalidArgumentException;

/** Pure ADR policy, not an authorization/checkout service. Persist decisions under the tenant lock. */
final class Routing
{
    /** Obligations include pending/uncertain creation attempts as well as active subscriptions. */
    public static function assignment(
        ?string $country,
        ?string $override,
        ?string $assigned,
        bool $hasObligations,
    ): array {
        self::country($country);
        foreach ([$override, $assigned] as $provider) {
            if ($provider !== null) self::provider($provider);
        }
        $requested = $override ?? match ($country) { 'BR' => 'asaas', 'US' => 'stripe', default => null };
        if ($hasObligations && $assigned === null) {
            throw new Conflict('Billing obligations require their original provider assignment.');
        }
        $effective = $hasObligations ? $assigned : $requested;
        return [
            'requestedProvider' => $requested, 'effectiveProvider' => $effective,
            'pinned' => $hasObligations, 'routeMismatch' => $requested !== $effective,
        ];
    }

    /** Caller must separately require an instance administrator and audit the edit. */
    public static function assertOverrideChange(
        ?string $country,
        ?string $newOverride,
        ?string $assigned,
        bool $hasObligations,
    ): void {
        $route = self::assignment($country, $newOverride, $assigned, $hasObligations);
        if ($hasObligations && $route['routeMismatch']) {
            throw new Conflict('Resolve billing obligations before provider reassignment.');
        }
    }

    /** New sales only. Existing subscriptions/events use their stored terms and originating binding. */
    public static function checkout(
        OfferTerms $terms,
        ?string $country,
        ?string $override,
        ?string $assigned,
        bool $hasObligations,
        ?ProviderCapability $capability = null,
        ?string $tenantId = null,
    ): array {
        $route = self::assignment($country, $override, $assigned, $hasObligations);
        $provider = $route['effectiveProvider'];
        $reason = match (true) {
            !$terms->availableToTenant($tenantId) => 'offer_unavailable',
            $country === null => 'billing_country_required',
            !in_array($country, $terms->countries, true) => 'market_ineligible',
            $provider === null => 'billing_route_missing',
            // Stripe is explicitly unavailable at launch, even if erroneously configured as enabled.
            $provider === 'stripe' => 'billing_provider_unavailable',
            $capability === null || $capability->provider !== $provider || !$capability->salesEnabled => 'billing_provider_unavailable',
            !in_array($terms->currency, $capability->currencies, true) => 'billing_currency_unsupported',
            !in_array($terms->operation, $capability->operations, true) => 'billing_operation_unsupported',
            default => null,
        };
        return $route + [
            'eligible' => $reason === null, 'blockedReason' => $reason,
            'integrationId' => $reason === null ? $capability->integrationId : null,
            'termsId' => $terms->termsId,
        ];
    }

    public static function provider(string $provider): void
    {
        if (!in_array($provider, ['asaas', 'stripe'], true)) {
            throw new InvalidArgumentException('Unknown billing provider.');
        }
    }

    private static function country(?string $country): void
    {
        if ($country !== null && !preg_match('/^[A-Z]{2}$/D', $country)) {
            throw new InvalidArgumentException('Billing country requires an uppercase two-letter code.');
        }
    }
}
