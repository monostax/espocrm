<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

/** Customer HTTP contracts, applied only after original receipt/binding verification. */
final class CustomerProjection
{
    private static function only(array $value, array $keys): array
    {
        return array_intersect_key($value, array_flip($keys));
    }

    public static function terms(array $value): array
    {
        return self::only($value, ['termsId', 'schema', 'offerId', 'version', 'productId', 'productVersion',
            'operation', 'countries', 'currency', 'price', 'credits', 'interval']);
    }

    public static function acceptance(array $value): array
    {
        return self::only($value, ['obligationId', 'tenantId', 'requestKey', 'catalogId', 'billingCountry',
            'assignmentRevision', 'acceptedAt', 'dispatchAllowed']) + ['terms' => self::terms($value['terms'])];
    }

    public static function account(array $value): array
    {
        return self::only($value, ['tenantId', 'billingCountry', 'revision', 'openObligations', 'obligationId', 'state']);
    }

    public static function context(array $value): array
    {
        $result = self::account($value) + self::only($value, ['catalogId', 'catalogUnavailable', 'earliestDueDate', 'hasMoreCheckouts']);
        $result['offers'] = array_map(static fn (array $offer): array => [
            'terms' => self::terms($offer['terms']),
            'eligibility' => ['eligible' => $offer['eligibility']['eligible'],
                'blockedReason' => $offer['eligibility']['eligible'] ? null : 'checkout_unavailable'],
        ], $value['offers']);
        if (isset($value['checkouts'])) $result['checkouts'] = array_map(self::obligation(...), $value['checkouts']);
        return $result;
    }

    public static function obligation(array $value): array
    {
        return ['state' => $value['state'], 'acceptance' => self::acceptance($value['acceptance'])];
    }

    public static function change(array $value): array
    {
        return self::only($value, ['changeId', 'tenantId', 'kind', 'changedAt']) +
            ['before' => self::account($value['before']), 'after' => self::account($value['after'])];
    }

    public static function creation(array $value): array
    {
        return self::only($value, ['creationId', 'tenantId', 'obligationId', 'kind', 'state', 'dispatchAllowed', 'environment']);
    }

    public static function subscription(array $value): array
    {
        $status = $value['providerStatus'] ?? null;
        return self::only($value, ['tenantId', 'obligationId', 'state', 'cancellationPending', 'observedAt']) +
            ['status' => in_array($status, ['ACTIVE', 'INACTIVE', 'EXPIRED', 'DELETED'], true) ? $status : null];
    }

    public static function checkout(array $value): array
    {
        $result = self::obligation($value) + self::only($value, ['tenantId', 'obligationId', 'earliestDueDate']);
        foreach (['customer', 'charge', 'subscription'] as $key) {
            $result[$key] = $value[$key] === null ? null :
                ($key === 'subscription' ? self::subscription($value[$key]) : self::creation($value[$key]));
        }
        return $result;
    }

    /** The external payment destination is intentional; internal processor status/bindings are not. */
    public static function payment(array $value): array
    {
        return self::only($value, ['tenantId', 'obligationId', 'environment', 'state', 'invoiceUrl']);
    }
}
