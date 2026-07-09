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
use Espo\Modules\Chatwoot\Tools\ContactFieldSync;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\ORM\EntityManager;

/**
 * One-time (but idempotent) backfill mirroring existing address-bearing
 * ContactChannelIdentity rows onto the parent Contact's native fields:
 *
 *   - whatsapp / sms -> phoneNumber
 *   - email          -> emailAddress
 *
 * The live path is Hooks\ContactChannelIdentity\SyncFieldsToContact,
 * which only fires on identity create / sourceId rotation / contact
 * re-assignment — identities created before that hook existed never
 * get re-saved, hence this rebuild pass.
 *
 * Tenant safety: only identities whose tenant_id matches the contact's
 * tenant_id are considered (enforced in the SQL). Runs as system, so
 * no per-record ACL applies — consistent with the other backfills.
 *
 * Additive only, deduped inside {@see ContactFieldSync} — repeated
 * rebuilds are no-ops.
 *
 * Runs AFTER {@see BackfillChannelIdentities} so freshly materialized
 * identity rows are included in the same rebuild.
 */
class BackfillContactFieldsFromIdentities implements RebuildAction
{
    /** Max identity rows to process per rebuild run. */
    private const ROW_LIMIT = 10000;

    public function __construct(
        private EntityManager $entityManager,
        private ContactFieldSync $contactFieldSync,
        private Log $log,
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();

        if (!$this->tableExists($pdo, 'contact_channel_identity')) {
            $this->log->info(
                'BackfillContactFieldsFromIdentities: contact_channel_identity table missing; skipping'
            );

            return;
        }

        $channels = "'" . implode("','", array_merge(
            ContactFieldSync::PHONE_CHANNELS,
            ContactFieldSync::EMAIL_CHANNELS,
        )) . "'";

        $sql = "
            SELECT cci.contact_id, cci.channel_type, cci.source_id
            FROM contact_channel_identity cci
            INNER JOIN contact c
                ON c.id = cci.contact_id AND c.deleted = false
            WHERE cci.deleted = false
              AND cci.channel_type IN ({$channels})
              AND cci.contact_id IS NOT NULL
              AND cci.tenant_id IS NOT NULL
              AND cci.tenant_id <> ''
              AND c.tenant_id = cci.tenant_id
            LIMIT " . self::ROW_LIMIT . "
        ";

        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Group by contact so each Contact is loaded once, not once
        // per identity row. Values are deduped per contact up front.
        $phonesByContact = [];
        $emailsByContact = [];

        foreach ($rows as $row) {
            $contactId = (string) $row['contact_id'];
            $channelType = (string) $row['channel_type'];
            $sourceId = (string) $row['source_id'];

            if (in_array($channelType, ContactFieldSync::PHONE_CHANNELS, true)) {
                $e164 = PhoneNormalizer::normalize($sourceId);

                if ($e164) {
                    // Null result: LID-keyed or otherwise non-routable.
                    $phonesByContact[$contactId][$e164] = true;
                }

                continue;
            }

            $email = strtolower(trim($sourceId));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emailsByContact[$contactId][$email] = true;
            }
        }

        $contactIds = array_values(array_unique(array_merge(
            array_keys($phonesByContact),
            array_keys($emailsByContact),
        )));

        $contacts = 0;
        $phonesAdded = 0;
        $emailsAdded = 0;

        foreach ($contactIds as $contactId) {
            $contact = $this->entityManager->getEntityById('Contact', $contactId);

            if (!$contact) {
                continue;
            }

            $contacts++;

            foreach (array_keys($phonesByContact[$contactId] ?? []) as $e164) {
                if ($this->contactFieldSync->appendPhone($contact, $e164)) {
                    $phonesAdded++;
                }
            }

            foreach (array_keys($emailsByContact[$contactId] ?? []) as $email) {
                if ($this->contactFieldSync->appendEmail($contact, $email)) {
                    $emailsAdded++;
                }
            }
        }

        $this->log->warning(
            "BackfillContactFieldsFromIdentities: scanned " . count($rows)
            . " identity row(s) across {$contacts} contact(s), added "
            . "{$phonesAdded} phone number(s), {$emailsAdded} email address(es)"
        );
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
}
