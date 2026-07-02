<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Re-merges duplicate `Contact` rows created by Chatwoot source_id
 * rotation.
 *
 * Chatwoot rotates `contact_inboxes[].source_id` on some channels
 * (notably `Channel::Api` WhatsApp groups, whose stable group JID can
 * flip to an internal UUID between syncs). The old ContactReconciler
 * keyed dedup on that volatile source_id, so a rotated value missed
 * every match strategy and auto-provisioned a SECOND Contact —
 * orphaning the original and re-linking the `chatwoot_contact` bridge
 * (plus its `chatwoot_contact_inbox` / `chatwoot_conversation` rows) to
 * the dup.
 *
 * The forward fix (passing the bridge's existing contactId into the
 * reconciler) stops new duplicates. This pass repairs the rows already
 * damaged before that fix shipped.
 *
 * Detection signature (per tenant, never global):
 *   A live `chatwoot_contact` bridge B whose stable `identifier` is also
 *   registered as a `contact_channel_identity` row I, where
 *   B.contact_id != I.contact_id. I.contact_id is the ORIGINAL owner
 *   (it still holds the durable identifier identity); B.contact_id is
 *   the duplicate (it only carries the rotated source_id identity).
 *
 * Repair, per affected bridge:
 *   1. Re-point the bridge + its denormalized inbox/conversation rows
 *      back to the original contact.
 *   2. Move the duplicate's `contact_channel_identity` rows onto the
 *      original (so the original now also owns the rotated source_id and
 *      future rotations match instantly). Collision-safe: if the
 *      original already owns an identical (tenant, channel, source)
 *      identity, the duplicate's row is soft-deleted instead.
 *   3. Soft-delete the now-empty duplicate Contact — but ONLY when it
 *      has no other bridges and no business records (opportunities,
 *      cases, meetings, calls, tasks). Anything richer is left in place
 *      and logged for manual review rather than risking data loss.
 *
 * Idempotent: once a bridge points at the original, the detection join
 * no longer matches it, so repeated rebuilds are no-ops.
 *
 * Runs AFTER {@see BackfillChannelIdentities} so identities exist.
 */
class MergeRotatedDuplicateContacts implements RebuildAction
{
    /** Max affected bridges to repair per rebuild run. */
    private const LIMIT = 5000;

