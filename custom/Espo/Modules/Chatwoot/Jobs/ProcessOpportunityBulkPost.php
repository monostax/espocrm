<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityBulkPost;
use Espo\ORM\EntityManager;

class ProcessOpportunityBulkPost implements Job
{
    public function __construct(private EntityManager $em) {}

    public function run(Data $data): void
    {
        $operation = $this->em->getEntityById(OpportunityBulkPost::OPERATION, (string) $data->get('operationId'));
        if (!$operation || (int) $operation->get('generation') !== (int) $data->get('generation')) return;
        $actor = $this->em->getEntityById('User', $operation->get('userId'));
        if (!$actor instanceof User || !$actor->isActive() || (!$actor->isRegular() && !$actor->isAdmin())) {
            throw new Forbidden('Bulk post author is no longer active.');
        }
        // Binding only a service's User/Acl leaves ORM hooks using the worker's system user.
        // A fresh container makes the original author the identity of the entire Note save pipeline.
        $app = new Application(new ApplicationParams(noErrorHandler: true, services: ['user' => $actor]));
        $app->getInjectableFactory()->create(OpportunityBulkPost::class)
            ->process($operation->getId(), (int) $data->get('generation'));
    }
}
