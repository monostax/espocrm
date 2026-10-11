<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** A cursor is a tenant-bound position, never an authorization credential or snapshot. */
final readonly class TransactionHistoryQuery
{
    public ?string $beforePostedAt;
    public ?string $beforeId;

    public function __construct(public string $tenantId, public int $limit = 50, ?string $cursor = null)
    {
        GrantInput::identity($tenantId);
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('History limit must be between 1 and 100.');
        }
        $postedAt = $id = null;
        if ($cursor !== null) {
            if (strlen($cursor) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
                throw new InvalidArgumentException('Invalid history cursor.');
            }
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            $parts = $decoded === false ? [] : explode('|', $decoded);
            if (count($parts) !== 4 || $parts[0] !== '1' || $parts[1] !== $tenantId) {
                throw new InvalidArgumentException('Invalid history cursor tenant or version.');
            }
            [, , $postedAt, $id] = $parts;
            GrantInput::identity($id);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $postedAt, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $postedAt ||
                self::cursor($tenantId, $postedAt, $id) !== $cursor) {
                throw new InvalidArgumentException('Invalid history cursor position.');
            }
        }
        $this->beforePostedAt = $postedAt;
        $this->beforeId = $id;
    }

    public static function cursor(string $tenantId, string $postedAt, string $id): string
    {
        return rtrim(strtr(base64_encode("1|$tenantId|$postedAt|$id"), '+/', '-_'), '=');
    }
}
