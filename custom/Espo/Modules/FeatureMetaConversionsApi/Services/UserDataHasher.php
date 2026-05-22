<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Services;

/**
 * Normalises and SHA-256-hashes customer information per Meta's Conversions API spec.
 *
 * Reference: https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters
 *
 * Rules applied (per Meta docs):
 *   - All values are lowercased and trimmed before hashing.
 *   - Email: trim + lowercase, then sha256.
 *   - Phone: keep digits only, no leading '+', no spaces.
 *   - First/last name: lowercase, no honorifics. Punctuation kept.
 *   - Gender: 'm' or 'f'.
 *   - DoB: YYYYMMDD (no separators).
 *   - Country: ISO 3166-1 alpha-2, lowercase.
 *   - ZIP: lowercase, no spaces.
 *   - State: 2-letter US state code if known, else free lowercase.
 *
 * NOT hashed (per spec): lead_id, fbc, fbp, external_id, click_id, subscription_id, ip, user_agent.
 */
class UserDataHasher
{
    public function email(?string $value): ?string
    {
        $value = $this->lowerTrim($value);

        return $value !== null ? hash('sha256', $value) : null;
    }

    public function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        return hash('sha256', $digits);
    }

    public function name(?string $value): ?string
    {
        $value = $this->lowerTrim($value);

        return $value !== null ? hash('sha256', $value) : null;
    }

    public function gender(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        $first = $value[0];

        if (!in_array($first, ['m', 'f'], true)) {
            return null;
        }

        return hash('sha256', $first);
    }

    /**
     * @param string|null $value 'YYYY-MM-DD' or any parseable date format.
     */
    public function dateOfBirth(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $ts = strtotime($value);

        if ($ts === false) {
            return null;
        }

        return hash('sha256', date('Ymd', $ts));
    }

    public function country(?string $value): ?string
    {
        $value = $this->lowerTrim($value);

        if ($value === null) {
            return null;
        }

        // If a full country name was provided, we still hash whatever was supplied;
        // Meta recommends ISO 3166-1 alpha-2, but free text is forgiven downstream.
        return hash('sha256', $value);
    }

    public function zip(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        if ($value === '') {
            return null;
        }

        return hash('sha256', $value);
    }

    public function city(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        if ($value === '') {
            return null;
        }

        return hash('sha256', $value);
    }

    public function state(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        if ($value === '') {
            return null;
        }

        return hash('sha256', $value);
    }

    private function lowerTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value === '' ? null : $value;
    }
}
