<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ConversationEpisodeSync;
use Espo\ORM\EntityManager;

class SyncConversationEpisodesFromChatwoot implements JobDataLess
{
    public function __construct(private EntityManager $entityManager, private ConversationEpisodeSync $sync, private Log $log)
    {}

    public function run(): void
    {
        $accounts = $this->entityManager->getRDBRepository('ChatwootAccount')->where(['episodeSyncEnabled' => true])->find();
        foreach ($accounts as $account) {
            try {
                $this->sync->syncAccount($account);
            } catch (\Throwable $e) {
                $this->log->error("Episode sync account {$account->getId()}: " . $e->getMessage());
            }
        }
    }
}
