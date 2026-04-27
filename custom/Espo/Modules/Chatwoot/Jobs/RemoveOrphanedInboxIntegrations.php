<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Entities\ChatwootInboxIntegration;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Scheduled job to remove ChatwootInboxIntegration records
 * that are truly orphaned (no local ChatwootInbox AND the remote
 * Chatwoot inbox is confirmed missing).
 *
 * Safety rules (all must pass before deletion):
 *   1. Integration has no linked, non-deleted ChatwootInbox locally.
 *   2. Integration status is not in an in-flight state
 *      (DRAFT, CREATING, PENDING_QR, CONNECTING) — these may legitimately
 *      lack an inbox while provisioning.
 *   3. EITHER:
 *        a) The integration carries a Chatwoot inbox identifier/id AND
 *           Chatwoot API confirms 404 for that inbox. OR
 *        b) The integration has no Chatwoot identifiers at all AND is
 *           older than MIN_ORPHAN_AGE_SECONDS (classic never-provisioned
 *           stub).
 *   4. If remote status cannot be confirmed (API error, missing account,
 *      missing platform, etc.) we skip with a warning rather than delete.
 *
 * This job previously deleted any integration whose local ChatwootInbox
 * was missing, which caused cascading data loss whenever
 * SyncInboxesFromChatwoot mis-classified an inbox as orphaned.
 */
class RemoveOrphanedInboxIntegrations implements JobDataLess
{
    /** @var array<string> Statuses that indicate the integration is being set up. */
    private const IN_FLIGHT_STATUSES = [
        ChatwootInboxIntegration::STATUS_DRAFT,
        ChatwootInboxIntegration::STATUS_CREATING,
        ChatwootInboxIntegration::STATUS_PENDING_QR,
        ChatwootInboxIntegration::STATUS_CONNECTING,
    ];

    /**
     * Minimum age before an integration with no Chatwoot identifiers is
     * considered a never-provisioned stub eligible for cleanup.
     */
    private const MIN_ORPHAN_AGE_SECONDS = 86400; // 24h

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    public function run(): void
    {
        $this->log->debug('RemoveOrphanedInboxIntegrations: Job started');

        try {
            $linkedIntegrationIds = $this->getLinkedIntegrationIds();

            $integrations = $this->entityManager
                ->getRDBRepository('ChatwootInboxIntegration')
                ->find();

            $removed = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($integrations as $integration) {
                $integrationId = $integration->getId();

                if (in_array($integrationId, $linkedIntegrationIds, true)) {
                    continue;
                }

                $decision = $this->shouldDelete($integration);

                if ($decision !== 'delete') {
                    $skipped++;
                    continue;
                }

                try {
                    $this->entityManager->removeEntity($integration, ['cascadeParent' => true]);
                    $removed++;
                    $this->log->info(
                        "RemoveOrphanedInboxIntegrations: Removed orphaned integration {$integrationId}"
                    );
                } catch (\Throwable $e) {
                    $errors++;
                    $this->log->error(
                        "RemoveOrphanedInboxIntegrations: Failed removing integration {$integrationId}: " .
                        $e->getMessage()
                    );
                }
            }

            $this->log->debug(
                "RemoveOrphanedInboxIntegrations: Job completed - " .
                "removed={$removed}, skipped={$skipped}, errors={$errors}"
            );
        } catch (\Throwable $e) {
            $this->log->error(
                'RemoveOrphanedInboxIntegrations: Job failed - ' .
                $e->getMessage() .
                ' at ' . $e->getFile() . ':' . $e->getLine()
            );
        }
    }