    /**
     * Entity tables that denormalize a `contact_id` FK and would make a
     * duplicate Contact unsafe to delete. We refuse to delete a dup
     * that owns any of these.
     */
    private const BUSINESS_TABLES = [
        'opportunity',
        'case',
        'meeting',
        'call',
        'task',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();

        if (
            !$this->tableExists($pdo, 'contact_channel_identity')
            || !$this->tableExists($pdo, 'chatwoot_contact')
        ) {
            $this->log->info('MergeRotatedDuplicateContacts: required tables missing; skipping');
            return;
        }

        $pairs = $this->findAffectedPairs($pdo);

        if ($pairs === []) {
            $this->log->debug('MergeRotatedDuplicateContacts: no rotated duplicates found');
            return;
        }

        $repaired = 0;
        $deletedDups = 0;
        $skippedDups = 0;

        foreach ($pairs as $pair) {
            $bridgeId = $pair['bridge_id'];
            $dupContactId = $pair['dup_contact'];
            $origContactId = $pair['orig_contact'];

            // Guard: never act on a self-referential or malformed pair.
            if (!$dupContactId || !$origContactId || $dupContactId === $origContactId) {
                continue;
            }

            try {
                $pdo->beginTransaction();

                $this->relinkBridge($pdo, $bridgeId, $origContactId);
                $this->moveDuplicateIdentities($pdo, $dupContactId, $origContactId);

                $deleted = false;
                if ($this->isDuplicateSafeToDelete($pdo, $dupContactId)) {
                    $this->softDeleteContact($pdo, $dupContactId);
                    $deleted = true;
                }

                $pdo->commit();

                $repaired++;
                if ($deleted) {
                    $deletedDups++;
                } else {
                    $skippedDups++;
                    $this->log->warning(
                        "MergeRotatedDuplicateContacts: re-linked bridge {$bridgeId} to "
                        . "{$origContactId} but left duplicate Contact {$dupContactId} in place "
                        . '(it owns other bridges or business records; review manually)'
                    );
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->log->error(
                    "MergeRotatedDuplicateContacts: failed repairing bridge {$bridgeId}: "
                    . $e->getMessage()
                );
            }

            if ($repaired >= self::LIMIT) {
                break;
            }
        }

        $this->log->info(
            "MergeRotatedDuplicateContacts: repaired {$repaired} bridge(s); "
            . "soft-deleted {$deletedDups} duplicate Contact(s); "
            . "left {$skippedDups} for manual review"
        );
    }

    /**
     * @return list<array{bridge_id:string, dup_contact:string, orig_contact:string}>
     */
    private function findAffectedPairs(\PDO $pdo): array
    {
        // Scope the identity match to the duplicate contact's tenant so
        // we never collapse contacts across tenants. The identifier is
        // the stable key; the bridge's current contact_id is the dup.
        $limit = self::LIMIT;
        $sql = "
            SELECT
                cct.id            AS bridge_id,
                cct.contact_id    AS dup_contact,
                ci.contact_id     AS orig_contact
            FROM chatwoot_contact cct
            INNER JOIN contact dup ON dup.id = cct.contact_id AND dup.deleted = false
            INNER JOIN contact_channel_identity ci
                ON ci.source_id = cct.identifier
               AND ci.tenant_id = dup.tenant_id
               AND ci.deleted = false
            INNER JOIN contact orig ON orig.id = ci.contact_id AND orig.deleted = false
            WHERE cct.deleted = false
              AND cct.contact_id IS NOT NULL
              AND cct.identifier IS NOT NULL
              AND cct.contact_id <> ci.contact_id
            GROUP BY cct.id, cct.contact_id, ci.contact_id
            LIMIT {$limit}
        ";

        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $r): array => [
            'bridge_id' => (string) $r['bridge_id'],
            'dup_contact' => (string) $r['dup_contact'],
            'orig_contact' => (string) $r['orig_contact'],
        ], $rows);
    }

    /**
     * Re-point the bridge and its denormalized children back onto the
     * original Contact. Unlike the orphan backfill we override the
     * conversation contact_id unconditionally because it currently
     * holds the WRONG (duplicate) id, not NULL.
     */
    private function relinkBridge(\PDO $pdo, string $bridgeId, string $origContactId): void
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'UPDATE chatwoot_contact SET contact_id = :cid, modified_at = :now '
            . 'WHERE id = :id AND deleted = false'
        );
        $stmt->execute([':cid' => $origContactId, ':now' => $now, ':id' => $bridgeId]);

        $stmt = $pdo->prepare(
            'UPDATE chatwoot_contact_inbox SET contact_id = :cid '
            . 'WHERE chatwoot_contact_id = :id AND deleted = false'
        );
        $stmt->execute([':cid' => $origContactId, ':id' => $bridgeId]);

        $stmt = $pdo->prepare(
            'UPDATE chatwoot_conversation SET contact_id = :cid '
            . 'WHERE chatwoot_contact_id = :id AND deleted = false'
        );
        $stmt->execute([':cid' => $origContactId, ':id' => $bridgeId]);
    }

    /**
     * Move the duplicate's channel identities onto the original. If the
     * original already owns the same (tenant, channelType, sourceId),
     * soft-delete the duplicate's row to avoid violating the unique
     * index; otherwise re-point it.
     */
    private function moveDuplicateIdentities(\PDO $pdo, string $dupContactId, string $origContactId): void
    {
        $select = $pdo->prepare(
            'SELECT id, tenant_id, channel_type, source_id '
            . 'FROM contact_channel_identity WHERE contact_id = :cid AND deleted = false'
        );
        $select->execute([':cid' => $dupContactId]);
        $identities = $select->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $collisionCheck = $pdo->prepare(
            'SELECT id FROM contact_channel_identity '
            . 'WHERE contact_id = :orig AND tenant_id = :tenant '
            . 'AND channel_type = :channel AND source_id = :source '
            . 'AND deleted = false LIMIT 1'
        );
        $repoint = $pdo->prepare(
            'UPDATE contact_channel_identity SET contact_id = :orig, modified_at = :now '
            . 'WHERE id = :id'
        );
        $softDelete = $pdo->prepare(
            'UPDATE contact_channel_identity SET deleted = true, modified_at = :now WHERE id = :id'
        );
        $now = date('Y-m-d H:i:s');

        foreach ($identities as $idn) {
            $collisionCheck->execute([
                ':orig' => $origContactId,
                ':tenant' => $idn['tenant_id'],
                ':channel' => $idn['channel_type'],
                ':source' => $idn['source_id'],
            ]);

            if ($collisionCheck->fetchColumn() !== false) {
                $softDelete->execute([':now' => $now, ':id' => $idn['id']]);
                continue;
            }

            $repoint->execute([':orig' => $origContactId, ':now' => $now, ':id' => $idn['id']]);
        }
    }

    /**
     * A duplicate is safe to delete only when it carries no other live
     * bridge and no business records on any contact_id-bearing table.
     */
    private function isDuplicateSafeToDelete(\PDO $pdo, string $dupContactId): bool
    {
        $bridgeCount = $pdo->prepare(
            'SELECT COUNT(*) FROM chatwoot_contact WHERE contact_id = :cid AND deleted = false'
        );
        $bridgeCount->execute([':cid' => $dupContactId]);
        if ((int) $bridgeCount->fetchColumn() > 0) {
            return false;
        }

        foreach (self::BUSINESS_TABLES as $table) {
            if (!$this->tableExists($pdo, $table) || !$this->columnExists($pdo, $table, 'contact_id')) {
                continue;
            }
            // `case` is a reserved word; quote every table name with the
            // driver's identifier quote character.
            $quoted = $this->quoteIdentifier($pdo, $table);
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM {$quoted} WHERE contact_id = :cid AND deleted = false"
            );
            $stmt->execute([':cid' => $dupContactId]);
            if ((int) $stmt->fetchColumn() > 0) {
                return false;
            }
        }

        return true;
    }

    private function softDeleteContact(\PDO $pdo, string $contactId): void
    {
        $stmt = $pdo->prepare(
            'UPDATE contact SET deleted = true, modified_at = :now WHERE id = :id'
        );
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $contactId]);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        try {
            $quoted = $this->quoteIdentifier($pdo, $table);
            $pdo->query("SELECT 1 FROM {$quoted} LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        try {
            $quotedTable = $this->quoteIdentifier($pdo, $table);
            $quotedColumn = $this->quoteIdentifier($pdo, $column);
            $pdo->query("SELECT {$quotedColumn} FROM {$quotedTable} LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Quote an identifier with the driver-appropriate quote character:
     * double quotes on PostgreSQL, backticks on MySQL/MariaDB.
     */
    private function quoteIdentifier(\PDO $pdo, string $identifier): string
    {
        $isPg = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql';

        return $isPg ? "\"{$identifier}\"" : "`{$identifier}`";
    }
}
