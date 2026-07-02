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

use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\Utils\Log;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * One-shot remediation for the agent-avatar sync feedback loop.
 *
 * ## Background
 *
 * Before the fix in {@see \Espo\Modules\Chatwoot\Services\AgentAvatarSyncService},
 * the CW→CRM pull mirrored Chatwoot's *resized* `thumbnail`
 * (`resize_to_fill: [250, nil]`) instead of the original `avatar_url` blob.
 * Because the thumbnail is a fresh JPEG re-encode, its bytes never matched
 * what the CRM had pushed, so the byte-hash loop guard never tripped. Each
 * round-trip re-encoded the image once more (generation loss), and after
 * thousands of cycles the avatars degraded into grayscale noise.
 *
 * Forensics found three users caught in loops of 4,618 / 2,829 / 1,688
 * iterations, totalling ~9,100 orphaned `cw-avatar-*` Attachment rows. The
 * original `avatar.jpg` source files have already been garbage-collected, so
 * the only safe recovery is: clear the corrupted current avatar (forcing a
 * fresh re-upload), reset the sync-hash bookkeeping, and purge the orphaned
 * attachment rows + any lingering files.
 *
 * ## What this does (idempotent)
 *
 *   1. For every User whose current `avatarId` points at a mirrored
 *      (`cw-avatar-*`) attachment: null out `avatarId` (silent + skip avatar
 *      sync hook) so the corrupted image stops rendering and the user can
 *      re-upload a clean one.
 *   2. Reset `crmAvatarSyncHash` / `chatwootAvatarSyncHash` to the empty-state
 *      sentinel on the linked ChatwootUser rows, so the next clean upload
 *      pushes correctly.
 *   3. Hard-delete every `cw-avatar-*` Attachment row on the User.avatar field
 *      (deleted or not), removing the backing file from storage first.
 *
 * ## Safety
 *
 *   - DRY_RUN constant lets you preview counts without mutating anything.
 *   - Only touches attachments named `cw-avatar-%` on `field = 'avatar'`,
 *     `related_type = 'User'` — never user-uploaded `avatar.jpg` rows.
 *   - Re-runnable: once avatars are cleared and rows purged, subsequent runs
 *     are no-ops.
 *
 * ## How to run
 *
 *   php -r 'require "bootstrap.php";
 *     $app = new \Espo\Core\Application();
 *     $app->setupSystemUser();
 *     (new \Espo\Modules\Chatwoot\Rebuild\CleanupAgentAvatarSyncLoop(
 *         $app->getContainer()->get("entityManager"),
 *         $app->getContainer()->get("fileStorageManager"),
 *         $app->getContainer()->get("log")
 *     ))->process();'
 *
 * NOTE: deliberately NOT registered in Resources/metadata/app/rebuild.json —
 * this is a destructive one-shot, not something to re-run on every rebuild.
 */
class CleanupAgentAvatarSyncLoop
{
    /** Flip to true to preview affected counts without mutating anything. */
    private const DRY_RUN = false;

    /** Mirrored-avatar filename prefix written by AgentAvatarSyncService. */
    private const MIRRORED_PREFIX = 'cw-avatar-';

    public function __construct(
        private EntityManager $entityManager,
        private FileStorageManager $fileStorageManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();

        $emptyHash = hash('sha256', '');

        // ---- Step 1 & 2: clear corrupted current avatars + reset hashes ----

        $affectedUserIds = $this->findUsersWithMirroredAvatar($pdo);

        $this->log->info(
            'CleanupAgentAvatarSyncLoop: ' . count($affectedUserIds) .
            ' user(s) currently showing a mirrored (corrupted) avatar' .
            (self::DRY_RUN ? ' [DRY_RUN]' : '')
        );

        foreach ($affectedUserIds as $userId) {
            if (!self::DRY_RUN) {
                $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

                if ($user && $user->get('avatarId')) {
                    $user->set('avatarId', null);
                    // Silent + skip the sync hook: we don't want this clear to
                    // propagate a delete to Chatwoot (Chatwoot still holds the
                    // image; the user will re-upload a clean one, which will
                    // then push normally).
                    $this->entityManager->saveEntity($user, [
                        'silent' => true,
                        'skipHooks' => true,
                        'skipChatwootAvatarSync' => true,
                    ]);
                }
            }

            // Reset sync-hash bookkeeping on the linked ChatwootUser(s).
            $chatwootUsers = $this->entityManager
                ->getRDBRepository('ChatwootUser')
                ->where(['assignedUserId' => $userId])
                ->find();

            foreach ($chatwootUsers as $chatwootUser) {
                if (self::DRY_RUN) {
                    continue;
                }

                $chatwootUser->set('crmAvatarSyncHash', $emptyHash);
                $chatwootUser->set('chatwootAvatarSyncHash', $emptyHash);
                $this->entityManager->saveEntity($chatwootUser, [
                    'silent' => true,
                    'skipHooks' => true,
                ]);
            }
        }

        // ---- Step 3: purge orphaned mirrored attachment rows + files ----

        $this->purgeMirroredAttachments($pdo);

        $this->log->info('CleanupAgentAvatarSyncLoop: done');
    }

