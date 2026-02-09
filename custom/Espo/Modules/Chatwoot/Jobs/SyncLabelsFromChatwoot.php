<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Scheduled job to sync labels from Chatwoot to EspoCRM.
 * Iterates through all ChatwootAccounts and pulls labels.
 */
class SyncLabelsFromChatwoot implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    public function run(): void
    {
        $this->log->debug('SyncLabelsFromChatwoot: Job started');

        try {
            $accounts = $this->getAccounts();
            
            foreach ($accounts as $account) {
                $this->syncAccountLabels($account);
            }

            $this->log->debug('SyncLabelsFromChatwoot: Job completed');
        } catch (\Throwable $e) {
            $this->log->error('SyncLabelsFromChatwoot: Job failed - ' . $e->getMessage());
        }
    }

    private function getAccounts(): iterable
    {
        return $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where(['status' => 'active'])
            ->find();
    }

    private function syncAccountLabels(Entity $account): void
    {
        try {
            $platform = $this->entityManager->getEntityById(
                'ChatwootPlatform',
                $account->get('platformId')
            );

            if (!$platform) {
                return;
            }

            $platformUrl = $platform->get('backendUrl');
            $apiKey = $account->get('apiKey');
            $chatwootAccountId = $account->get('chatwootAccountId');

            if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
                return;
            }

            $this->log->debug("SyncLabelsFromChatwoot: Syncing account {$chatwootAccountId}");

            $labels = $this->apiClient->listLabels(
                $platformUrl,
                $apiKey,
                $chatwootAccountId
            );

            $syncedLabelIds = [];
            $teamsIds = $account->getLinkMultipleIdList('teams');

            foreach ($labels as $labelData) {
                try {
                    $labelId = $labelData['id'];
                    $syncedLabelIds[] = $labelId;

                    $this->syncSingleLabel($labelData, $account, $teamsIds);
                } catch (\Exception $e) {
                    $this->log->error(
                        "Failed to sync Chatwoot label {$labelData['title']}: " . $e->getMessage()
                    );
                }
            }

            // Reconciliation: Delete labels that no longer exist in Chatwoot
            $this->reconcileDeletedLabels($account, $syncedLabelIds);

        } catch (\Exception $e) {
            $this->log->error(
                "Chatwoot label sync failed for account {$account->getId()}: " . $e->getMessage()
            );
        }
    }

    private function syncSingleLabel(array $labelData, Entity $account, array $teamsIds): void
    {
        $chatwootLabelId = $labelData['id'];
        $name = $labelData['title'];
        $accountId = $account->getId();

        // Try to find by ID first
        $label = $this->entityManager
            ->getRDBRepository('ChatwootLabel')
            ->where([
                'chatwootLabelId' => $chatwootLabelId,
                'chatwootAccountId' => $accountId,
            ])
            ->findOne();

        // Fallback: try to find by name to link existing labels
        if (!$label) {
            $label = $this->entityManager
                ->getRDBRepository('ChatwootLabel')
                ->where([
                    'name' => $name,
                    'chatwootAccountId' => $accountId,
                ])
                ->findOne();
        }

        if (!$label) {
            $label = $this->entityManager->createEntity('ChatwootLabel', [
                'chatwootAccountId' => $accountId,
            ]);
        }

        $label->set('name', $name);
        $label->set('description', $labelData['description'] ?? '');
        $label->set('color', $labelData['color'] ?? '#000000');
        $label->set('showOnSidebar', $labelData['show_on_sidebar'] ?? true);
        $label->set('chatwootLabelId', $chatwootLabelId);
        $label->set('syncStatus', 'synced');
        $label->set('lastSyncedAt', date('Y-m-d H:i:s'));
        
        // ACL: ensure label belongs to the same teams as the account
        if (!empty($teamsIds)) {
            $label->set('teamsIds', $teamsIds);
        }

        // Save silenty to avoid triggering the hook (prevent infinite loop)
        $this->entityManager->saveEntity($label, ['silent' => true]);
    }

    private function reconcileDeletedLabels(Entity $account, array $presentLabelIds): void
    {
        $accountId = $account->getId();
        
        // Find labels in DB that are NOT in the present list
        // We only check synced labels to avoid deleting pending ones
        $labelsToDelete = $this->entityManager
            ->getRDBRepository('ChatwootLabel')
            ->where([
                'chatwootAccountId' => $accountId,
                'syncStatus' => 'synced',
                'chatwootLabelId!=' => $presentLabelIds,
            ])
            ->find();

        foreach ($labelsToDelete as $label) {
            $this->log->info("SyncLabelsFromChatwoot: Deleting label {$label->get('name')} (not in Chatwoot)");
            
            // Delete silently to avoid triggering hook (which would try to delete from Chatwoot)
            $this->entityManager->deleteEntity($label, ['silent' => true]);
        }
    }
}
