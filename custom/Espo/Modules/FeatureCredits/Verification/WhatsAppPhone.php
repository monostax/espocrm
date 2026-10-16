<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

/** Explicit international numbers, with the existing Brazilian shorthand only. */
final class WhatsAppPhone
{
    public static function normalize(mixed $raw): array
    {
        if (!is_string($raw)) return ['e164' => null, 'reason' => 'invalid_phone'];
        $value = trim($raw);
        if (!preg_match('/\A\+?[0-9() .-]+\z/', $value)) {
            return ['e164' => null, 'reason' => 'invalid_phone'];
        }
        $digits = preg_replace('/[^0-9]/', '', $value);
        if (!str_starts_with($value, '+') || str_starts_with($digits, '55')) {
            return BrazilianPhone::normalize($value);
        }
        return preg_match('/\A[1-9][0-9]{7,14}\z/', $digits)
            ? ['e164' => '+' . $digits, 'reason' => null]
            : ['e164' => null, 'reason' => 'invalid_phone'];
    }

    public static function e164(mixed $raw): ?string
    {
        return self::normalize($raw)['e164'];
    }
}
