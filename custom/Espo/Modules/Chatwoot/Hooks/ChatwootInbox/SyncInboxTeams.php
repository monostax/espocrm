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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootInbox;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to sync ChatwootInbox team changes with Chatwoot.
 *
 * When a ChatwootTeam is linked/unlinked to a ChatwootInbox (from the inbox
 * side), this hook pushes the updated full team list to Chatwoot via
 * PATCH /api/v1/accounts/{id}/inbox_teams.
 *
 * The Chatwoot API uses replace-all semantics. Chatwoot materializes members
 * of linked teams into inbox members automatically (department-scoped inbox
 * privacy), so linking a department team grants its agents/admins access to
 * the inbox.
 */
class SyncInboxTeams
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Called when a ChatwootTeam is linked to this ChatwootInbox.
     */
    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (!isset($relationParams['relationName']) || $relationParams['relationName'] !== 'chatwootTeams') {
            return;
        }

        $this->syncInboxTeamsToChatwoot($entity, 'relate', $relationParams['foreignId'] ?? null);
    }

    /**
     * Called when a ChatwootTeam is unlinked from this ChatwootInbox.
     */
    public function afterUnrelate(Entity $entity, array $options, array $relationParams): void
    {
        if (!isset($relationParams['relationName']) || $relationParams['relationName'] !== 'chatwootTeams') {
            return;
        }

        $this->syncInboxTeamsToChatwoot($entity, 'unrelate', $relationParams['foreignId'] ?? null);
    }

    /**
     * Push the current full team list for this inbox to Chatwoot.
     *
     * Reads all currently-linked ChatwootTeams, resolves each to its Chatwoot
     * platform team ID, and sends the complete list via
     * PATCH /api/v1/accounts/{id}/inbox_teams.
     */
    private function syncInboxTeamsToChatwoot(Entity $inbox, string $action, ?string $teamId): void
    {
        $chatwootInboxId = $inbox->get('chatwootInboxId');

        if (!$chatwootInboxId) {
            $this->log->warning(
                "SyncInboxTeams: Inbox {$inbox->getId()} has no chatwootInboxId, cannot sync"
            );
            return;
        }

        try {
            $credentials = $this->getApiCredentials($inbox);

            if (!$credentials) {
                return;
            }

            // Read the current full list of teams on this inbox
            $teams = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->getRelation($inbox, 'chatwootTeams')
                ->find();

            // Resolve each team to its Chatwoot platform team ID
            $chatwootTeamIds = [];

            foreach ($teams as $team) {
                $platformTeamId = $team->get('chatwootTeamId');

                if ($platformTeamId) {
                    $chatwootTeamIds[] = (int) $platformTeamId;
                }
            }

            $this->log->info(
                "SyncInboxTeams: {$action} on inbox {$chatwootInboxId} — " .
                "pushing " . count($chatwootTeamIds) . " team(s) to Chatwoot" .
                ($teamId ? " (triggered by team {$teamId})" : '')
            );

            $this->apiClient->updateInboxTeams(
                $credentials['platformUrl'],
                $credentials['apiKey'],
                $credentials['chatwootAccountId'],
                $chatwootInboxId,
                $chatwootTeamIds
            );

            $this->log->info(
                "SyncInboxTeams: Successfully synced inbox {$chatwootInboxId} teams to Chatwoot"
            );

        } catch (\Exception $e) {
            $this->log->error(
                "SyncInboxTeams: Failed to sync inbox {$chatwootInboxId} teams: " . $e->getMessage()
            );
        }
    }

    /**
     * Get API credentials from the inbox's account.
     *
     * @return array{platformUrl: string, apiKey: string, chatwootAccountId: int}|null
     */
    private function getApiCredentials(Entity $inbox): ?array
    {
        $accountId = $inbox->get('chatwootAccountId');

        if (!$accountId) {
            $this->log->warning('SyncInboxTeams: Inbox has no chatwootAccountId');
            return null;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);

        if (!$account) {
            $this->log->warning('SyncInboxTeams: ChatwootAccount not found: ' . $accountId);
            return null;
        }

        $chatwootAccountId = $account->get('chatwootAccountId');
        $apiKey = $account->get('apiKey');
        $platformId = $account->get('platformId');

        if (!$chatwootAccountId || !$apiKey || !$platformId) {
            $this->log->warning('SyncInboxTeams: Account missing credentials');
            return null;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);

        if (!$platform) {
            $this->log->warning('SyncInboxTeams: ChatwootPlatform not found: ' . $platformId);
            return null;
        }

        $platformUrl = $platform->get('backendUrl');

        if (!$platformUrl) {
            $this->log->warning('SyncInboxTeams: Platform has no URL');
            return null;
        }

        return [
            'platformUrl' => $platformUrl,
            'apiKey' => $apiKey,
            'chatwootAccountId' => $chatwootAccountId,
        ];
    }
}
