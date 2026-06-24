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

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;

/**
 * Consolidated scheduled job to sync account user memberships from Chatwoot.
 *
 * Replaces the two former competing jobs:
 *   - SyncAgentsFromChatwoot (Account API, every 1 min)
 *   - SyncAccountMembersFromChatwoot (Platform API, every 5 min)
 *
 * Strategy:
 *   1. Phase 1 — Authoritative membership list from Platform API (/account_users).
 *      Uses platform accessToken. This is the single source of truth for which
 *      users are members of the account.
 *   2. Phase 2 — Agent enrichment from Account API (/agents).
 *      Uses account apiKey. Enriches memberships with detailed profile fields
 *      (name, email, availability, avatar, etc.) that the Platform API doesn't provide.
 *   3. Phase 3 — Single stale-removal pass based on the authoritative source.
 *
 * Key invariants:
 *   - Never explicitly sets isAI = false. The entity default handles that for new records.
 *   - Preserves all user-configured fields (isAI, aiPrompt, ignoreGroups,
 *     transferScenarios, knowledgeBaseCategories, calendarsToManage, etc.)
 *     during both create and update paths.
 *   - Agents are added to the "seen" set from the authoritative Platform API response,
 *     NOT conditional on enrichment success. This prevents false stale-removal.
 */
class SyncAccountUserMembershipsFromChatwoot implements JobDataLess
{
    private const LOG_PREFIX = 'SyncAccountUserMembershipsFromChatwoot';

    private const AI_PREFIX = '✦ ';

