<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools;

use PDO;

/**
 * Merges a duplicate Contact (loser) into another (survivor) when the loser
 * is an empty shell: a second CRM contact created for the same WhatsApp line
 * (Brazilian numbers with and without the mobile 9th digit).
 *
 * Every pair is re-validated before anything is written, and refused unless:
 * both contacts are live and in the same tenant; each is linked to a
 * chatwoot_contact in a shared Chatwoot account whose phones are the same
 * number or differ only by the 9th digit; and the loser is referenced by
 * nothing except what this class moves (Chatwoot bridges, channel
 * identities, phone/email/account/team links). Any other reference
 * (conversations, messages, opportunities, activities, notes, ...) refuses
 * the pair, so no business record is ever moved or orphaned.
 *
 * Moves: Chatwoot bridges, identities (soft-deleting exact duplicates),
 * phone/email/account links the survivor lacks. Then soft-deletes the loser,
 * so it can be restored. Each pair runs in its own transaction.
 */
final class DuplicateContactMerger
{
    /** References this class moves or drops itself; anything else blocks a merge. */
    private const HANDLED = [
        'chatwoot_contact.contact_id', 'chatwoot_contact_inbox.contact_id', 'contact_channel_identity.contact_id',
        'account_contact.contact_id',
    ];

    /**
     * @param ?callable(): list<array{table: string, column: string, parent: bool, deleted: bool}> $references
     *   Columns that can reference a Contact (and whether the table has a deleted flag);
     *   discovered from information_schema when null.
     */
    public function __construct(private PDO $pdo, private $references = null) {}

    /** @var ?list<array{table: string, column: string, parent: bool, deleted: bool}> */
    private ?array $referenceCache = null;

