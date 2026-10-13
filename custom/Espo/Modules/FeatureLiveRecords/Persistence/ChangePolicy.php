<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Persistence;

use Espo\Core\Utils\Util;
use Espo\ORM\Entity;
use PDO;

/** Shared SQL/ORM policy: operational bookkeeping is not a record change. */
final class ChangePolicy
{
    public static function ignoredColumns(string $type): array
    {
        return array_merge(['version_number', 'modified_at', 'modified_by_id'], match ($type) {
            'JourneyRecord' => ['claimed_at'],
            'ChatwootAccount' => ['last_contact_sync_at', 'last_conversation_sync_at',
                'contact_sync_cursor', 'conversation_sync_cursor'],
            'ContactChannelIdentity' => ['ownership_synced_at'],
            'ChatwootAccountUserMembership' => ['last_synced_at'],
            'User' => ['last_access'],
            default => [],
        });
    }

    public static function snapshot(PDO $pdo, Entity $entity): ?array
    {
        if (!$entity->hasId()) return null;
        $table = Util::toUnderScore($entity->getEntityType());
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $table)) throw new \InvalidArgumentException('Invalid record table.');
        $quote = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`' : '"';
        $query = $pdo->prepare("SELECT * FROM $quote$table$quote WHERE id = ?");
        $query->execute([$entity->getId()]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        foreach (self::ignoredColumns($entity->getEntityType()) as $column) unset($row[$column]);
        if ($entity->getEntityType() === 'JourneyRecord' && ($row['status'] ?? null) === 'Processing') {
            $row['status'] = 'Active';
        }
        return $row;
    }

    public static function sqlChanged(string $driver, string $type, array $columns): string
    {
        $comparisons = [];
        $quote = $driver === 'mysql' ? '`' : '"';
        foreach (array_diff($columns, self::ignoredColumns($type)) as $column) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/D', $column)) throw new \InvalidArgumentException('Invalid record column.');
            $old = "OLD.$quote$column$quote";
            $new = "NEW.$quote$column$quote";
            if ($type === 'JourneyRecord' && $column === 'status') {
                $old = "(CASE WHEN $old = 'Processing' THEN 'Active' ELSE $old END)";
                $new = "(CASE WHEN $new = 'Processing' THEN 'Active' ELSE $new END)";
            }
            $comparisons[] = $driver === 'mysql'
                ? "NOT (CAST($old AS BINARY) <=> CAST($new AS BINARY))"
                : "$old IS DISTINCT FROM $new";
        }
        return $comparisons ? '(' . implode(' OR ', $comparisons) . ')' : 'FALSE';
    }

    public static function columns(PDO $pdo, string $table): array
    {
        $schema = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'DATABASE()' : 'current_schema()';
        $query = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = $schema AND table_name = ? ORDER BY ordinal_position");
        $query->execute([$table]);
        $columns = $query->fetchAll(PDO::FETCH_COLUMN);
        if (!$columns) throw new \RuntimeException('Live-record table has no columns.');
        return $columns;
    }
}