    /**
     * User IDs whose current `avatarId` resolves to a `cw-avatar-*` attachment.
     *
     * @return string[]
     */
    private function findUsersWithMirroredAvatar(\PDO $pdo): array
    {
        // `user` is a reserved word on PostgreSQL (and quoting is
        // harmless on MySQL), so quote it with the driver's identifier
        // quote character.
        $q = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql' ? '"' : '`';

        $sql = "
            SELECT u.id
            FROM {$q}user{$q} u
            JOIN attachment a ON a.id = u.avatar_id
            WHERE a.name LIKE :prefix
              AND a.field = 'avatar'
              AND a.related_type = 'User'
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':prefix' => self::MIRRORED_PREFIX . '%']);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) ?: []);
    }

    /**
     * Hard-delete every mirrored avatar attachment (deleted or not), removing
     * the backing file from storage first. Runs in batches so a multi-thousand
     * row purge doesn't balloon memory.
     */
    private function purgeMirroredAttachments(\PDO $pdo): void
    {
        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM attachment
            WHERE name LIKE :prefix
              AND field = 'avatar'
              AND related_type = 'User'
        ");
        $countStmt->execute([':prefix' => self::MIRRORED_PREFIX . '%']);
        $total = (int) $countStmt->fetchColumn();

        $this->log->info(
            'CleanupAgentAvatarSyncLoop: ' . $total .
            ' mirrored avatar attachment row(s) to purge' .
            (self::DRY_RUN ? ' [DRY_RUN]' : '')
        );

        if (self::DRY_RUN || $total === 0) {
            return;
        }

        $idStmt = $pdo->prepare("
            SELECT id
            FROM attachment
            WHERE name LIKE :prefix
              AND field = 'avatar'
              AND related_type = 'User'
        ");
        $idStmt->execute([':prefix' => self::MIRRORED_PREFIX . '%']);
        $ids = $idStmt->fetchAll(\PDO::FETCH_COLUMN, 0) ?: [];

        $repository = $this->entityManager->getRDBRepository(Attachment::ENTITY_TYPE);

        $removedFiles = 0;
        $removedRows = 0;

        foreach ($ids as $id) {
            // Best-effort file removal: load the row (incl. soft-deleted) so
            // FileStorageManager can resolve the storage path. Many files were
            // already GC'd, so a missing file is expected and ignored.
            $query = $this->entityManager
                ->getQueryBuilder()
                ->select()
                ->from(Attachment::ENTITY_TYPE)
                ->where(['id' => $id])
                ->withDeleted()
                ->build();

            $attachment = $repository->clone($query)->findOne();

            if ($attachment) {
                try {
                    if ($this->fileStorageManager->exists($attachment)) {
                        $this->fileStorageManager->unlink($attachment);
                        $removedFiles++;
                    }
                } catch (\Throwable $e) {
                    $this->log->debug(
                        'CleanupAgentAvatarSyncLoop: could not unlink file for attachment ' .
                        $id . ' — ' . $e->getMessage()
                    );
                }
            }

            // Hard-delete the row regardless of soft-delete state.
            $del = $pdo->prepare("DELETE FROM attachment WHERE id = :id");
            $del->execute([':id' => $id]);
            $removedRows++;
        }

        $this->log->info(
            'CleanupAgentAvatarSyncLoop: purged ' . $removedRows .
            ' attachment row(s), removed ' . $removedFiles . ' backing file(s)'
        );
    }
}
