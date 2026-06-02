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
use Espo\Modules\Chatwoot\Tools\ContactReconciler;
use Espo\ORM\EntityManager;

/**
 * Backfills the new `contact_channel_identity` table and links
 * previously orphaned `chatwoot_contact` rows to a CRM `Contact`.
 *
 * Two passes, run sequentially and idempotent across repeated rebuilds:
 *
 *   Pass 1 — Materialize identities for ALREADY-LINKED rows.
 *     For each `chatwoot_contact` with a non-null `contact_id` whose
 *     parent `chatwoot_account.tenant_id` is set, walk
 *     `chatwoot_contact_inbox` and synthesize one
 *     `contact_channel_identity` row per (channelType, sourceId).
 *     Also synthesize whatsapp/email identities from `contact.phone_number`
 *     and `contact.email_address` so future raw-phone matches collide.
 *
 *   Pass 2 — Reconcile UNLINKED `chatwoot_contact` rows.
 *     For each `chatwoot_contact` where `contact_id IS NULL` and the
 *     parent account has a tenant, run the {@see ContactReconciler}
 *     against the row's payload (name, phone, email, identifier, and
 *     its `chatwoot_contact_inbox` children). When the reconciler
 *     returns a Contact, re-link the bridge row.
 *
 * Both passes are bounded by row limit per run to keep rebuild fast;
 * subsequent rebuilds (or the next contact-sync cron tick) finish the
 * job. The cap is generous (10k rows/pass) because the 128 unlinked
 * contacts seen in production fit comfortably.
 *
 * Runs AFTER {@see BackfillChatwootTenant} so tenant ids are populated.
 */
class BackfillChannelIdentities implements RebuildAction
{
    /** Max chatwoot_contact rows to process per pass per rebuild. */
    private const PASS_LIMIT = 10000;

