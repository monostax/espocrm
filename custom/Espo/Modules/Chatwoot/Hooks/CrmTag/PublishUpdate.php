<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\CrmTag;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Job\QueueName;
use Espo\Modules\Chatwoot\Jobs\BroadcastActivityUpdate;
use Espo\Modules\Chatwoot\Jobs\BroadcastOpportunityUpdate;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\Option\RemoveOptions;

/** Catalog changes invalidate both workspaces after commit, without per-record jobs. */
class PublishUpdate implements AfterSave, AfterRemove
{
    public static int $order = 99;

    public function __construct(private EntityManager $em) {}

    public function afterSave(Entity $entity, SaveOptions $options): void { $this->schedule($entity); }
    public function afterRemove(Entity $entity, RemoveOptions $options): void { $this->schedule($entity); }

    private function schedule(Entity $entity): void
    {
        foreach ([BroadcastOpportunityUpdate::class, BroadcastActivityUpdate::class] as $job) {
            $this->em->createEntity('Job', [
                'name' => $job, 'className' => $job, 'queue' => QueueName::Q0, 'attempts' => 3,
                'data' => (object) ['tenantIds' => [$entity->get('tenantId')]],
            ]);
        }
    }
}
