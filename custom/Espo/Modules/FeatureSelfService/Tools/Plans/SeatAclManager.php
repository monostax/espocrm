<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Core\Acl\Table;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\Query\SelectBuilder;

class SeatAclManager extends \Espo\Core\AclManager
{
    public function checkEntity(User $user, Entity $entity, string $action = Table::ACTION_READ): bool
    {
        if ($entity->hasId() && !$entity->isNew() && !$user->isAdmin() && !$user->isSystem()) {
            $query = SelectBuilder::create()->from($entity->getEntityType())->select('id')->where(['id' => $entity->getId()]);
            if ((new SeatBoundary($this->entityManager))->apply($user, $entity->getEntityType(), $query) &&
                !$this->entityManager->getRDBRepository($entity->getEntityType())->clone($query->build())->findOne()) return false;
        }
        return parent::checkEntity($user, $entity, $action);
    }
}
