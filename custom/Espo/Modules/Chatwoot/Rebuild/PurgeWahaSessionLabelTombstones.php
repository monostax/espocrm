<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Rebuild;

use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Espo\Core\Utils\Database\Helper;
use Espo\Core\Utils\Database\Schema\RebuildAction;
use Espo\Core\Utils\Log;
use Throwable;

/**
 * Pre-rebuild schema action (registered in app/databasePlatforms.json).
 *
 * Hard-deletes soft-deleted `waha_session_label` rows before the schema diff
 * is applied.
 *
 * Background: the `uniqueMembershipIntegration` unique index originally
 * included the boolean `deleted` column, so at most one tombstone could exist
 * per (membership, integration) pair and any subsequent soft-delete of a
 * recreated label collided with it ("Duplicate entry ... for key
 * 'UNIQ_UNIQUE_MEMBERSHIP_INTEGRATION'"). The index now uses `delete_id`
 * (see WahaSessionLabel entityDefs), but legacy tombstones predating the
 * `delete_id` column carry the default value '0' and would collide with
 * active rows when the new unique index is created. Purging tombstones
 * up-front makes the index migration safe and is idempotent.
 *
 * WahaSessionLabel tombstones carry no information worth restoring (they are
 * derived mappings that the SyncInboxMembersFromChatwoot job recreates on
 * demand), so hard deletion is safe.
 */
class PurgeWahaSessionLabelTombstones implements RebuildAction
{
    private const TABLE = 'waha_session_label';

    public function __construct(
        private Helper $helper,
        private Log $log
    ) {}

    public function process(DbalSchema $oldSchema, DbalSchema $newSchema): void
    {
        if (!$oldSchema->hasTable(self::TABLE)) {
            // Fresh install; nothing to purge.
            return;
        }

        $table = $oldSchema->getTable(self::TABLE);

        // Only legacy tombstones (delete_id missing or still '0') can collide
        // with the unique index on (membership, integration, delete_id).
        $sql = $table->hasColumn('delete_id')
            ? "DELETE FROM `" . self::TABLE . "` WHERE deleted = 1 AND delete_id = '0'"
            : "DELETE FROM `" . self::TABLE . "` WHERE deleted = 1";

        try {
            $count = $this->helper->getPDO()->exec($sql);
        } catch (Throwable $e) {
            $this->log->error(
                'PurgeWahaSessionLabelTombstones: failed to purge tombstones: ' . $e->getMessage()
            );

            return;
        }

        if ($count) {
            $this->log->info(
                "PurgeWahaSessionLabelTombstones: purged {$count} soft-deleted waha_session_label row(s)"
            );
        }
    }
}
