<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Common;

use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\Chatwoot\Services\ManagedIdentityPolicy;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Also cover ORM/field-saver links, including the inverse Team/Tenant/Role side. */
class ProtectManagedCrmRelations
{
    public static int $order = -20;

    public function __construct(private ManagedIdentityPolicy $policy, private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!in_array($entity->getEntityType(), ['Team', 'Tenant', 'Role'], true) ||
            !$entity->isAttributeChanged('usersIds')) return;
        foreach ($entity->get('usersIds') ?? [] as $userId) {
            if (!$this->policy->permitsCrmRelation($entity, 'users', $userId)) {
                throw new Forbidden('Managed CRM users are restricted to their workspace base team.');
            }
        }
    }

    public function afterRelate(Entity $entity, array $options, array $data): void
    {
        $link = $data['relationName'];
        $id = $data['foreignId'];
        if ($this->policy->permitsCrmRelation($entity, $link, $id)) return;
        // Espo only exposes afterRelate ORM hooks. Undo before tenant/team sync hooks run.
        $this->entityManager->getRelation($entity, $link)->unrelateById($id, ['skipHooks' => true]);
        throw new Forbidden('Managed CRM users are restricted to their workspace base team.');
    }

    public function afterMassRelate(Entity $entity, array $options, array $data): void
    {
        $link = $data['relationName'];
        if (!($entity->getEntityType() === 'User' && in_array($link, ['teams', 'tenants', 'roles'], true)) &&
            !(in_array($entity->getEntityType(), ['Team', 'Tenant', 'Role'], true) && $link === 'users')) return;
        $relation = $this->entityManager->getRelation($entity, $link);
        $rejected = false;
        foreach ($relation->find() as $foreignEntity) {
            if ($this->policy->permitsCrmRelation($entity, $link, $foreignEntity->getId())) continue;
            $relation->unrelate($foreignEntity, ['skipHooks' => true]);
            $rejected = true;
        }
        if ($rejected) throw new Forbidden('Managed CRM users are restricted to their workspace base team.');
    }
}
