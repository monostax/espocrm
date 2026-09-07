<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\WebSocket\Submission;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\EntityManager;
use Throwable;

/** Post-commit, retryable invalidation for Espo WebSocket and Chatwoot ActionCable. */
class BroadcastOpportunityUpdate implements Job
{
    public function __construct(
        private EntityManager $entityManager,
        private Submission $webSocketSubmission,
        private ChatwootApiClient $apiClient,
    ) {}

    public function run(Data $data): void
    {
        $id = $data->get('opportunityId');
        $tenantIds = $data->get('tenantIds');
        if (!$id || !$tenantIds) {
            return;
        }

        // Reuse Espo's ACL-checked record/stream topics. Read cutoffs are personal.
        $this->webSocketSubmission->submit("recordUpdate.Opportunity.$id", $data->get('userId'));
        $this->webSocketSubmission->submit("streamUpdate.Opportunity.$id", $data->get('userId'));

        $accounts = $this->entityManager->getRDBRepository('ChatwootAccount')
            ->where(['tenantId' => $tenantIds])->find();
        $failure = null;
        foreach ($accounts as $account) {
            $accountId = $account->get('chatwootAccountId');
            $apiKey = $account->get('apiKey');
            $platformId = $account->get('platformId');
            if (!$accountId || !$apiKey || !$platformId) {
                continue;
            }
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            $url = $platform?->get('backendUrl');
            if (!$url) {
                continue;
            }

            try {
                // Only an invalidation crosses the account boundary. Browsers refetch with their own CRM ACL.
                $this->apiClient->notifyOpportunityUpdate($url, $apiKey, (int) $accountId);
            } catch (Throwable $e) {
                // One unavailable Chatwoot platform must not block the other accounts.
                $failure = $e;
            }
        }

        if ($failure) {
            throw $failure; // Let the durable job queue retry. Duplicate invalidations are harmless.
        }
    }
}
