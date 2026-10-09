<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Common;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\Core\Job\QueueName;
use Espo\Modules\Chatwoot\Jobs\BroadcastActivityUpdate;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
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
    public function afterRelate(Entity $entity, array $options, array $params): void
    {
        if ($entity->getEntityType() === 'ChatwootConversation') {
            $type = ['tasks' => 'Task', 'calls' => 'Call', 'meetings' => 'Meeting', 'appointments' => 'Appointment'][$params['relationName'] ?? ''] ?? null;
            $record = $type && !empty($params['foreignId']) ? $this->em->getEntityById($type, $params['foreignId']) : null;
            if ($record) $this->schedule($record, [['entityType' => 'ChatwootConversation', 'recordId' => $entity->getId()]]);
            return;
        }
        $affected = ($params['relationName'] ?? null) === 'chatwootConversations' && !empty($params['foreignId'])
            ? [['entityType' => 'ChatwootConversation', 'recordId' => $params['foreignId']]] : [];
        $this->schedule($entity, $affected);
    }
    public function afterUnrelate(Entity $entity, array $options, array $params): void { $this->afterRelate($entity, $options, $params); }
    public function afterMassRelate(Entity $entity, array $options, array $params): void
    {
        $link = $params['relationName'] ?? '';
        if ($entity->getEntityType() === 'ChatwootConversation' && in_array($link, ['tasks', 'calls', 'meetings', 'appointments'], true)) {
            foreach ($this->em->getRDBRepository('ChatwootConversation')->getRelation($entity, $link)->find() as $record) {
                $this->schedule($record, [['entityType' => 'ChatwootConversation', 'recordId' => $entity->getId()]]);
            }
            return;
        }
        $this->schedule($entity);
    }

    private function schedule(Entity $entity, array $affected = []): void
    {
        if ($entity->getEntityType() === 'RecordRelation') {
            foreach (['subject', 'object'] as $side) {
                $type = $entity->get($side . 'Type');
                if (!in_array($type, ActivityDiscussion::PARENT_TYPES, true)) continue;
                $record = $this->em->getEntityById($type, $entity->get($side . 'Id'));
                $other = $side === 'subject' ? 'object' : 'subject';
                if ($record) $this->schedule($record, [['entityType' => $entity->get($other . 'Type'), 'recordId' => $entity->get($other . 'Id')]]);
            }
            return;
        }
        if ($entity->getEntityType() === 'UserReaction' && $entity->get('parentType') === 'Note') {
            $note = $this->em->getEntityById('Note', $entity->get('parentId'));
            if ($note) $this->schedule($note);
            return;
        }
        $type = $entity->getEntityType();
        $record = $entity;
        if (in_array($type, ['Note', 'ActivityReadState'], true) && in_array($entity->get('parentType'), ActivityDiscussion::PARENT_TYPES, true)) {
            $record = $this->em->getEntityById($entity->get('parentType'), $entity->get('parentId'));
        } elseif (!in_array($type, ActivityDiscussion::PARENT_TYPES, true)) return;
        if (!$record) return;
        // Private sessions must never enter account-wide activity broadcasts.
        if ($record->getEntityType() === 'AiSession') return;
        $teams = $record->getEntityType() === 'Initiative' ? []
            : $this->em->getRDBRepository($record->getEntityType())->getRelation($record, 'teams')->find();
        $ids = $record->get('tenantId') ? [$record->get('tenantId')] : $this->tenants->resolveAllFromTeamIds(array_map(fn ($team) => $team->getId(), [...$teams]));
        $oldTeams = $record->getFetched('teamsIds') ?? [];
        $ids = array_values(array_unique(array_filter([...$ids, ...$this->tenants->resolveAllFromTeamIds($oldTeams), $record->getFetched('tenantId')])));
        if (!$ids) return;
        $this->em->createEntity('Job', [
            'name' => BroadcastActivityUpdate::class, 'className' => BroadcastActivityUpdate::class, 'queue' => QueueName::Q0, 'attempts' => 3,
            'data' => (object) ['type' => $record->getEntityType(), 'id' => $record->getId(), 'tenantIds' => $ids,
                'affectedRecords' => $this->affectedRecords($record, $affected)],
        ]);
    }

    private function affectedRecords(Entity $record, array $affected): array
    {
        $type = $record->getEntityType();
        $id = $record->getId();
        $affected[] = ['entityType' => $type, 'recordId' => $id];
        // Both sides of a parent move must refresh, including removal from the old list.
        foreach (['get', 'getFetched'] as $method) {
            if ($record->$method('parentType') && $record->$method('parentId')) {
                $affected[] = ['entityType' => $record->$method('parentType'), 'recordId' => $record->$method('parentId')];
            }
        }
        if (in_array($type, ['Task', 'Call', 'Meeting', 'Appointment'], true)) {
            foreach ($this->em->getRDBRepository($type)->getRelation($record, 'chatwootConversations')->find() as $conversation) {
                $affected[] = ['entityType' => 'ChatwootConversation', 'recordId' => $conversation->getId()];
            }
        }
        foreach ($this->em->getRDBRepository('RecordRelation')->where(['status' => 'confirmed', 'OR' => [
            ['subjectType' => $type, 'subjectId' => $id], ['objectType' => $type, 'objectId' => $id],
        ]])->find() as $relation) {
            foreach (['subject', 'object'] as $side) {
                $affected[] = ['entityType' => $relation->get($side . 'Type'), 'recordId' => $relation->get($side . 'Id')];
            }
        }
        return array_values(array_unique($affected, SORT_REGULAR));
    }
}
