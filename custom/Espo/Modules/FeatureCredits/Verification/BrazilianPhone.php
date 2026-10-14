<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

/**
 * Brazil-only E.164 phone normalization for identity verification (D9, D22).
 * Never infers or strips subscriber digits (such as Brazil's ninth digit).
 */
final class BrazilianPhone
{
    /** @return array{e164: ?string, reason: ?string} */
    public static function normalize(mixed $raw): array
    {
        if (!is_string($raw)) {
            return ['e164' => null, 'reason' => 'invalid_phone'];
        }
        $value = trim($raw);
        if ($value === '' || str_contains($value, '@') || !preg_match('/\A\+?[0-9() .-]+\z/', $value)) {
            return ['e164' => null, 'reason' => 'invalid_phone'];
        }
        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/[^0-9]/', '', $value);
        if (!is_string($digits) || $digits === '') {
            return ['e164' => null, 'reason' => 'invalid_phone'];
        }
        if ($hasPlus) {
            if (!str_starts_with($digits, '55')) {
                return [
                    'e164' => null,
                    'reason' => preg_match('/\A[1-9][0-9]{7,14}\z/', $digits) ? 'unsupported_country' : 'invalid_phone',
                ];
            }
            $national = substr($digits, 2);
        } elseif (in_array(strlen($digits), [10, 11], true)) {
            $national = $digits;
        } elseif (in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, '55')) {
            $national = substr($digits, 2);
        } else {
            return ['e164' => null, 'reason' => 'invalid_phone'];
        }
        if (!preg_match('/\A([1-9][1-9])([2-9][0-9]{7}|9[0-9]{8})\z/', $national)) {
            return ['e164' => null, 'reason' => 'invalid_phone'];
        }
        return ['e164' => '+55' . $national, 'reason' => null];
    }

    public static function e164(mixed $raw): ?string
    {
        return self::normalize($raw)['e164'];
    }
}
