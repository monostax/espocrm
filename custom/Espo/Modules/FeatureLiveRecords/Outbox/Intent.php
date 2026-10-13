<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Outbox;

use InvalidArgumentException;

/** Server-only routing identities; never a browser event or an authorization grant. */
final readonly class Intent
{
    public function __construct(
        public string $tenantId,
        public string $kind,
        public array $records,
    ) {
        self::identity($tenantId);
        if (!in_array($kind, ['create', 'update', 'delete', 'link', 'unlink', 'access'], true)) {
            throw new InvalidArgumentException('Unsupported live-record mutation.');
        }
        if (($records === [] && $kind !== 'access') || !array_is_list($records)) {
            throw new InvalidArgumentException('Routing identities are required.');
        }
        foreach ($records as $record) {
            if (!is_array($record) || count($record) !== 2 ||
                !is_string($record['type'] ?? null) || !is_string($record['id'] ?? null) ||
                !preg_match('/^[A-Z][a-zA-Z0-9]{0,63}$/D', $record['type'])) {
                throw new InvalidArgumentException('Invalid routing identity.');
            }
            self::identity($record['id']);
        }
    }

    public function encode(): string
    {
        return json_encode([
            'version' => 1,
            'kind' => $this->kind,
            'records' => array_values(array_unique($this->records, SORT_REGULAR)),
        ], JSON_THROW_ON_ERROR);
    }

    private static function identity(string $value): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value)) {
            throw new InvalidArgumentException('Invalid routing identity.');
        }
    }
}
