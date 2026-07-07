<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use Espo\Core\Utils\Config;
use RuntimeException;

/**
 * Mints and verifies self-contained, HMAC-signed contact tokens — the
 * identity carrier for channels where neither cookies nor an authenticated
 * session exist (short-link redirects, and the browser SDK's `contactToken`
 * body field on the landing page).
 *
 * Model: WE mint, WE verify — no third party ever needs the key, so the
 * installation-private `cryptKey` (the same secret Espo\Core\Utils\Crypt
 * encrypts source secrets with) is used as the HMAC key. Tokens are
 * stateless: no lookup table, nothing to clean up.
 *
 * Format (URL-safe, ~120 chars):
 *   base64url(json{c: contactId, t: tenantId, e: expUnixSeconds}) . "." .
 *   base64url(hmac_sha256_raw(payloadB64, cryptKey))
 *
 * The tenantId is embedded so verifiers can (and must) assert the token's
 * tenant matches the resource it arrives through (link's tenant, source's
 * tenant) — a token minted for tenant A is inert everywhere else. Expiry is
 * mandatory (default 90 days: email links live long).
 *
 * verify() never throws — invalid/expired/foreign tokens return null and the
 * caller degrades to the anonymous path.
 */
class ContactToken
{
    public const DEFAULT_TTL_DAYS = 90;

    public function __construct(
        private Config $config,
    ) {}

    /**
     * @throws RuntimeException when the installation has no cryptKey.
     */
    public function mint(string $contactId, string $tenantId, ?int $ttlDays = null): string
    {
        $key = $this->key();

        if ($key === '') {
            throw new RuntimeException('ContactToken: no cryptKey configured; cannot mint tokens.');
        }

        $days = $ttlDays !== null && $ttlDays > 0 ? $ttlDays : self::DEFAULT_TTL_DAYS;

        $payload = self::base64UrlEncode((string) json_encode([
            'c' => $contactId,
            't' => $tenantId,
            'e' => time() + $days * 86400,
        ]));

        return $payload . '.' . self::base64UrlEncode(hash_hmac('sha256', $payload, $key, true));
    }

    /**
     * @return ?array{contactId: string, tenantId: string} null when invalid.
     */
    public function verify(?string $token): ?array
    {
        if (!is_string($token) || $token === '' || strlen($token) > 512) {
            return null;
        }

        $key = $this->key();

        if ($key === '') {
            return null;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;

        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, $key, true));

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $decoded = self::base64UrlDecode($payload);

        if ($decoded === null) {
            return null;
        }

        $data = json_decode($decoded, true);

        if (
            !is_array($data) ||
            !is_string($data['c'] ?? null) || $data['c'] === '' ||
            !is_string($data['t'] ?? null) || $data['t'] === '' ||
            !is_int($data['e'] ?? null)
        ) {
            return null;
        }

        if ($data['e'] < time()) {
            return null;
        }

        return [
            'contactId' => $data['c'],
            'tenantId' => $data['t'],
        ];
    }

    private function key(): string
    {
        $key = $this->config->get('cryptKey');

        return is_string($key) ? $key : '';
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