    /**
     * `chatwoot_contact.email` is an Espo `email`-type field, so it is
     * NOT a column on the table — the value lives in the EmailAddress
     * relation (`entity_email_address` -> `email_address`). Selecting
     * `cc.email` directly throws SQLSTATE[42S22] and aborts the whole
     * rebuild. This correlated subquery resolves the primary email
     * address and aliases it as `email` so downstream code that reads
     * `$row['email']` is unchanged. `primary` is a reserved word and
     * must stay backticked.
     */
    private const EMAIL_SUBQUERY = "(
                SELECT ea.name
                FROM entity_email_address eea
                INNER JOIN email_address ea
                    ON ea.id = eea.email_address_id AND ea.deleted = 0
                WHERE eea.entity_id = cc.id
                  AND eea.entity_type = 'ChatwootContact'
                  AND eea.deleted = 0
                ORDER BY eea.`primary` DESC, ea.id ASC
                LIMIT 1
            ) AS email";

    public function __construct(
        private EntityManager $entityManager,
        private ContactReconciler $reconciler,
        private Log $log,
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();

        if (!$this->tableExists($pdo, 'contact_channel_identity')) {
            // Table not yet created by the rebuild's earlier schema
            // pass. Espo's RebuildAction runs after schema sync, but
            // a fresh install with no contact_channel_identity yet
            // (e.g. extension just installed) should not crash.
            $this->log->info('BackfillChannelIdentities: contact_channel_identity table missing; skipping');
            return;
        }

        if (!$this->columnExists($pdo, 'chatwoot_account', 'tenant_id')) {
            $this->log->info('BackfillChannelIdentities: chatwoot_account.tenant_id missing; run BackfillChatwootTenant first');
            return;
        }

        $this->materializeIdentitiesForLinked();
        $this->reconcileUnlinked();
    }

    /**
     * Pass 1 — Synthesize ContactChannelIdentity rows for chatwoot
     * contacts that are ALREADY linked to a Contact. This is what
     * makes the new fast path (match by identity) work for the bulk
     * of existing data.
     */
    private function materializeIdentitiesForLinked(): void
    {
        $rows = $this->fetchLinkedRows();
        $processed = 0;
        $created = 0;

        foreach ($rows as $row) {
            $contactId = (string) $row['contact_id'];
            $tenantId = (string) $row['tenant_id'];
            $chatwootAccountId = (string) $row['chatwoot_account_id'];

            // Synthesize from chatwoot_contact_inbox children. The
            // inbox display name (used as `label`) lives on the
            // chatwoot_inbox table, not the bridge — we LEFT JOIN to
            // pull it so the label survives even when the inbox row
            // hasn't been backfilled yet.
            $inboxRows = $this->fetchContactInboxes((string) $row['id']);
            foreach ($inboxRows as $cib) {
                $channel = $this->reconciler->mapChannelType($cib['inbox_channel_type'] ?? null);
                $sourceId = $this->normalizeSourceForChannel(
                    $channel,
                    $cib['source_id'] ?? null
                );
                if (!$channel || !$sourceId) {
                    continue;
                }
                $this->reconciler->upsertIdentity(
                    contactId: $contactId,
                    tenantId: $tenantId,
                    channelType: $channel,
                    sourceId: $sourceId,
                    label: $cib['inbox_name'] ?? null,
                    chatwootAccountId: $chatwootAccountId,
                    chatwootInboxId: $cib['inbox_id'] ?: null,
                );
                $created++;
            }

            // Synthesize whatsapp/email identities from the
            // chatwoot_contact bridge row itself — the bridge stores
            // the Chatwoot-side phone/email which is the same data
            // the AI agent will see, and it's authoritative even when
            // the inbox payload is absent (e.g. WhatsApp contact
            // imported with no contact_inboxes row yet).
            $bridgePhone = \Espo\Modules\Chatwoot\Tools\PhoneNormalizer::normalize(
                $row['phone_number'] ?? null
            );
            if ($bridgePhone) {
                $this->reconciler->upsertIdentity(
                    contactId: $contactId,
                    tenantId: $tenantId,
                    channelType: 'whatsapp',
                    sourceId: $bridgePhone,
                    label: null,
                    chatwootAccountId: $chatwootAccountId,
                    chatwootInboxId: null,
                );
                $created++;
            }
            $bridgeEmail = isset($row['email']) && is_string($row['email']) ? trim($row['email']) : '';
            if ($bridgeEmail !== '') {
                $this->reconciler->upsertIdentity(
                    contactId: $contactId,
                    tenantId: $tenantId,
                    channelType: 'email',
                    sourceId: strtolower($bridgeEmail),
                    label: null,
                    chatwootAccountId: $chatwootAccountId,
                    chatwootInboxId: null,
                );
                $created++;
            }

            $processed++;
        }

        $this->log->warning(
            "BackfillChannelIdentities: pass 1 (linked) — "
            . "processed {$processed} chatwoot_contact row(s), upserted/touched {$created} identity row(s)"
        );
    }

    /**
     * Pass 2 — Reconcile chatwoot_contact rows that have contact_id =
     * NULL (the orphans). This is what unblocks Ana Paula and the
     * other 127 Instagram-only contacts on the production data.
     */
    private function reconcileUnlinked(): void
    {
        $rows = $this->fetchUnlinkedRows();
        $matched = 0;
        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $chatwootContactEntityId = (string) $row['id'];
            $tenantId = (string) $row['tenant_id'];
            $chatwootAccountId = (string) $row['chatwoot_account_id'];

            $contactInboxes = $this->buildContactInboxesPayload(
                $this->fetchContactInboxes($chatwootContactEntityId)
            );

            $reconciled = $this->reconciler->reconcile([
                'tenantId' => $tenantId,
                'teamsIds' => $this->fetchAccountTeamIds($chatwootAccountId),
                'chatwootAccountId' => $chatwootAccountId,
                'name' => $row['name'] ?? null,
                'phoneNumber' => $row['phone_number'] ?? null,
                'email' => $row['email'] ?? null,
                'identifier' => $row['identifier'] ?? null,
                'contactInboxes' => $contactInboxes,
                'inboxIdMap' => $this->buildInboxIdMap($contactInboxes, $chatwootAccountId),
            ]);

            $contact = $reconciled['contact'];
            if (!$contact) {
                $skipped++;
                continue;
            }

            if ($reconciled['isNew']) {
                $created++;
            } else {
                $matched++;
            }

            // Link the bridge row + denormalize contactId onto the
            // child inbox rows (the AI agent reads chatwoot_contact
            // _inbox.contact_id directly in some paths).
            $this->linkBridge($chatwootContactEntityId, $contact->getId());
        }

        $this->log->warning(
            "BackfillChannelIdentities: pass 2 (unlinked) — "
            . "matched {$matched}, created {$created}, skipped (insufficient data) {$skipped}"
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLinkedRows(): array
    {
        $sql = "
            SELECT
                cc.id,
                cc.contact_id,
                cc.chatwoot_account_id,
                cc.phone_number,
                " . self::EMAIL_SUBQUERY . ",
                cc.identifier,
                cc.name,
                a.tenant_id
            FROM chatwoot_contact cc
            INNER JOIN chatwoot_account a ON a.id = cc.chatwoot_account_id AND a.deleted = 0
            WHERE cc.deleted = 0
              AND cc.contact_id IS NOT NULL
              AND a.tenant_id IS NOT NULL
              AND a.tenant_id <> ''
            LIMIT " . self::PASS_LIMIT . "
        ";
        return $this->entityManager->getPDO()->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchUnlinkedRows(): array
    {
        $sql = "
            SELECT
                cc.id,
                cc.chatwoot_account_id,
                cc.phone_number,
                " . self::EMAIL_SUBQUERY . ",
                cc.identifier,
                cc.name,
                a.tenant_id
            FROM chatwoot_contact cc
            INNER JOIN chatwoot_account a ON a.id = cc.chatwoot_account_id AND a.deleted = 0
            WHERE cc.deleted = 0
              AND cc.contact_id IS NULL
              AND a.tenant_id IS NOT NULL
              AND a.tenant_id <> ''
            LIMIT " . self::PASS_LIMIT . "
        ";
        return $this->entityManager->getPDO()->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchContactInboxes(string $chatwootContactEntityId): array
    {
        // `chatwoot_contact_inbox` does NOT carry a human-readable
        // inbox name — that lives on `chatwoot_inbox.name`. LEFT
        // JOIN so a missing chatwoot_inbox row (rare but possible
        // during partial backfills) just nulls the label rather
        // than dropping the bridge row.
        $sql = "
            SELECT cib.inbox_id,
                   cib.chatwoot_inbox_id,
                   cib.inbox_channel_type,
                   ci.name AS inbox_name,
                   cib.source_id
            FROM chatwoot_contact_inbox cib
            LEFT JOIN chatwoot_inbox ci ON ci.id = cib.inbox_id AND ci.deleted = 0
            WHERE cib.chatwoot_contact_id = :id AND cib.deleted = 0
        ";
        $stmt = $this->entityManager->getPDO()->prepare($sql);
        $stmt->execute([':id' => $chatwootContactEntityId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<string>
     */
    private function fetchAccountTeamIds(string $chatwootAccountId): array
    {
        $sql = "
            SELECT et.team_id
            FROM entity_team et
            WHERE et.entity_id = :id
              AND et.entity_type = 'ChatwootAccount'
              AND et.deleted = 0
        ";
        $stmt = $this->entityManager->getPDO()->prepare($sql);
        $stmt->execute([':id' => $chatwootAccountId]);
        return array_values(array_map(
            static fn($r) => (string) $r['team_id'],
            $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        ));
    }

    /**
     * Reshape internal contact_inbox rows into the same array shape the
     * reconciler expects (mirroring Chatwoot's API payload).
     *
     * @param list<array{inbox_id:?string, chatwoot_inbox_id:?int, inbox_channel_type:?string, source_id:?string}> $rows
     * @return list<array{source_id:?string, inbox:array{id:?int, channel_type:?string, name:?string}}>
     */
    private function buildContactInboxesPayload(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'source_id' => $r['source_id'] ?? null,
                'inbox' => [
                    'id' => isset($r['chatwoot_inbox_id']) ? (int) $r['chatwoot_inbox_id'] : null,
                    'channel_type' => $r['inbox_channel_type'] ?? null,
                    'name' => $r['inbox_name'] ?? null,
                ],
            ];
        }
        return $out;
    }

    /**
     * @param list<array{inbox:array{id:?int}}> $contactInboxes
     * @return array<int, string>
     */
    private function buildInboxIdMap(array $contactInboxes, string $chatwootAccountId): array
    {
        $rawIds = [];
        foreach ($contactInboxes as $ci) {
            $id = $ci['inbox']['id'] ?? null;
            if (is_int($id)) {
                $rawIds[] = $id;
            }
        }
        if ($rawIds === []) {
            return [];
        }
        $rawIds = array_values(array_unique($rawIds));
        $placeholders = implode(',', array_fill(0, count($rawIds), '?'));

        $sql = "
            SELECT id, chatwoot_inbox_id
            FROM chatwoot_inbox
            WHERE chatwoot_inbox_id IN ({$placeholders})
              AND chatwoot_account_id = ?
              AND deleted = 0
        ";
        $stmt = $this->entityManager->getPDO()->prepare($sql);
        $params = $rawIds;
        $params[] = $chatwootAccountId;
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['chatwoot_inbox_id']] = (string) $row['id'];
        }
        return $map;
    }

    /**
     * Set chatwoot_contact.contact_id and cascade onto
     * chatwoot_contact_inbox.contact_id (denormalized field that some
     * read paths rely on). UPDATE-by-id is the cheapest way to do
     * this without re-running Espo hooks.
     */
    private function linkBridge(string $chatwootContactEntityId, string $contactId): void
    {
        $pdo = $this->entityManager->getPDO();
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            "UPDATE chatwoot_contact SET contact_id = :cid, modified_at = :now WHERE id = :id AND deleted = 0"
        );
        $stmt->execute([':cid' => $contactId, ':now' => $now, ':id' => $chatwootContactEntityId]);

        $stmt = $pdo->prepare(
            "UPDATE chatwoot_contact_inbox SET contact_id = :cid "
            . "WHERE chatwoot_contact_id = :id AND deleted = 0"
        );
        $stmt->execute([':cid' => $contactId, ':id' => $chatwootContactEntityId]);

        // The chatwoot_conversation rows also denormalize contact_id;
        // backfill them too so the AI agent's reads see consistent data.
        $stmt = $pdo->prepare(
            "UPDATE chatwoot_conversation SET contact_id = :cid "
            . "WHERE chatwoot_contact_id = :id AND deleted = 0 AND contact_id IS NULL"
        );
        $stmt->execute([':cid' => $contactId, ':id' => $chatwootContactEntityId]);
    }

    /**
     * Apply the same channel-specific normalization the reconciler
     * uses so identities round-trip cleanly between the two code
     * paths.
     */
    private function normalizeSourceForChannel(?string $channel, ?string $sourceId): ?string
    {
        if (!$channel || !$sourceId) {
            return null;
        }
        $sourceId = trim($sourceId);
        if ($sourceId === '') {
            return null;
        }
        if ($channel === 'whatsapp' || $channel === 'sms') {
            $norm = \Espo\Modules\Chatwoot\Tools\PhoneNormalizer::normalize($sourceId);
            return $norm ?: $sourceId;
        }
        if ($channel === 'email') {
            return strtolower($sourceId);
        }
        return $sourceId;
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        try {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        try {
            $pdo->query("SELECT {$column} FROM {$table} LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
