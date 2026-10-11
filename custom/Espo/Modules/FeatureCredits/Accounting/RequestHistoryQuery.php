<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Tenant-bound authorization position; outcomes remain live between pages. */
final readonly class RequestHistoryQuery
{
    public ?string $beforeAuthorizedAt;
    public ?string $beforeId;

    public function __construct(public string $tenantId, public int $limit = 50, ?string $cursor = null)
    {
        GrantInput::identity($tenantId);
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Request limit must be between 1 and 100.');
        }
        $authorizedAt = $id = null;
        if ($cursor !== null) {
            if (strlen($cursor) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
                throw new InvalidArgumentException('Invalid request cursor.');
            }
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            $parts = $decoded === false ? [] : explode('|', $decoded);
            if (count($parts) !== 4 || $parts[0] !== 'requests-1' || $parts[1] !== $tenantId) {
                throw new InvalidArgumentException('Invalid request cursor tenant or version.');
            }
            [, , $authorizedAt, $id] = $parts;
            GrantInput::identity($id);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $authorizedAt, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $authorizedAt ||
                self::cursor($tenantId, $authorizedAt, $id) !== $cursor) {
                throw new InvalidArgumentException('Invalid request cursor position.');
            }
        }
        $this->beforeAuthorizedAt = $authorizedAt;
        $this->beforeId = $id;
    }

    public static function cursor(string $tenantId, string $authorizedAt, string $id): string
    {
        return rtrim(strtr(base64_encode("requests-1|$tenantId|$authorizedAt|$id"), '+/', '-_'), '=');
    }
}
