<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ManagedAgentCrmUser;
use Espo\ORM\EntityManager;

/** Retryable backfill for agents created before CRM principals were provisioned. */
class BackfillManagedAgentCrmUsers implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private ManagedAgentCrmUser $provisioner,
        private Log $log,
    ) {}

    public function run(): void
    {
        foreach ($this->entityManager->getRDBRepository('ChatwootMachineIdentity')->where([
            'status' => 'active', 'crmUserId' => null,
        ])->find() as $identity) {
            try {
                $this->provisioner->ensure($identity->getId());
            } catch (\Throwable $e) {
                $this->log->error('Managed agent CRM user backfill failed for ' . $identity->getId() . ': ' . $e->getMessage());
            }
        }
    }
}
