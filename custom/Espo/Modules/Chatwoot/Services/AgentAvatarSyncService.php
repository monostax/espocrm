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

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\Utils\Log;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Keeps agent avatars in sync between an EspoCRM {@see User} (the CRM-side
 * identity) and its Chatwoot counterpart (the {@see ChatwootUser} entity plus
 * the Chatwoot platform user behind it).
 *
 * ## Directions
 *
 *   - **CRM → Chatwoot** (push): invoked by the `User.afterSave` hook when
 *     `avatarId` is dirty. Iterates every {@see ChatwootUser} whose
 *     `assignedUser` points at the CRM user and uploads the new attachment
 *     bytes (or issues a delete) to each Chatwoot platform user's profile.
 *
 *   - **Chatwoot → CRM** (pull): invoked by the
 *     `ChatwootAccountUserMembership.afterSave` hook when `avatarUrl` is dirty.
 *     Downloads the hosted **original blob** (not the 250px representation),
 *     creates a fresh `Attachment` row, and overwrites the CRM user's
 *     `avatarId` when Chatwoot is the source of a real change.
 *
 * ## Loop / quality-loss prevention
 *
 * Chatwoot's public `thumbnail` / UI `avatar_url` is an Active Storage
 * `representation(resize_to_fill: [250, nil])` — a fresh JPEG re-encode on
 * every generation. Pulling that back into the CRM and then re-pushing it
 * caused generational loss that collapsed avatars into grayscale noise after
 * enough round-trips (see {@see \Espo\Modules\Chatwoot\Rebuild\CleanupAgentAvatarSyncLoop}).
 *
 * Defenses:
 *
 *   1. Membership sync prefers Chatwoot's `avatar_original_url` (raw blob).
 *   2. Representation URLs (`.../representations/...`) are never ingested; if
 *      CRM already has bytes we re-push them to heal CW.
 *   3. Two content-hash columns on `ChatwootUser`:
 *        - `crmAvatarSyncHash` — SHA-256 of bytes last *pushed* to CW
 *        - `chatwootAvatarSyncHash` — SHA-256 of bytes last *pulled* from CW
 *      Each direction short-circuits when bytes match the corresponding column.
 *      After a successful pull both columns are aligned and the CRM save is
 *      explicitly marked so the reverse `User.afterSave` push is skipped.
 *
 * ## Best-effort policy
 *
 * All network calls are wrapped: avatar sync never aborts the underlying
 * save. A missing access token, unreachable Chatwoot host, 5xx, or corrupt
 * Attachment row is logged and swallowed. The hash columns are only advanced
 * on successful operations, so the next trigger will naturally retry.
 */
class AgentAvatarSyncService
{
    /**
     * Attachment role tag written on avatars we mirror from Chatwoot. Kept
     * distinct from {@see Attachment::ROLE_ATTACHMENT} so operational tooling
     * can tell mirrored files apart from user-uploaded ones.
     */
    private const MIRRORED_AVATAR_ROLE = 'Attachment';

    /** Default filename used when Chatwoot doesn't volunteer one. */
    private const DEFAULT_FILENAME = 'avatar.png';

    public function __construct(
        private EntityManager $entityManager,
        private FileStorageManager $fileStorageManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Entry point for the CRM → Chatwoot direction.
     *
     * Called from the `User.afterSave` hook whenever a CRM User's `avatarId`
     * field is dirty (including null → null transitions, which we filter out
     * here rather than at the hook layer so this service has a single, stable
     * contract).
     *
     * For every {@see ChatwootUser} linked to the CRM user via `assignedUser`,
     * resolves the platform & user access token and forwards the new bytes
     * (or a delete, if the CRM avatar was cleared) to Chatwoot.
     *
     * @param string $crmUserId EspoCRM User entity ID
     */
    public function pushCrmUserAvatarToChatwoot(string $crmUserId): void
    {
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $crmUserId);

        if (!$user) {
            return;
        }

        $chatwootUsers = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where(['assignedUserId' => $crmUserId])
            ->find();

        $avatarId = $user->get('avatarId');
        $payload = $avatarId ? $this->loadAttachmentPayload($avatarId) : null;

        foreach ($chatwootUsers as $chatwootUser) {
            try {
                $this->pushToChatwootUser($chatwootUser, $payload);
            } catch (\Throwable $e) {
                // Best-effort per-user: a broken token or unreachable platform
                // for one tenant should never prevent the sibling from syncing.
                $this->log->warning(
                    'AgentAvatarSyncService: CRM → CW push failed for ChatwootUser ' .
                    $chatwootUser->getId() . ' — ' . $e->getMessage()
                );
            }
        }
    }

