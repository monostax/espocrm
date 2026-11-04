<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Tools\Signup;

/**
 * The verified email and name in a Google or Microsoft ID token.
 *
 * The token comes straight from the provider's token endpoint over TLS, in
 * exchange for our client secret, so its signature isn't re-checked (OIDC
 * Core 3.1.3.7). Issuer, audience and expiry are.
 *
 * An email is trusted only if the provider vouches for it:
 * - Google: `email_verified`.
 * - Microsoft: personal accounts (consumer tenant), or a work account whose
 *   token has `xms_edov` (the email's domain is verified by the tenant). Work
 *   account emails are otherwise editable by any tenant admin, which allows
 *   account takeover by email ("nOAuth").
 */
final class SocialIdentity
{
    public const GOOGLE = 'google';
    public const MICROSOFT = 'microsoft';

    private const MICROSOFT_CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';
    private const CLOCK_SKEW_SECONDS = 120;

    private function __construct(
        public readonly string $email,
        public readonly string $name,
    ) {}

    /**
     * @throws SignupRejected
     */
    public static function fromIdToken(string $provider, string $idToken, string $clientId, int $now): self
    {
        $claims = self::claims($idToken);

        $audience = (array) ($claims['aud'] ?? []);
        $expires = (int) ($claims['exp'] ?? 0);

        if (!in_array($clientId, $audience, true) || $expires + self::CLOCK_SKEW_SECONDS < $now) {
            throw new SignupRejected(SignupRejected::SOCIAL_FAILED);
        }

        $issuer = (string) ($claims['iss'] ?? '');
        $email = strtolower(trim((string) ($claims['email'] ?? '')));

        $trusted = match ($provider) {
            self::GOOGLE => in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)
                && ($claims['email_verified'] ?? false) === true,
            self::MICROSOFT => self::isMicrosoftIssuer($issuer, (string) ($claims['tid'] ?? ''))
                && (($claims['tid'] ?? null) === self::MICROSOFT_CONSUMER_TENANT
                    || in_array($claims['xms_edov'] ?? null, [true, 1, '1', 'true'], true)),
            default => false,
        };

        if (!$trusted || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SignupRejected(SignupRejected::SOCIAL_EMAIL_UNVERIFIED);
        }

        $name = trim((string) ($claims['name'] ?? ''));

        if ($name === '') {
            $name = trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? ''));
        }

        if (mb_strlen($name) < 2) {
            $name = explode('@', $email)[0];
        }

        return new self($email, mb_substr($name, 0, 100));
    }

    private static function isMicrosoftIssuer(string $issuer, string $tenantId): bool
    {
        return $tenantId !== '' && $issuer === "https://login.microsoftonline.com/{$tenantId}/v2.0";
    }

    /**
     * @return array<string, mixed>
     * @throws SignupRejected
     */
    private static function claims(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new SignupRejected(SignupRejected::SOCIAL_FAILED);
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $payload !== false ? json_decode($payload, true) : null;

        if (!is_array($claims)) {
            throw new SignupRejected(SignupRejected::SOCIAL_FAILED);
        }

        return $claims;
    }
}
