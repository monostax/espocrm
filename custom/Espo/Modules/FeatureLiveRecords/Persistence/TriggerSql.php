<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Persistence;

use InvalidArgumentException;
use PDO;
use Espo\Modules\FeatureLiveRecords\Scope;

/** Covers tenant-owned writes made outside Espo's ORM (including backend SQL). */
final class TriggerSql
{
    public static function statements(string $driver, string $type, string $table, bool $versioned, bool $scoped = false, ?array $columns = null): array
    {
        if (!in_array($driver, ['mysql', 'pgsql'], true) || !preg_match('/^[A-Z][a-zA-Z0-9]{0,63}$/D', $type) ||
            !preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $table)) throw new InvalidArgumentException('Invalid trigger target.');
        $result = [];
        $prefix = 'lr_' . substr(hash('sha256', $table), 0, 16);
        foreach (['INSERT' => 'create', 'UPDATE' => 'update', 'DELETE' => 'delete'] as $operation => $kind) {
            $name = $prefix . '_' . strtolower($operation);
            $body = '';
            if ($operation !== 'INSERT') {
                $changed = $operation === 'UPDATE'
                    ? ' AND (' . ($driver === 'mysql' ? 'NOT (OLD.tenant_id <=> NEW.tenant_id)' : 'OLD.tenant_id IS DISTINCT FROM NEW.tenant_id') .
                        ($scoped ? ' OR NOT (' . self::scopeCondition($type, 'NEW') . ')' : '') . ')' : '';
                $body .= self::insert($driver, $type, 'OLD', $kind, $changed, $scoped);
            }
            if ($operation !== 'DELETE') $body .= self::insert($driver, $type, 'NEW', $kind, '', $scoped);
            if ($operation === 'UPDATE' && $columns !== null) {
                $body = 'IF ' . ChangePolicy::sqlChanged($driver, $type, $columns) . " THEN $body END IF; ";
            }
            if ($driver === 'mysql') {
                $result[] = "DROP TRIGGER IF EXISTS `$name`";
                $result[] = "CREATE TRIGGER `$name` AFTER $operation ON `$table` FOR EACH ROW BEGIN $body END";
            } else {
                $result[] = "CREATE OR REPLACE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN $body RETURN NULL; END; \$\$";
                $result[] = "DROP TRIGGER IF EXISTS $name ON \"$table\"";
                $result[] = "CREATE TRIGGER $name AFTER $operation ON \"$table\" FOR EACH ROW EXECUTE FUNCTION $name()";
            }
        }
        if ($versioned) {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $name = $prefix . '_version_' . strtolower($operation);
                $condition = $operation === 'INSERT' ? 'COALESCE(NEW.version_number, 0) < 1'
                    : 'COALESCE(NEW.version_number, 0) <= COALESCE(OLD.version_number, 0)';
                $next = $operation === 'INSERT' ? '1' : 'COALESCE(OLD.version_number, 0) + 1';
                if ($driver === 'mysql') {
                    $result[] = "DROP TRIGGER IF EXISTS `$name`";
                    $result[] = "CREATE TRIGGER `$name` BEFORE $operation ON `$table` FOR EACH ROW BEGIN IF $condition THEN SET NEW.version_number = $next; END IF; END";
                } else {
                    $result[] = "CREATE OR REPLACE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF $condition THEN NEW.version_number := $next; END IF; RETURN NEW; END; \$\$";
                    $result[] = "DROP TRIGGER IF EXISTS $name ON \"$table\"";
                    $result[] = "CREATE TRIGGER $name BEFORE $operation ON \"$table\" FOR EACH ROW EXECUTE FUNCTION $name()";
                }
            }
        }
        return $result;
    }

    private static function insert(string $driver, string $type, string $row, string $kind, string $extra = '', bool $scoped = false): string
    {
        $access = in_array($type, Scope::ACCESS, true);
        if ($access) $kind = 'access';
        $id = $driver === 'mysql' ? 'SUBSTRING(MD5(UUID()), 1, 17)' : "SUBSTRING(MD5(random()::text || clock_timestamp()::text), 1, 17)";
        $now = $driver === 'mysql' ? 'UTC_TIMESTAMP()' : "timezone('UTC', CURRENT_TIMESTAMP)";
        $json = $driver === 'mysql'
            ? "JSON_OBJECT('version', 1, 'kind', '$kind', 'records', JSON_ARRAY(JSON_OBJECT('type', '$type', 'id', CAST($row.id AS CHAR))))"
            : "json_build_object('version', 1, 'kind', '$kind', 'records', json_build_array(json_build_object('type', '$type', 'id', $row.id::text)))::text";
        if ($access) {
            $json = $driver === 'mysql'
                ? "JSON_OBJECT('version', 1, 'kind', 'access', 'records', JSON_ARRAY())"
                : "json_build_object('version', 1, 'kind', 'access', 'records', json_build_array())::text";
        }
        $condition = $scoped ? ' AND (' . self::scopeCondition($type, $row) . ')' : '';
        return "IF $row.tenant_id IS NOT NULL AND $row.tenant_id <> ''$condition$extra THEN
            INSERT INTO live_record_outbox (id, tenant_id, intent, created_at, due_at, attempts)
            VALUES ($id, $row.tenant_id, $json, $now, $now, 0); END IF; ";
    }

    private static function scopeCondition(string $type, string $row): string
    {
        if (!in_array($type, Scope::DEPENDENCIES, true)) return 'TRUE';
        $types = "'" . implode("','", Scope::RECORD_TYPES) . "'";
        // COALESCE makes leaving the scope (including NULL) invalidate the old
        // panel. Both engines otherwise propagate NULL through NOT/OR.
        $predicate = match ($type) {
            'ContactChannelIdentity' => "$row.contact_id IS NOT NULL",
            'Note', 'ActivityReadState' => "$row.parent_type IN ($types)",
            'RecordDocument' => "$row.record_type IN ($types)",
            'RecordRelation' => "$row.subject_type IN ($types) OR $row.object_type IN ($types)",
            'Document' => "$row.knowledge_record_type IN ($types)",
            'DocumentRevision' => "EXISTS (SELECT 1 FROM document d WHERE d.id = $row.document_id AND d.knowledge_record_type IN ($types))",
            'UserReaction' => "$row.parent_type = 'Note' AND EXISTS (SELECT 1 FROM note n WHERE n.id = $row.parent_id AND n.parent_type IN ($types))",
            'InitiativeRelation' => "$row.initiative_id IS NOT NULL",
            'OpportunityReadState', 'PlaybookRun' => "$row.opportunity_id IS NOT NULL",
            'PlaybookRunStep' => "$row.run_id IS NOT NULL OR $row.task_id IS NOT NULL",
            default => 'FALSE',
        };
        return "COALESCE(($predicate), FALSE)";
    }

    /** Remove only this feature's hashed triggers, including previously broad registrations. */
    public static function removeObsolete(PDO $pdo, array $activeTables): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $filter = $driver === 'mysql' ? 'trigger_schema = DATABASE()' : 'trigger_schema = current_schema()';
        $rows = $pdo->query("SELECT DISTINCT trigger_name AS name, event_object_table AS table_name
            FROM information_schema.triggers WHERE $filter")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $name = $row['name'];
            $table = $row['table_name'];
            $prefix = 'lr_' . substr(hash('sha256', $table), 0, 16) . '_';
            if (!preg_match('/\A' . $prefix . '(insert|update|delete|version_insert|version_update)\z/', $name, $match)) continue;
            if (array_key_exists($table, $activeTables) &&
                ($activeTables[$table] || !str_starts_with($match[1], 'version_'))) continue;
            if ($driver === 'mysql') {
                $pdo->exec("DROP TRIGGER IF EXISTS `$name`");
            } else {
                $quotedTable = '"' . str_replace('"', '""', $table) . '"';
                $pdo->exec("DROP TRIGGER IF EXISTS $name ON $quotedTable");
                $pdo->exec("DROP FUNCTION IF EXISTS $name()");
            }
        }
    }
}