    /**
     * Entry point for the Chatwoot → CRM direction.
     *
     * Called from `ChatwootAccountUserMembership.afterSave` when `avatarUrl`
     * is dirty. Resolves the owning {@see ChatwootUser} + CRM User and mirrors
     * the hosted avatar (or clears it, on a url → null transition) onto the
     * CRM User's `avatarId`.
     *
     * No-ops when the membership has no linked CRM user (e.g. concierge users,
     * which are system identities with no Espo owner).
     *
     * @param Entity $membership ChatwootAccountUserMembership entity
     * @param string|null $newAvatarUrl  The freshly-saved `avatarUrl` (null means cleared)
     */
    public function pullChatwootAvatarToCrmUser(Entity $membership, ?string $newAvatarUrl): void
    {
        $chatwootUserId = $membership->get('chatwootUserId');
        if (!$chatwootUserId) {
            return;
        }

        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);
        if (!$chatwootUser) {
            return;
        }

        $crmUserId = $chatwootUser->get('assignedUserId');
        if (!$crmUserId) {
            // Concierge / system ChatwootUser with no CRM counterpart —
            // the avatarUrl on the membership is the terminal artefact.
            return;
        }

        $crmUser = $this->entityManager->getEntityById(User::ENTITY_TYPE, $crmUserId);
        if (!$crmUser) {
            return;
        }

