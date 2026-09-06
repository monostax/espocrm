<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Acl\Note;

use Espo\Core\Acl\AccessEntityCREDChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class AccessChecker implements AccessEntityCREDChecker
{
    public function __construct(
        private \Espo\Classes\Acl\Note\AccessChecker $base,
        private AclManager $aclManager,
        private EntityManager $entityManager,
    ) {}

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        // Events are written by the integration, not by clients impersonating messages.
        return !in_array($entity->get('type'), OpportunityStreamEvents::EVENT_TYPES, true) &&
            $this->base->checkEntityCreate($user, $entity, $data);
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        if (!$this->base->checkEntityRead($user, $entity, $data)) {
            return false;
        }
        if (!in_array($entity->get('type'), OpportunityStreamEvents::EVENT_TYPES, true)) {
            return true;
        }

        $scopes = $entity->get('type') === OpportunityStreamEvents::MESSAGE_RECEIVED
            ? ['ChatwootConversation'] : ['Task', 'Meeting', 'Call'];
        $related = in_array($entity->get('relatedType'), $scopes, true) && $entity->get('relatedId')
            ? $this->entityManager->getEntityById($entity->get('relatedType'), $entity->get('relatedId')) : null;

        return $related && $this->aclManager->checkEntityRead($user, $related);
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return !in_array($entity->get('type'), OpportunityStreamEvents::EVENT_TYPES, true) &&
            $this->base->checkEntityEdit($user, $entity, $data);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data) &&
            $this->base->checkEntityDelete($user, $entity, $data);
    }
}
