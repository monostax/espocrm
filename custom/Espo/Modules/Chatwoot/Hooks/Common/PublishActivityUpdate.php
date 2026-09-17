<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Common;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\Core\Job\QueueName;
use Espo\Modules\Chatwoot\Jobs\BroadcastActivityUpdate;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\Option\RemoveOptions;

class PublishActivityUpdate implements AfterSave, BeforeRemove
{
    public static int $order = 99;
    public function __construct(private EntityManager $em, private TenantResolver $tenants) {}
    public function afterSave(Entity $entity, SaveOptions $options): void { $this->schedule($entity); }
    public function beforeRemove(Entity $entity, RemoveOptions $options): void { $this->schedule($entity); }
    public function afterRelate(Entity $entity, array $options, array $params): void { $this->schedule($entity); }
    public function afterUnrelate(Entity $entity, array $options, array $params): void { $this->schedule($entity); }

    private function schedule(Entity $entity): void
    {
        if ($entity->getEntityType() === 'UserReaction' && $entity->get('parentType') === 'Note') {
            $note = $this->em->getEntityById('Note', $entity->get('parentId'));
            if ($note) $this->schedule($note);
            return;
        }
        $type = $entity->getEntityType();
        $record = $entity;
        if (in_array($type, ['Note', 'ActivityReadState'], true) && in_array($entity->get('parentType'), Access::TYPES, true)) {
            $record = $this->em->getEntityById($entity->get('parentType'), $entity->get('parentId'));
        } elseif (!in_array($type, Access::TYPES, true)) return;
        if (!$record) return;
        $teams = $this->em->getRDBRepository($record->getEntityType())->getRelation($record, 'teams')->find();
        $ids = $record->get('tenantId') ? [$record->get('tenantId')] : $this->tenants->resolveAllFromTeamIds(array_map(fn ($team) => $team->getId(), [...$teams]));
        $oldTeams = $record->getFetched('teamsIds') ?? [];
        $ids = array_values(array_unique(array_filter([...$ids, ...$this->tenants->resolveAllFromTeamIds($oldTeams), $record->getFetched('tenantId')])));
        if (!$ids) return;
        $this->em->createEntity('Job', [
            'name' => BroadcastActivityUpdate::class, 'className' => BroadcastActivityUpdate::class, 'queue' => QueueName::Q0, 'attempts' => 3,
            'data' => (object) ['type' => $record->getEntityType(), 'id' => $record->getId(), 'tenantIds' => $ids],
        ]);
    }
}
