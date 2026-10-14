<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use InvalidArgumentException;

/**
 * Canonical identity preparation and keyed digests for phone verification (D14, D16, Section 5).
 * No raw personal data is placed in index keys or logs.
 */
final class IdentityInput
{
    public const VERSION = 'phone-verification-v1';

    /**
     * Split combined Contact name into first word and remaining words for Brazil (D14).
     *
     * @return ?array{firstName: string, lastName: string, fullName: string}
     */
    public static function splitBrazilName(?string $firstName, ?string $lastName, ?string $fullName = null): ?array
    {
        $combined = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            trim((string) $firstName) . ' ' . trim((string) $lastName),
        ));
        if ($combined === '' && $fullName !== null) {
            $combined = trim((string) preg_replace('/\s+/u', ' ', $fullName));
        }
        if ($combined === '') {
            return null;
        }
        $parts = preg_split('/\s+/u', $combined, 2);
        if (!is_array($parts) || count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        return [
            'firstName' => $parts[0],
            'lastName' => $parts[1],
            'fullName' => $parts[0] . ' ' . $parts[1],
        ];
    }

    public static function validateDateOfBirth(mixed $dob, string $nowUtc): ?string
    {
        if ($dob === null || $dob === '') {
            return null;
        }
        if (!is_string($dob)) {
            throw new InvalidArgumentException('Invalid date of birth.');
        }
        $value = trim($dob);
        if (!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $matches)) {
            throw new InvalidArgumentException('Date of birth must use YYYY-MM-DD format.');
        }
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        if ($year < 1900 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('Date of birth must be a valid calendar date.');
        }
        $today = substr($nowUtc, 0, 10);
        if ($value > $today) {
            throw new InvalidArgumentException('Date of birth cannot be in the future.');
        }
        return $value;
    }

    public static function twilioDateOfBirth(?string $dob): ?string
    {
        return $dob === null ? null : str_replace('-', '', $dob);
    }

    public static function hmacKey(string $secret): string
    {
        return hash_hmac('sha256', 'feature-credits-phone-verification-hmac-v1', $secret);
    }

    public static function subjectHash(string $hmacKey, string $tenantId, string $contactId, string $e164): string
    {
        return hash_hmac(
            'sha256',
            json_encode(['phone-verification-subject-v1', $tenantId, $contactId, $e164], JSON_THROW_ON_ERROR),
            $hmacKey,
        );
    }

    public static function fingerprintHash(
        string $hmacKey,
        string $tenantId,
        string $contactId,
        string $e164,
        string $firstName,
        string $lastName,
        ?string $dob,
        string $policyVersion,
    ): string {
        return hash_hmac(
            'sha256',
            json_encode([
                self::VERSION,
                $tenantId,
                $contactId,
                $e164,
                $firstName,
                $lastName,
                $dob,
                $policyVersion,
            ], JSON_THROW_ON_ERROR),
            $hmacKey,
        );
    }
}
