<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Scheduled job to remove ChatwootInboxIntegration records
 * that are not linked to any ChatwootInbox.
 */
class RemoveOrphanedInboxIntegrations implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
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
            $errors = 0;

            foreach ($integrations as $integration) {
                $integrationId = $integration->getId();

                $hasLinkedInbox = in_array($integrationId, $linkedIntegrationIds, true);

                if ($hasLinkedInbox) {
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
                "RemoveOrphanedInboxIntegrations: Job completed - removed={$removed}, errors={$errors}"
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
