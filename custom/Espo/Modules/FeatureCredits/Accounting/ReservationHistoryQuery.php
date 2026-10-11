<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Tenant-bound creation position; reservation states remain live between pages. */
final readonly class ReservationHistoryQuery
{
    public ?string $beforeCreatedAt;
    public ?string $beforeId;

    public function __construct(public string $tenantId, public int $limit = 50, ?string $cursor = null)
    {
        GrantInput::identity($tenantId);
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Reservation limit must be between 1 and 100.');
        }
        $createdAt = $id = null;
        if ($cursor !== null) {
            if (strlen($cursor) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
                throw new InvalidArgumentException('Invalid reservation cursor.');
            }
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            $parts = $decoded === false ? [] : explode('|', $decoded);
            if (count($parts) !== 4 || $parts[0] !== 'reservations-1' || $parts[1] !== $tenantId) {
                throw new InvalidArgumentException('Invalid reservation cursor tenant or version.');
            }
            [, , $createdAt, $id] = $parts;
            GrantInput::identity($id);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $createdAt, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $createdAt ||
                self::cursor($tenantId, $createdAt, $id) !== $cursor) {
                throw new InvalidArgumentException('Invalid reservation cursor position.');
            }
        }
        $this->beforeCreatedAt = $createdAt;
        $this->beforeId = $id;
    }

    public static function cursor(string $tenantId, string $createdAt, string $id): string
    {
        return rtrim(strtr(base64_encode("reservations-1|$tenantId|$createdAt|$id"), '+/', '-_'), '=');
    }
}
