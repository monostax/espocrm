<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use stdClass;

/** Trusted funding command, constructed only after payment/entitlement/migration verification. */
final readonly class GrantInput
{
    public string $credits;
    public string $snapshotJson;
    public string $hash;

    public function __construct(
        public string $tenantId,
        public string $sourceType,
        public string $sourceKey,
        mixed $credits,
        public string $occurredAt,
        public ?string $expiresAt,
        stdClass $sourceSnapshot,
        public ?string $actorId = null,
    ) {
        self::identity($tenantId);
        if ($actorId !== null) {
            self::identity($actorId);
        }
        if (!in_array($sourceType, ['purchase', 'subscription', 'migration'], true)) {
            throw new InvalidArgumentException('Invalid grant source.');
        }
        // Lowercase ASCII avoids different identity semantics under MySQL collations and PostgreSQL.
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $sourceKey)) {
            throw new InvalidArgumentException('A canonical lowercase funding source key is required.');
        }
        $amount = Amount::fromString($credits);
        if ($amount->compareTo(Amount::fromString('0')) <= 0) {
            throw new InvalidArgumentException('A grant must be positive.');
        }
        $this->credits = (string) $amount;
        self::timestamp($occurredAt);
        if ($expiresAt !== null) {
            self::timestamp($expiresAt);
            if ($expiresAt <= $occurredAt) {
                throw new InvalidArgumentException('Grant expiration must follow its occurrence.');
            }
        }
        if (($sourceType === 'purchase' && $expiresAt !== null) ||
            ($sourceType === 'subscription' && $expiresAt === null)) {
            throw new InvalidArgumentException('Purchases never expire; subscriptions require next-renewal expiration.');
        }
        $this->snapshotJson = json_encode(self::canonical($sourceSnapshot), JSON_THROW_ON_ERROR);
        $this->hash = hash('sha256', json_encode([
            'grant-v1', $tenantId, $sourceType, $sourceKey, $this->credits,
            $occurredAt, $expiresAt, $this->snapshotJson, $actorId,
        ], JSON_THROW_ON_ERROR));
    }

    public static function identity(string $id): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,24}$/D', $id)) {
            throw new InvalidArgumentException('Invalid accounting entity identity.');
        }
    }

    public static function timestamp(string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Expected a valid UTC timestamp, Y-m-d H:i:s.');
        }
    }

    public static function canonical(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('Accounting evidence is too deeply nested.');
        }
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            $result = new stdClass();
            foreach ($fields as $key => $item) {
                $result->$key = self::canonical($item, $depth + 1);
            }
            return $result;
        }
        if (is_array($value) && array_is_list($value)) {
            return array_map(static fn ($item) => self::canonical($item, $depth + 1), $value);
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }
        throw new InvalidArgumentException('Evidence requires JSON objects/lists/scalars; decimal values must be strings.');
    }
}
