<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class OperationHistoryQuery
{
    public ?string $beforeAdmittedAt;
    public ?string $beforeId;

    public function __construct(public string $tenantId, public int $limit = 50, ?string $cursor = null)
    {
        GrantInput::identity($tenantId);
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Operation limit must be between 1 and 100.');
        }
        $date = $id = null;
        if ($cursor !== null) {
            if (strlen($cursor) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
                throw new InvalidArgumentException('Invalid operation cursor.');
            }
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            $parts = $decoded === false ? [] : explode('|', $decoded);
            if (count($parts) !== 4 || $parts[0] !== 'operations-1' || $parts[1] !== $tenantId) {
                throw new InvalidArgumentException('Invalid operation cursor tenant or version.');
            }
            [, , $date, $id] = $parts;
            GrantInput::identity($id);
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new DateTimeZone('UTC'));
            if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $date ||
                self::cursor($tenantId, $date, $id) !== $cursor) {
                throw new InvalidArgumentException('Invalid operation cursor position.');
            }
        }
        $this->beforeAdmittedAt = $date;
        $this->beforeId = $id;
    }

    public static function cursor(string $tenantId, string $admittedAt, string $id): string
    {
        return rtrim(strtr(base64_encode("operations-1|$tenantId|$admittedAt|$id"), '+/', '-_'), '=');
    }
}