    /**
     * @return array{status: string, reason?: string, counts: array<string, int>}
     */
    public function merge(string $survivorId, string $loserId, bool $apply): array
    {
        $refusal = $this->validate($survivorId, $loserId);
        if ($refusal !== null) {
            return ['status' => 'refused', 'reason' => $refusal, 'counts' => []];
        }
        if (!$apply) {
            // Dry run: the same plan, counted with reads only. Nothing is written or locked.
            return ['status' => 'would merge', 'counts' => $this->move($survivorId, $loserId, false)];
        }
        $this->pdo->beginTransaction();
        try {
            $counts = $this->move($survivorId, $loserId, true);
            // Re-check inside the transaction: nothing new may reference the loser.
            $blocking = $this->blockingReferences($loserId);
            if ($blocking !== null) {
                $this->pdo->rollBack();
                return ['status' => 'refused', 'reason' => "loser referenced by $blocking", 'counts' => []];
            }
            $this->pdo->commit();
            return ['status' => 'merged', 'counts' => $counts];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Merge when the loser contact exists; repair when it was purged and only
     * Chatwoot bridges still point at its id.
     *
     * @return array{status: string, reason?: string, counts: array<string, int>}
     */
    public function mergeOrRepair(string $survivorId, string $loserId, bool $apply): array
    {
        return $this->scalar('SELECT COUNT(*) FROM contact WHERE id = ?', [$loserId]) === 0
            ? $this->repairDangling($survivorId, $loserId, $apply)
            : $this->merge($survivorId, $loserId, $apply);
    }

    /**
     * Re-point Chatwoot bridges whose contact_id names a contact that no longer
     * exists (purged) to the survivor on the same WhatsApp line. Deletes nothing.
     *
     * @return array{status: string, reason?: string, counts: array<string, int>}
     */
    public function repairDangling(string $survivorId, string $danglingId, bool $apply): array
    {
        $refusal = $this->validateDangling($survivorId, $danglingId);
        if ($refusal !== null) {
            return ['status' => 'refused', 'reason' => $refusal, 'counts' => []];
        }
        $counts = [
            'dangling bridges repointed' => $this->scalar('SELECT COUNT(*) FROM chatwoot_contact WHERE contact_id = ? AND deleted = FALSE', [$danglingId]),
            'dangling inbox links repointed' => $this->scalar('SELECT COUNT(*) FROM chatwoot_contact_inbox WHERE contact_id = ?', [$danglingId]),
        ];
        if (!$apply) {
            return ['status' => 'would repair', 'counts' => $counts];
        }
        $this->pdo->beginTransaction();
        try {
            $this->exec('UPDATE chatwoot_contact SET contact_id = ?, modified_at = ? WHERE contact_id = ? AND deleted = FALSE',
                [$survivorId, gmdate('Y-m-d H:i:s'), $danglingId]);
            $this->exec('UPDATE chatwoot_contact_inbox SET contact_id = ? WHERE contact_id = ?', [$survivorId, $danglingId]);
            $blocking = $this->blockingReferences($danglingId);
            if ($blocking !== null || $this->scalar('SELECT COUNT(*) FROM contact WHERE id = ?', [$danglingId]) !== 0) {
                $this->pdo->rollBack();
                return ['status' => 'refused', 'reason' => 'dangling id changed during repair', 'counts' => []];
            }
            $this->pdo->commit();
            return ['status' => 'repaired', 'counts' => $counts];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function validateDangling(string $survivorId, string $danglingId): ?string
    {
        if ($survivorId === $danglingId) return 'same contact';
        if ($this->scalar('SELECT COUNT(*) FROM contact WHERE id = ?', [$danglingId]) !== 0) return 'dangling contact exists';
        $survivor = $this->rows('SELECT tenant_id FROM contact WHERE id = ? AND deleted = FALSE', [$survivorId]);
        if (!$survivor || !$survivor[0]['tenant_id']) return 'survivor missing, deleted or without tenant';
        $tenant = $survivor[0]['tenant_id'];
        $dangling = $this->rows('SELECT cc.chatwoot_account_id, cc.phone_number, a.tenant_id FROM chatwoot_contact cc
            LEFT JOIN chatwoot_account a ON a.id = cc.chatwoot_account_id WHERE cc.contact_id = ? AND cc.deleted = FALSE', [$danglingId]);
        if (!$dangling) return 'no live bridge points at the dangling id';
        $own = $this->rows('SELECT chatwoot_account_id, phone_number FROM chatwoot_contact WHERE contact_id = ? AND deleted = FALSE', [$survivorId]);
        foreach ($dangling as $d) {
            if ($d['tenant_id'] !== $tenant) return 'dangling bridge account in another tenant';
            $match = false;
            foreach ($own as $o) {
                if ($o['chatwoot_account_id'] === $d['chatwoot_account_id'] && self::sameLine((string) $o['phone_number'], (string) $d['phone_number'])) $match = true;
            }
            if (!$match) return 'no shared Chatwoot account with the same phone line';
        }
        // Repair moves bridges only; anything else still owned by the id must refuse.
        foreach (['contact_channel_identity', 'account_contact'] as $table) {
            if ($this->scalar("SELECT COUNT(*) FROM $table WHERE contact_id = ? AND deleted = FALSE", [$danglingId]) > 0) {
                return "dangling id referenced by $table.contact_id";
            }
        }
        $blocking = $this->blockingReferences($danglingId);
        return $blocking === null ? null : "dangling id referenced by $blocking";
    }

    private function validate(string $survivorId, string $loserId): ?string
    {
        if ($survivorId === $loserId) return 'same contact';
        $contacts = $this->rows('SELECT id, tenant_id FROM contact WHERE id IN (?, ?) AND deleted = FALSE', [$survivorId, $loserId]);
        if (count($contacts) !== 2) return 'contact missing or deleted';
        $tenants = array_unique(array_column($contacts, 'tenant_id'));
        if (count($tenants) !== 1 || !$tenants[0]) return 'different or missing tenant';
        $bridges = $this->rows('SELECT contact_id, chatwoot_account_id, phone_number FROM chatwoot_contact
            WHERE contact_id IN (?, ?) AND deleted = FALSE', [$survivorId, $loserId]);
        $matched = false;
        foreach ($bridges as $s) {
            if ($s['contact_id'] !== $survivorId) continue;
            foreach ($bridges as $l) {
                if ($l['contact_id'] !== $loserId || $l['chatwoot_account_id'] !== $s['chatwoot_account_id']) continue;
                if (self::sameLine((string) $s['phone_number'], (string) $l['phone_number'])) $matched = true;
            }
        }
        if (!$matched) return 'no shared Chatwoot account with the same phone line';
        $blocking = $this->blockingReferences($loserId);
        return $blocking === null ? null : "loser referenced by $blocking";
    }

    /** Same number, or Brazilian mobile with and without the 9th digit. */
    public static function sameLine(string $a, string $b): bool
    {
        $a = preg_replace('/\D/', '', $a) ?? '';
        $b = preg_replace('/\D/', '', $b) ?? '';
        if (strlen($a) < 10 || strlen($b) < 10) return false;
        if ($a === $b) return true;
        [$long, $short] = strlen($a) > strlen($b) ? [$a, $b] : [$b, $a];
        return str_starts_with($long, '55') && strlen($long) === 13 && strlen($short) === 12
            && $long[4] === '9' && substr($long, 0, 4) . substr($long, 5) === $short;
    }

    private function blockingReferences(string $loserId): ?string
    {
        foreach ($this->references() as $ref) {
            if (in_array($ref['table'] . '.' . $ref['column'], self::HANDLED, true)) continue;
            if (str_starts_with($ref['table'], 'entity_') && $ref['column'] === 'entity_id') continue;
            $table = $this->quote($ref['table']);
            $column = $this->quote($ref['column']);
            $deleted = $ref['deleted'] ? ' AND ' . $this->quote('deleted') . ' = FALSE' : '';
            $where = $ref['parent']
                ? "$column = ? AND " . $this->quote(str_replace('_id', '_type', $ref['column'])) . " = 'Contact'"
                : "$column = ?";
            if ($this->scalar("SELECT COUNT(*) FROM $table WHERE $where$deleted", [$loserId]) > 0) {
                return $ref['table'] . '.' . $ref['column'];
            }
        }
        return null;
    }

    /** @return array<string, int> */
    private function move(string $survivorId, string $loserId, bool $write): array
    {
        $now = gmdate('Y-m-d H:i:s');
        // Reads decide every step; writes only happen when $write.
        $exec = fn (string $sql, array $args): int => $write ? $this->exec($sql, $args) : 1;
        $counts = [];
        $counts['chatwoot bridges'] = $this->scalar('SELECT COUNT(*) FROM chatwoot_contact WHERE contact_id = ? AND deleted = FALSE', [$loserId]);
        $counts['chatwoot inbox links'] = $this->scalar('SELECT COUNT(*) FROM chatwoot_contact_inbox WHERE contact_id = ?', [$loserId]);
        if ($write) {
            $this->exec('UPDATE chatwoot_contact SET contact_id = ?, modified_at = ? WHERE contact_id = ? AND deleted = FALSE', [$survivorId, $now, $loserId]);
            $this->exec('UPDATE chatwoot_contact_inbox SET contact_id = ? WHERE contact_id = ?', [$survivorId, $loserId]);
        }
        $counts['identities moved'] = 0;
        $counts['identities already on survivor'] = 0;
        foreach ($this->rows('SELECT id, tenant_id, channel_type, source_id FROM contact_channel_identity WHERE contact_id = ? AND deleted = FALSE', [$loserId]) as $i) {
            $twin = $this->scalar('SELECT COUNT(*) FROM contact_channel_identity WHERE contact_id = ? AND tenant_id = ? AND channel_type = ? AND source_id = ? AND deleted = FALSE',
                [$survivorId, $i['tenant_id'], $i['channel_type'], $i['source_id']]);
            if ($twin) {
                $exec('UPDATE contact_channel_identity SET deleted = TRUE, modified_at = ? WHERE id = ?', [$now, $i['id']]);
                $counts['identities already on survivor']++;
            } else {
                $exec('UPDATE contact_channel_identity SET contact_id = ?, modified_at = ? WHERE id = ?', [$survivorId, $now, $i['id']]);
                $counts['identities moved']++;
            }
        }
        foreach (['phone' => ['entity_phone_number', 'phone_number_id'], 'email' => ['entity_email_address', 'email_address_id']] as $kind => [$table, $key]) {
            $counts["$kind links moved"] = 0;
            foreach ($this->rows("SELECT id, $key AS target FROM $table WHERE entity_type = 'Contact' AND entity_id = ? AND deleted = FALSE", [$loserId]) as $link) {
                $has = $this->scalar("SELECT COUNT(*) FROM $table WHERE entity_type = 'Contact' AND entity_id = ? AND $key = ? AND deleted = FALSE", [$survivorId, $link['target']]);
                if ($has) {
                    $exec("UPDATE $table SET deleted = TRUE WHERE id = ?", [$link['id']]);
                } else {
                    $exec("UPDATE $table SET entity_id = ?, " . $this->quote('primary') . ' = FALSE WHERE id = ?', [$survivorId, $link['id']]);
                    $counts["$kind links moved"]++;
                }
            }
        }
        $counts['account links moved'] = 0;
        foreach ($this->rows('SELECT id, account_id FROM account_contact WHERE contact_id = ? AND deleted = FALSE', [$loserId]) as $link) {
            $has = $this->scalar('SELECT COUNT(*) FROM account_contact WHERE contact_id = ? AND account_id = ? AND deleted = FALSE', [$survivorId, $link['account_id']]);
            if ($has) {
                $exec('UPDATE account_contact SET deleted = TRUE WHERE id = ?', [$link['id']]);
            } else {
                $exec('UPDATE account_contact SET contact_id = ? WHERE id = ?', [$survivorId, $link['id']]);
                $counts['account links moved']++;
            }
        }
        $counts['contacts soft-deleted'] = $exec('UPDATE contact SET deleted = TRUE, modified_at = ? WHERE id = ? AND deleted = FALSE', [$now, $loserId]);
        return $counts;
    }

    /** @return list<array{table: string, column: string, parent: bool, deleted: bool}> */
    private function references(): array
    {
        if ($this->referenceCache !== null) return $this->referenceCache;
        if ($this->references) return $this->referenceCache = ($this->references)();
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $schema = $mysql ? 'DATABASE()' : 'current_schema()';
        $columns = [];
        foreach ($this->rows("SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = $schema", []) as $row) {
            $columns[strtolower((string) $row['t'])][strtolower((string) $row['c'])] = true;
        }
        $refs = [];
        foreach ($columns as $table => $cols) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/D', $table)) continue;
            foreach (array_keys($cols) as $column) {
                $parent = $column === 'parent_id' && isset($cols['parent_type']);
                if (!$parent && !str_ends_with($column, 'contact_id') && $column !== 'customer_id') continue;
                $refs[] = ['table' => $table, 'column' => $column, 'parent' => $parent, 'deleted' => isset($cols['deleted'])];
            }
        }
        return $this->referenceCache = $refs;
    }

    private function quote(string $identifier): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $identifier)) throw new \InvalidArgumentException('Invalid identifier.');
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "`$identifier`" : "\"$identifier\"";
    }

    private function rows(string $sql, array $args): array
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function scalar(string $sql, array $args): int
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return (int) $s->fetchColumn();
    }

    private function exec(string $sql, array $args): int
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s->rowCount();
    }
}