    /**
     * Maximum percentage of existing memberships that can be deleted in a single
     * stale-removal pass. If the number of stale candidates exceeds this ratio,
     * the deletion is skipped entirely to protect against partial/degraded API
     * responses that would otherwise cause mass data loss.
     *
     * Example: with 10 existing memberships and a threshold of 0.5, at most 5
     * can be removed. If 6+ would be removed, the pass is skipped.
     *
     * Set to 0.5 (50%) — a legitimate "half the team left" scenario is rare enough
     * to warrant manual intervention; a partial API response is far more likely.
     */
    private const STALE_REMOVAL_MAX_RATIO = 0.5;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
        private ChatwootAccountUserMembershipService $membershipService
    ) {}

    public function run(): void
    {
        $this->log->debug(self::LOG_PREFIX . ': Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->debug(self::LOG_PREFIX . ": Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccount($account);
            }

            $this->log->debug(self::LOG_PREFIX . ": Job completed - processed {$accountCount} account(s)");
        } catch (\Throwable $e) {
            $this->log->error(
                self::LOG_PREFIX . ': Job failed - ' . $e->getMessage() .
                ' at ' . $e->getFile() . ':' . $e->getLine()
            );
        }
    }

    /**
     * Get all ChatwootAccounts with contact sync enabled.
     *
     * @return iterable<Entity>
     */
    private function getEnabledAccounts(): iterable
    {
        return $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where([
                'contactSyncEnabled' => true,
                'status' => 'active',
            ])
            ->find();
    }

    /**
     * Sync memberships for a single ChatwootAccount.
     */
    private function syncAccount(Entity $account): void
    {
        $accountName = $account->get('name');

        try {
            $platform = $this->entityManager->getEntityById(
                'ChatwootPlatform',
                $account->get('platformId')
            );

            if (!$platform) {
                throw new \Exception('ChatwootPlatform not found');
            }

            $platformUrl = $platform->get('backendUrl');
            $accessToken = $platform->get('accessToken');
            $accountApiKey = $account->get('apiKey');
            $chatwootAccountId = $account->get('chatwootAccountId');
            $espoAccountId = $account->getId();
            $platformId = $account->get('platformId');

            if (!$platformUrl || !$chatwootAccountId) {
                $this->log->warning(
                    self::LOG_PREFIX . ": Skipping account {$accountName} - " .
                    'missing platform URL or Chatwoot account ID'
                );
                return;
            }

            if (!$accessToken) {
                $this->log->warning(
                    self::LOG_PREFIX . ": Skipping account {$accountName} - " .
                    'missing platform access token (required for authoritative membership source)'
                );
                return;
            }

            $teamsIds = $this->getAccountTeamsIds($account);

            // ── Phase 1: Authoritative membership list (Platform API) ──
            $remoteAccountUsers = $this->apiClient->listAccountUsers(
                $platformUrl,
                $accessToken,
                $chatwootAccountId
            );

            // Validate response shape: must be a sequential array of objects.
            // A non-list response (e.g., {"error": "..."} returned with HTTP 200,
            // or non-JSON HTML from a reverse proxy) indicates a degraded API that
            // should NOT be trusted for stale-removal decisions.
            if (!$this->isValidListResponse($remoteAccountUsers)) {
                $this->log->warning(
                    self::LOG_PREFIX . ": Platform API returned a non-list response for " .
                    "account {$accountName}. Skipping sync to prevent data loss. " .
                    "Response type: " . gettype($remoteAccountUsers)
                );
                return;
            }

            // Build the authoritative set of remote user IDs
            $remoteUserIds = [];
            foreach ($remoteAccountUsers as $remoteAccountUser) {
                $remoteUserIds[] = (int) $remoteAccountUser['user_id'];
            }

            // Safety check: refuse to treat empty API response as "delete all"
            $existingMembershipCount = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where(['chatwootAccountId' => $espoAccountId])
                ->count();

            if (count($remoteAccountUsers) === 0 && $existingMembershipCount > 0) {
                $this->log->warning(
                    self::LOG_PREFIX . ": API returned 0 account_users but " .
                    "{$existingMembershipCount} local memberships exist for account {$accountName}. " .
                    "Skipping destructive cleanup to prevent data loss."
                );
                return;
            }

            // ── Phase 2: Agent enrichment (Account API) ──
            // Build a lookup of agent details keyed by platform user ID.
            // If the Account API call fails, we still proceed with Phase 1 data —
            // memberships will be upserted without enrichment rather than skipped.
            $agentsByPlatformUserId = [];

            if ($accountApiKey) {
                try {
                    $agents = $this->apiClient->listAgents(
                        $platformUrl,
                        $accountApiKey,
                        $chatwootAccountId
                    );

                    if (!$this->isValidListResponse($agents)) {
                        $this->log->warning(
                            self::LOG_PREFIX . ": Account API returned a non-list response for " .
                            "account {$accountName}. Skipping enrichment."
                        );
                        $agents = [];
                    }

                    foreach ($agents as $agent) {
                        $agentPlatformUserId = (int) ($agent['id'] ?? 0);
                        if ($agentPlatformUserId) {
                            $agentsByPlatformUserId[$agentPlatformUserId] = $agent;
                        }
                    }

                    $this->log->debug(
                        self::LOG_PREFIX . ": Enrichment: loaded " . count($agentsByPlatformUserId) .
                        " agent profiles for account {$accountName}"
                    );
                } catch (\Throwable $e) {
                    $this->log->warning(
                        self::LOG_PREFIX . ": Agent enrichment failed for account {$accountName}: " .
                        $e->getMessage() . ' — proceeding with Platform API data only'
                    );
                }
            } else {
                $this->log->debug(
                    self::LOG_PREFIX . ": No account API key for {$accountName} — skipping agent enrichment"
                );
            }

            // ── Process each remote account_user ──
            $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'enriched' => 0];

            foreach ($remoteAccountUsers as $remoteAccountUser) {
                $remoteUserId = (int) $remoteAccountUser['user_id'];
                $remoteRole = $remoteAccountUser['role'] ?? 'agent';
                $remoteAccountUserId = (int) $remoteAccountUser['id'];

                // Resolve local ChatwootUser by (chatwootUserId, platformId)
                $localUser = $this->entityManager
                    ->getRDBRepository('ChatwootUser')
                    ->where([
                        'chatwootUserId' => $remoteUserId,
                        'platformId' => $platformId,
                    ])
                    ->findOne();

                if (!$localUser) {
                    $this->log->debug(
                        self::LOG_PREFIX . ": No local ChatwootUser for " .
                        "chatwootUserId={$remoteUserId} platformId={$platformId} - skipping"
                    );
                    $stats['skipped']++;
                    continue;
                }

                // Find existing membership BEFORE upsert to track create vs update
                $existingMembership = $this->entityManager
                    ->getRDBRepository('ChatwootAccountUserMembership')
                    ->where([
                        'chatwootAccountId' => $espoAccountId,
                        'chatwootUserId' => $localUser->getId(),
                    ])
                    ->findOne();

                $isNew = !$existingMembership;

                // Upsert membership (creates or updates role + chatwootAccountUserId)
                $membership = $this->membershipService->upsertMembership(
                    $espoAccountId,
                    $localUser->getId(),
                    $remoteRole,
                    $remoteAccountUserId
                );

                // Enrich with agent profile data if available
                $agentData = $agentsByPlatformUserId[$remoteUserId] ?? null;

                if ($agentData) {
                    $this->enrichMembershipFromAgent($membership, $agentData, $teamsIds);
                    $stats['enriched']++;
                } else {
                    // Even without enrichment, update sync status and teams
                    $membership->set('syncStatus', 'synced');
                    $membership->set('lastSyncedAt', date('Y-m-d H:i:s'));
                    $membership->set('lastSyncError', null);

                    if (!empty($teamsIds)) {
                        $membership->set('teamsIds', $teamsIds);
                    }

                    $this->entityManager->saveEntity($membership, ['silent' => true]);
                }

                if ($isNew) {
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }
            }

            // ── Phase 3: Single stale-removal pass (authoritative source) ──
            $this->removeStaleMembers($espoAccountId, $platformId, $remoteUserIds);

            $this->log->debug(
                self::LOG_PREFIX . ": Account {$accountName} - " .
                "created={$stats['created']}, updated={$stats['updated']}, " .
                "enriched={$stats['enriched']}, skipped={$stats['skipped']}"
            );

        } catch (\Exception $e) {
            $message = $e->getMessage();

            if ($this->isAccountGoneError($message)) {
                $this->log->warning(
                    self::LOG_PREFIX . ": Account {$accountName} returned 404. " .
                    "This may be a transient error during Chatwoot redeployment. " .
                    "No destructive cleanup will be performed automatically."
                );
            } else {
                $this->log->error(
                    self::LOG_PREFIX . ": Sync failed for account {$accountName}: " . $message
                );
            }
        }
    }

    /**
     * Enrich a membership with detailed agent profile data from the Account API.
     *
     * IMPORTANT: Never overwrites user-configured fields (isAI, aiPrompt,
     * ignoreGroups, transferScenarios, knowledgeBaseCategories, calendarsToManage).
     *
     * @param Entity $membership ChatwootAccountUserMembership entity
     * @param array<string, mixed> $agentData Agent data from Account API
     * @param array<string> $teamsIds Team IDs from the ChatwootAccount
     */
    private function enrichMembershipFromAgent(Entity $membership, array $agentData, array $teamsIds = []): void
    {
        $name = $agentData['name'] ?? $membership->get('name') ?? 'Agent #' . ($agentData['id'] ?? '?');

        // Preserve AI prefix if membership is configured as AI
        if ($membership->get('isAI')) {
            // Strip any existing AI prefix before re-adding to avoid duplication
            $name = preg_replace('/^' . preg_quote(self::AI_PREFIX, '/') . '/', '', $name);
            $name = self::AI_PREFIX . $name;
        }

        $membership->set('name', $name);
        $membership->set('email', $agentData['email'] ?? null);
        $membership->set('availableName', $agentData['available_name'] ?? null);
        $membership->set('role', $agentData['role'] ?? $membership->get('role') ?? 'agent');
        $membership->set('availabilityStatus', $agentData['availability_status'] ?? 'offline');
        $membership->set('autoOffline', $agentData['auto_offline'] ?? true);
        $membership->set('confirmed', $agentData['confirmed'] ?? false);
        // Prefer the original blob URL (`avatar_url`) over the resized
        // `thumbnail` representation. Mirroring the re-encoded thumbnail back
        // into the CRM avatar would never byte-match what we pushed, driving an
        // infinite re-encode loop (see AgentAvatarSyncService loop-prevention).
        $membership->set('avatarUrl', $agentData['avatar_url'] ?? $agentData['thumbnail'] ?? null);
        $membership->set('customRoleId', $agentData['custom_role_id'] ?? null);

        // NOTE: isAI is intentionally NOT set here. It is a user-configured field
        // managed exclusively by enableAiProfile() / disableAiProfile().

        $membership->set('syncStatus', 'synced');
        $membership->set('lastSyncedAt', date('Y-m-d H:i:s'));
        $membership->set('lastSyncError', null);

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $membership->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($membership, ['silent' => true]);
    }

    /**
     * Remove memberships whose Chatwoot user is no longer in the authoritative
     * Platform API response.
     *
     * Safety guards:
     *   1. Empty-response guard (checked before this method is called).
     *   2. Suspicious-drop threshold: if the number of stale candidates exceeds
     *      STALE_REMOVAL_MAX_RATIO of existing memberships, the entire removal
     *      pass is skipped. This protects against partial/degraded API responses
     *      that return a subset of the real membership list.
     *
     * Uses ['skipChatwootSync' => true] so the DeleteFromChatwoot hook does NOT
     * call back to Chatwoot — the membership is already gone on their side.
     *
     * @param string $espoAccountId EspoCRM ChatwootAccount entity ID
     * @param string $platformId    EspoCRM ChatwootPlatform entity ID
     * @param array<int> $remoteUserIds Chatwoot user IDs from Platform API (authoritative)
     */
    private function removeStaleMembers(string $espoAccountId, string $platformId, array $remoteUserIds): void
    {
        $allLocalMemberships = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where(['chatwootAccountId' => $espoAccountId])
            ->find();

        $allLocal = iterator_to_array($allLocalMemberships);
        $totalLocal = count($allLocal);

        if ($totalLocal === 0) {
            return;
        }

        // ── First pass: identify stale candidates ──
        $staleCandidates = [];

        foreach ($allLocal as $membership) {
            $userId = $membership->get('chatwootUserId');
            if (!$userId) {
                continue;
            }

            $localUser = $this->entityManager->getEntityById('ChatwootUser', $userId);
            if (!$localUser) {
                continue;
            }

            $chatwootUserId = (int) $localUser->get('chatwootUserId');
            if (!$chatwootUserId) {
                continue;
            }

            if (!in_array($chatwootUserId, $remoteUserIds, true)) {
                $staleCandidates[] = [
                    'membership' => $membership,
                    'chatwootUserId' => $chatwootUserId,
                ];
            }
        }

        if (empty($staleCandidates)) {
            return;
        }

        // ── Suspicious-drop threshold guard ──
        $staleCount = count($staleCandidates);
        $dropRatio = $staleCount / $totalLocal;

        if ($dropRatio > self::STALE_REMOVAL_MAX_RATIO) {
            $pct = round($dropRatio * 100);
            $this->log->warning(
                self::LOG_PREFIX . ": Stale-removal blocked for account {$espoAccountId} — " .
                "{$staleCount}/{$totalLocal} ({$pct}%) memberships would be deleted, " .
                "exceeding the " . round(self::STALE_REMOVAL_MAX_RATIO * 100) . "% safety threshold. " .
                "This likely indicates a partial/degraded API response. " .
                "Skipping removal pass to prevent data loss."
            );
            return;
        }

        // ── Second pass: delete confirmed stale memberships ──
        foreach ($staleCandidates as $candidate) {
            $membership = $candidate['membership'];
            $chatwootUserId = $candidate['chatwootUserId'];
            $membershipName = $membership->get('name');

            try {
                $this->entityManager->removeEntity($membership, ['skipChatwootSync' => true]);
                $this->log->info(
                    self::LOG_PREFIX . ": Removed stale membership '{$membershipName}' " .
                    "(chatwootUserId={$chatwootUserId} no longer in authoritative Platform API response)"
                );
            } catch (\Exception $e) {
                $this->log->error(
                    self::LOG_PREFIX . ": Failed to remove membership {$membership->getId()}: " .
                    $e->getMessage()
                );
            }
        }
    }

    /**
     * Validate that an API response is a sequential array of associative arrays.
     *
     * Catches degraded responses that slip through the HTTP status-code check:
     *   - {"error": "..."} returned with HTTP 200 (object, not list)
     *   - {"raw_response": "<html>..."} from non-JSON reverse proxy pages
     *   - Scalar/null values from malformed JSON
     *
     * An empty array [] is considered valid (handled separately by the
     * empty-response guard).
     *
     * @param mixed $response The decoded API response body
     * @return bool True if the response is a valid list of objects
     */
    private function isValidListResponse(mixed $response): bool
    {
        if (!is_array($response)) {
            return false;
        }

        // Empty array is a valid (but empty) list
        if (count($response) === 0) {
            return true;
        }

        // A sequential array has integer keys starting from 0.
        // An associative array ({"error": "..."}) has string keys — reject it.
        return array_is_list($response);
    }

    /**
     * Check if the error message indicates the Chatwoot account is gone (404).
     */
    private function isAccountGoneError(string $message): bool
    {
        return (bool) preg_match('/HTTP\s+404\b/', $message);
    }

    /**
     * Get team IDs from a ChatwootAccount.
     *
     * @return array<string>
     */
    private function getAccountTeamsIds(Entity $account): array
    {
        return $account->getLinkMultipleIdList('teams');
    }
}
