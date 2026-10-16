<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

final class Kind
{
    public const PHONE = 'phone_identity';
    public const EMAIL = 'email_deliverability';
    public const WHATSAPP = 'whatsapp_registration';

    public static function price(string $kind): string
    {
        return match ($kind) {
            self::PHONE => '8.0000',
            self::EMAIL, self::WHATSAPP => '2.0000',
            default => throw new \InvalidArgumentException('Unsupported verification kind.'),
        };
    }

    public static function outcomes(string $kind): array
    {
        return match ($kind) {
            self::PHONE => ['verified', 'not_verified', 'insufficient_data'],
            self::EMAIL => ['deliverable', 'risky', 'undeliverable', 'unknown'],
            self::WHATSAPP => ['registered', 'not_registered'],
            default => [],
        };
    }

    public static function email(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = strtolower(trim($value));
        return strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    public static function fingerprint(string $key, string $tenant, string $contact, string $value, array $identity, string $policy, string $kind): string
    {
        if ($kind === self::PHONE) {
            return IdentityInput::fingerprintHash($key, $tenant, $contact, $value,
                $identity['name']['firstName'], $identity['name']['lastName'], $identity['dateOfBirth'], $policy);
        }
        if ($kind === self::WHATSAPP) {
            return hash_hmac('sha256', Operations::hash([$kind, $tenant, $contact, $value, $policy]), $key);
        }
        return hash_hmac('sha256', Operations::hash([self::EMAIL, $tenant, $contact, $value, 'regular', '0', $policy]), $key);
    }

    public static function revision(string $tenant, string $contact, string $value, array $identity, string $policy, string $kind): string
    {
        if ($kind === self::PHONE) {
            return Operations::computeContextRevision($tenant, $contact, $value,
                $identity['name']['firstName'], $identity['name']['lastName'], $identity['dateOfBirth'], $policy);
        }
        if ($kind === self::WHATSAPP) {
            return Operations::hash(['whatsapp-context-v1', $tenant, $contact, $value, $policy, self::price($kind)]);
        }
        return Operations::hash(['email-context-v1', $tenant, $contact, $value, 'regular', '0', $policy, self::price($kind)]);
    }
}