    /**
     * Decide whether to delete an integration whose local inbox link is missing.
     *
     * @return 'delete'|'skip'
     */
    private function shouldDelete(Entity $integration): string
    {
        $integrationId = $integration->getId();

        // Rule 2: never touch in-flight integrations.
        $status = $integration->get('status');
        if (in_array($status, self::IN_FLIGHT_STATUSES, true)) {
            $this->log->debug(
                "RemoveOrphanedInboxIntegrations: Skipping {$integrationId} " .
                "(status={$status} is in-flight)"
            );
            return 'skip';
        }

        $chatwootInboxId = $integration->get('chatwootInboxId');
        $chatwootInboxIdentifier = $integration->get('chatwootInboxIdentifier');

        $hasRemoteHandles = !empty($chatwootInboxId) || !empty($chatwootInboxIdentifier);

        // Rule 3b: no remote handles — treat as never-provisioned stub, but
        // protect against races by enforcing a minimum age.
        if (!$hasRemoteHandles) {
            $createdAt = $integration->get('createdAt');
            $ageSeconds = $createdAt ? (time() - strtotime($createdAt)) : null;

            if ($ageSeconds === null || $ageSeconds < self::MIN_ORPHAN_AGE_SECONDS) {
                $this->log->debug(
                    "RemoveOrphanedInboxIntegrations: Skipping {$integrationId} " .
                    "(no remote handles, too young: {$ageSeconds}s < " .
                    self::MIN_ORPHAN_AGE_SECONDS . 's)'
                );
                return 'skip';
            }

            $this->log->info(
                "RemoveOrphanedInboxIntegrations: Integration {$integrationId} has no " .
                "remote handles and is older than " . self::MIN_ORPHAN_AGE_SECONDS .
                "s; eligible for cleanup."
            );
            return 'delete';
        }

        // Rule 3a: remote confirmation required.
        $remote = $this->confirmRemoteState($integration);

        if ($remote === 'gone') {
            return 'delete';
        }

        if ($remote === 'exists') {
            $this->log->warning(
                "RemoveOrphanedInboxIntegrations: Integration {$integrationId} has no local " .
                "ChatwootInbox, but the remote Chatwoot inbox still exists " .
                "(inboxId={$chatwootInboxId}, identifier={$chatwootInboxIdentifier}). " .
                "Skipping deletion — SyncInboxesFromChatwoot should re-create the local inbox."
            );
            return 'skip';
        }

        // remote === 'unknown'
        $this->log->warning(
            "RemoveOrphanedInboxIntegrations: Could not confirm remote state for " .
            "integration {$integrationId}; skipping deletion."
        );
        return 'skip';
    }

    /**
     * Ask Chatwoot whether the integration's inbox still exists.
     *
     * @return 'exists'|'gone'|'unknown'
     */
    private function confirmRemoteState(Entity $integration): string
    {
        $integrationId = $integration->getId();
        $chatwootInboxId = $integration->get('chatwootInboxId');
        $chatwootInboxIdentifier = $integration->get('chatwootInboxIdentifier');

        $accountId = $integration->get('chatwootAccountId');
        if (!$accountId) {
            $this->log->debug(
                "RemoveOrphanedInboxIntegrations: Integration {$integrationId} has no " .
                "chatwootAccountId; cannot confirm remote state."
            );
            return 'unknown';
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            $this->log->debug(
                "RemoveOrphanedInboxIntegrations: ChatwootAccount {$accountId} not found " .
                "for integration {$integrationId}; cannot confirm remote state."
            );
            return 'unknown';
        }

        $apiKey = $account->get('apiKey');
        $remoteAccountId = $account->get('chatwootAccountId');
        $platformId = $account->get('platformId');

        if (!$apiKey || !$remoteAccountId || !$platformId) {
            return 'unknown';
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            return 'unknown';
        }

        $platformUrl = $platform->get('backendUrl');
        if (!$platformUrl) {
            return 'unknown';
        }

        // Strategy A: direct lookup by numeric inbox id.
        if (!empty($chatwootInboxId)) {
            try {
                $inbox = $this->apiClient->getInbox(
                    $platformUrl,
                    $apiKey,
                    (int) $remoteAccountId,
                    (int) $chatwootInboxId
                );

                return $inbox === null ? 'gone' : 'exists';
            } catch (\Throwable $e) {
                $this->log->warning(
                    "RemoveOrphanedInboxIntegrations: getInbox failed for integration " .
                    "{$integrationId} (inboxId={$chatwootInboxId}): " . $e->getMessage()
                );
                // Fall through to identifier match.
            }
        }

        // Strategy B: scan listInboxes for matching identifier.
        if (!empty($chatwootInboxIdentifier)) {
            try {
                $response = $this->apiClient->listInboxes(
                    $platformUrl,
                    $apiKey,
                    (int) $remoteAccountId
                );

                $inboxes = $response['payload'] ?? [];

                foreach ($inboxes as $remoteInbox) {
                    if (($remoteInbox['inbox_identifier'] ?? null) === $chatwootInboxIdentifier) {
                        return 'exists';
                    }
                }

                // Full list returned, identifier not present => confirmed gone.
                return 'gone';
            } catch (\Throwable $e) {
                $this->log->warning(
                    "RemoveOrphanedInboxIntegrations: listInboxes failed for integration " .
                    "{$integrationId}: " . $e->getMessage()
                );
                return 'unknown';
            }
        }

        return 'unknown';
    }

    /**
     * @return array<string>
     */
    private function getLinkedIntegrationIds(): array
    {
        $inboxes = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where([
                'chatwootInboxIntegrationId!=' => null,
                'deleted' => false,
            ])
            ->find();

        $linkedIntegrationIds = [];

        foreach ($inboxes as $inbox) {
            $integrationId = $inbox->get('chatwootInboxIntegrationId');

            if ($integrationId) {
                $linkedIntegrationIds[$integrationId] = true;
            }
        }

        return array_keys($linkedIntegrationIds);
    }
}
