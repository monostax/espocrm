<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Job\QueueName;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Modules\Chatwoot\Jobs\BackfillManagedAgentCrmUsers as BackfillJob;
use Espo\ORM\EntityManager;

/** Rebuild disables ORM hooks; defer provisioning until email/team field savers are enabled. */
class BackfillManagedAgentCrmUsers implements RebuildAction
{
    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        if (!$this->entityManager->getRDBRepository('ChatwootMachineIdentity')->where([
            'status' => 'active', 'crmUserId' => null,
        ])->findOne()) return;
        if ($this->entityManager->getRDBRepository('Job')->where([
            'className' => BackfillJob::class, 'status' => ['Pending', 'Running'],
        ])->findOne()) return;
        $this->entityManager->createEntity('Job', [
            'name' => 'Provision managed agent CRM users', 'className' => BackfillJob::class,
            'queue' => QueueName::Q0, 'status' => 'Pending', 'attempts' => 3,
        ]);
    }
}