        try {
            if ($newAvatarUrl === null || $newAvatarUrl === '') {
                $this->clearCrmUserAvatar($crmUser, $chatwootUser);
                return;
            }

            $this->applyAvatarUrlToCrmUser($crmUser, $chatwootUser, $newAvatarUrl);
        } catch (\Throwable $e) {
            $this->log->warning(
                'AgentAvatarSyncService: CW → CRM pull failed for ChatwootUser ' .
                $chatwootUser->getId() . ' — ' . $e->getMessage()
            );
        }
    }

    /* -------------------------------------------------------------------- */
    /*  CRM → Chatwoot                                                      */
    /* -------------------------------------------------------------------- */

    /**
     * Push (or clear) the avatar on a single ChatwootUser's Chatwoot profile.
     *
     * @param Entity $chatwootUser  ChatwootUser entity
     * @param array{bytes: string, mime: string, name: string}|null $payload
     *   The attachment bytes to push, or null to request deletion.
     */
    private function pushToChatwootUser(Entity $chatwootUser, ?array $payload): void
    {
        $newHash = $this->computeHash($payload['bytes'] ?? '');

        // Short-circuit: we already pushed these exact bytes (or the empty
        // state) last time. Avoids hammering Chatwoot on unrelated User saves.
        if ($chatwootUser->get('crmAvatarSyncHash') === $newHash) {
            return;
        }

        // Loop-break (defense-in-depth): never push back bytes that we just
        // pulled *from* Chatwoot. If the CRM avatar is byte-identical to the
        // last bytes we mirrored down (`chatwootAvatarSyncHash`), pushing
        // it would make Chatwoot re-encode a thumbnail, we'd pull the re-encode,
        // and the image would degrade one generation per round-trip. Realign
        // the push hash so subsequent unrelated saves also no-op.
        if ($payload !== null && $chatwootUser->get('chatwootAvatarSyncHash') === $newHash) {
            if ($chatwootUser->get('crmAvatarSyncHash') !== $newHash) {
                $chatwootUser->set('crmAvatarSyncHash', $newHash);
                $this->entityManager->saveEntity($chatwootUser, ['silent' => true, 'skipHooks' => true]);
            }
            return;
        }

        $resolved = $this->resolvePushContext($chatwootUser);
        if ($resolved === null) {
            return;
        }

        [$platformUrl, $userAccessToken] = $resolved;

        if ($payload === null) {
            $this->apiClient->deleteUserAvatar($platformUrl, $userAccessToken);
        } else {
            $this->apiClient->updateUserAvatarFromBytes(
                $platformUrl,
                $userAccessToken,
                $payload['bytes'],
                $payload['mime'],
                $payload['name']
            );
        }

        $chatwootUser->set('crmAvatarSyncHash', $newHash);
        $this->entityManager->saveEntity($chatwootUser, ['silent' => true, 'skipHooks' => true]);

        $this->log->info(
            'AgentAvatarSyncService: ' . ($payload ? 'Pushed' : 'Cleared') .
            ' avatar on Chatwoot for ChatwootUser ' . $chatwootUser->getId() .
            ' (chatwootUserId=' . $chatwootUser->get('chatwootUserId') . ')'
        );
    }

    /**
     * Resolve the `platformUrl` + per-user `access_token` needed to hit
     * Chatwoot's `/api/v1/profile` endpoints on behalf of a ChatwootUser.
     *
     * Lazy-fetches the user's access token via the Platform API if it wasn't
     * persisted at creation time and caches the result on the entity so
     * subsequent avatar operations don't re-probe Chatwoot.
     *
     * Returns null with a log line (not an exception) when any part of the
     * chain is missing — avatar sync must never escalate into a hard failure.
     *
     * @return array{0: string, 1: string}|null  [platformUrl, userAccessToken]
     */
    private function resolvePushContext(Entity $chatwootUser): ?array
    {
        $platformId = $chatwootUser->get('platformId');
        if (!$platformId) {
            return null;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            return null;
        }

        $platformUrl = $platform->get('backendUrl');
        if (!$platformUrl) {
            return null;
        }

        $userAccessToken = $chatwootUser->get('userAccessToken');

        if (!$userAccessToken) {
            $chatwootUserPlatformId = $chatwootUser->get('chatwootUserId');
            $platformAccessToken = $platform->get('accessToken');

            if (!$chatwootUserPlatformId || !$platformAccessToken) {
                $this->log->debug(
                    'AgentAvatarSyncService: No user access token for ChatwootUser ' .
                    $chatwootUser->getId() . ' and no platform credentials to refetch — skipping'
                );
                return null;
            }

            $userAccessToken = $this->apiClient->fetchUserAccessToken(
                $platformUrl,
                $platformAccessToken,
                (int) $chatwootUserPlatformId
            );

            if (!$userAccessToken) {
                $this->log->debug(
                    'AgentAvatarSyncService: Platform API did not expose access_token for ChatwootUser ' .
                    $chatwootUser->getId() . ' — skipping'
                );
                return null;
            }

            $chatwootUser->set('userAccessToken', $userAccessToken);
            $this->entityManager->saveEntity($chatwootUser, ['silent' => true, 'skipHooks' => true]);
        }

        return [$platformUrl, $userAccessToken];
    }

    /**
     * Load an attachment's bytes + metadata. Returns null (with a warning)
     * when the attachment row is missing or the underlying storage can't
     * serve the file — we log and move on instead of aborting the sync.
     *
     * @return array{bytes: string, mime: string, name: string}|null
     */
    private function loadAttachmentPayload(string $attachmentId): ?array
    {
        /** @var ?Attachment $attachment */
        $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

        if (!$attachment) {
            $this->log->warning(
                'AgentAvatarSyncService: Attachment ' . $attachmentId . ' not found while loading avatar'
            );
            return null;
        }

        try {
            $bytes = $this->fileStorageManager->getContents($attachment);
        } catch (\Throwable $e) {
            $this->log->warning(
                'AgentAvatarSyncService: Could not read bytes of Attachment ' .
                $attachmentId . ' — ' . $e->getMessage()
            );
            return null;
        }

        if ($bytes === '') {
            return null;
        }

        return [
            'bytes' => $bytes,
            'mime' => $attachment->getType() ?: 'image/png',
            'name' => $attachment->getName() ?: self::DEFAULT_FILENAME,
        ];
    }

    /* -------------------------------------------------------------------- */
    /*  Chatwoot → CRM                                                      */
    /* -------------------------------------------------------------------- */

    /**
     * Download `$avatarUrl`, make it the new CRM User avatar, and align both
     * hash columns while suppressing the reverse `User.afterSave` push.
     *
     * Representation (thumbnail) URLs never clobber an existing CRM avatar —
     * they are a re-encode of the original and would degrade quality. When CRM
     * already has an avatar we instead re-push it so Chatwoot recovers.
     */
    private function applyAvatarUrlToCrmUser(Entity $crmUser, Entity $chatwootUser, string $avatarUrl): void
    {
        // Never ingest Active Storage representations, even as an initial CRM
        // avatar. They are lossy 250px variants and can start the feedback loop
        // as soon as the mirrored attachment is pushed back to Chatwoot.
        if ($this->isActiveStorageRepresentationUrl($avatarUrl)) {
            $this->log->info(
                'AgentAvatarSyncService: Refusing CW representation URL for CRM User ' .
                $crmUser->getId()
            );

            if ($crmUser->get('avatarId')) {
                $this->maybeHealPushCrmAvatar($crmUser, $chatwootUser);
            }

            return;
        }

        $bytes = $this->apiClient->downloadBinary($avatarUrl);

        if ($bytes === '') {
            return;
        }

        $hash = $this->computeHash($bytes);

        if ($chatwootUser->get('chatwootAvatarSyncHash') === $hash) {
            // This avatar is byte-identical to the last one we synced;
            // the avatarUrl changed (ActiveStorage signed URLs rotate) but
            // the underlying image didn't. Skip to avoid a no-op write loop.
            return;
        }

        if ($chatwootUser->get('crmAvatarSyncHash') === $hash) {
            // Chatwoot is reporting the original blob from our last successful
            // CRM push. Align the pull hash without replacing the user's source
            // attachment with a duplicate mirrored attachment.
            $chatwootUser->set('chatwootAvatarSyncHash', $hash);
            $this->entityManager->saveEntity($chatwootUser, ['silent' => true, 'skipHooks' => true]);

            return;
        }

        // When CRM already has larger bytes than the download, refuse to
        // downgrade (e.g. mixed deployment before CW exposes original URL).
        if ($crmUser->get('avatarId') && $this->wouldDegradeCrmAvatar((string) $crmUser->get('avatarId'), $bytes)) {
            $this->log->info(
                'AgentAvatarSyncService: Skipping CW→CRM pull for User ' .
                $crmUser->getId() . ' — download is smaller than existing CRM avatar'
            );

            // Remember download hash so URL rotation doesn't re-trigger; leave
            // crmAvatarSyncHash alone so a later CRM push still runs.
            $chatwootUser->set('chatwootAvatarSyncHash', $hash);
            $this->entityManager->saveEntity($chatwootUser, ['silent' => true, 'skipHooks' => true]);

            $this->maybeHealPushCrmAvatar($crmUser, $chatwootUser);

            return;
        }

        $mime = $this->detectMimeType($bytes, $avatarUrl);
        $filename = $this->deriveFilename($avatarUrl, $mime);

        $attachmentId = $this->createAvatarAttachment($crmUser->getId(), $bytes, $mime, $filename);

        $crmUser->set('avatarId', $attachmentId);
        // This save is the Chatwoot → CRM direction. Suppress the reverse hook
        // so it does not immediately re-upload the just-downloaded blob before
        // the hash bookkeeping below has been persisted.
        $this->entityManager->saveEntity($crmUser, [
            'silent' => true,
            'skipChatwootAvatarSync' => true,
        ]);

        // Align BOTH hashes: CRM and Chatwoot now agree about these bytes.
        $chatwootUser->set('chatwootAvatarSyncHash', $hash);
        $chatwootUser->set('crmAvatarSyncHash', $hash);
        $this->entityManager->saveEntity($chatwootUser, ['silent' => true, 'skipHooks' => true]);

        $this->log->info(
            'AgentAvatarSyncService: Mirrored Chatwoot avatar onto CRM User ' .
            $crmUser->getId() . ' from ChatwootUser ' . $chatwootUser->getId() .
            ' (original blob)'
        );
    }

    /**
     * Clear the CRM User's avatar when Chatwoot tells us there is none.
     *
     * Setting both hash columns to the empty-state sentinel keeps the round
     * trip symmetric with {@see applyAvatarUrlToCrmUser()}: the ensuing
     * `User.afterSave` push sees `crmAvatarSyncHash === sha256('')` and
     * declines to re-issue a delete at Chatwoot.
     */
    private function clearCrmUserAvatar(Entity $crmUser, Entity $chatwootUser): void
    {
        $emptyHash = $this->computeHash('');

        // Both sides already know "no avatar" — nothing to do.
        if (
            !$crmUser->get('avatarId') &&
            $chatwootUser->get('chatwootAvatarSyncHash') === $emptyHash
        ) {
            return;
        }

        if ($crmUser->get('avatarId')) {
            $crmUser->set('avatarId', null);
            $this->entityManager->saveEntity($crmUser, [
                'silent' => true,
                'skipChatwootAvatarSync' => true,
            ]);
        }

        $chatwootUser->set('chatwootAvatarSyncHash', $emptyHash);
        $chatwootUser->set('crmAvatarSyncHash', $emptyHash);
        $this->entityManager->saveEntity($chatwootUser, ['silent' => true, 'skipHooks' => true]);

        $this->log->info(
            'AgentAvatarSyncService: Cleared CRM User ' . $crmUser->getId() .
            ' avatar following Chatwoot removal (ChatwootUser ' . $chatwootUser->getId() . ')'
        );
    }

    /**
     * Create an `Attachment` row backed by `$bytes` and return its ID, ready
     * to be plugged into `User.avatarId`. Mirrors the pattern used elsewhere
     * in the platform (see {@see \Espo\Tools\Pdf\MassService}): save the
     * entity first so it gets an ID, *then* push bytes into storage.
     */
    private function createAvatarAttachment(string $userId, string $bytes, string $mime, string $name): string
    {
        /** @var Attachment $attachment */
        $attachment = $this->entityManager->getNewEntity(Attachment::ENTITY_TYPE);

        $attachment
            ->setName($name)
            ->setType($mime)
            ->setRole(self::MIRRORED_AVATAR_ROLE)
            ->setSize(strlen($bytes))
            ->setTargetField('avatar');

        // Bind the attachment to the user's `avatar` field. This matches how
        // a regular UI upload populates the row and keeps orphan-cleanup jobs
        // happy.
        $attachment->set('relatedType', User::ENTITY_TYPE);
        $attachment->set('relatedId', $userId);

        $this->entityManager->saveEntity($attachment);

        $this->fileStorageManager->putContents($attachment, $bytes);

        return $attachment->getId();
    }

    /* -------------------------------------------------------------------- */
    /*  Small helpers                                                       */
    /* -------------------------------------------------------------------- */

    private function computeHash(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /**
     * Active Storage variant/representation redirect path — always a lossy
     * transform of the master blob when used for avatars (`resize_to_fill`).
     */
    private function isActiveStorageRepresentationUrl(string $url): bool
    {
        return str_contains($url, '/rails/active_storage/representations/');
    }

    /**
     * Re-push CRM avatar to Chatwoot when it is a clean original (not a
     * corrupted `cw-avatar-*` residue of the historical sync loop).
     */
    private function maybeHealPushCrmAvatar(Entity $crmUser, Entity $chatwootUser): void
    {
        $avatarId = $crmUser->get('avatarId');
        if (!$avatarId) {
            return;
        }

        $payload = $this->loadAttachmentPayload((string) $avatarId);
        if ($payload === null) {
            return;
        }

        if (str_starts_with($payload['name'], 'cw-avatar-')) {
            $this->log->debug(
                'AgentAvatarSyncService: Skipping heal-push for CRM User ' .
                $crmUser->getId() . ' — current avatar is a corrupted cw-avatar-* residue'
            );
            return;
        }

        try {
            $this->pushToChatwootUser($chatwootUser, $payload);
        } catch (\Throwable $e) {
            $this->log->warning(
                'AgentAvatarSyncService: Heal-push failed for ChatwootUser ' .
                $chatwootUser->getId() . ' — ' . $e->getMessage()
            );
        }
    }

    /**
     * True when the download is sneakily smaller than the current CRM avatar
     * (strong signal of a 250px variant / re-encode vs a full original).
     * Uses an 85% threshold to absorb innocent format conversion shrinkage.
     */
    private function wouldDegradeCrmAvatar(string $existingAttachmentId, string $newBytes): bool
    {
        $existing = $this->loadAttachmentPayload($existingAttachmentId);

        if ($existing === null) {
            return false;
        }

        $existingSize = strlen($existing['bytes']);
        $newSize = strlen($newBytes);

        if ($existingSize <= 0 || $newSize <= 0) {
            return false;
        }

        return $newSize < (int) floor($existingSize * 0.85);
    }

    /**
     * Best-effort MIME sniffing. `finfo` on a raw buffer beats parsing the URL
     * (ActiveStorage paths rarely carry the right extension). Falls back to
     * the URL's extension, then `image/png`.
     */
    private function detectMimeType(string $bytes, string $url): string
    {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = @finfo_buffer($finfo, $bytes);
                @finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }

    /**
     * Produce a reasonable filename for the mirrored Attachment. Falls back
     * to a MIME-derived extension when the URL doesn't have one (Chatwoot's
     * `rails/active_storage/blobs/redirect/...` URLs usually don't).
     *
     * Never preserve legacy `cw-avatar-*` basenames from corrupted loop blobs —
     * those names mark unusable noise for CleanupAgentAvatarSyncLoop.
     */
    private function deriveFilename(string $url, string $mime): string
    {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => 'png',
        };

        $basename = basename(parse_url($url, PHP_URL_PATH) ?: '');

        if (
            $basename !== '' &&
            pathinfo($basename, PATHINFO_EXTENSION) !== '' &&
            !str_starts_with($basename, 'cw-avatar-')
        ) {
            return $basename;
        }

        return 'avatar.' . $extension;
    }
}
