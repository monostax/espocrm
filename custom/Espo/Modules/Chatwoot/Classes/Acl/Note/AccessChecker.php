<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Acl\Note;

use Espo\Core\Acl\AccessEntityCREDChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class AccessChecker implements AccessEntityCREDChecker
{
    public function __construct(
        private \Espo\Classes\Acl\Note\AccessChecker $base,
        private AclManager $aclManager,
        private EntityManager $entityManager,
        private OpportunityAccess $opportunityAccess,
    ) {}

    public function check(User $user, ScopeData $data): bool
    {
        return $this->base->check($user, $data);
    }

    public function checkCreate(User $user, ScopeData $data): bool
    {
        return $this->base->checkCreate($user, $data);
    }

    public function checkRead(User $user, ScopeData $data): bool
    {
        return $this->base->checkRead($user, $data);
    }

    public function checkEdit(User $user, ScopeData $data): bool
    {
        return $this->base->checkEdit($user, $data);
    }

    public function checkDelete(User $user, ScopeData $data): bool
    {
        return $this->base->checkDelete($user, $data);
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        // Events are written by the integration, not by clients impersonating messages.
        return !in_array($entity->get('type'), OpportunityStreamEvents::EVENT_TYPES, true) &&
            $this->opportunityAccess->canReadNote($user, $entity) &&
            $this->base->checkEntityCreate($user, $entity, $data);
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        if (!$this->opportunityAccess->canReadNote($user, $entity) ||
            !$this->base->checkEntityRead($user, $entity, $data)) {
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
            $this->opportunityAccess->canReadNote($user, $entity) &&
            $this->base->checkEntityEdit($user, $entity, $data);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data) &&
            $this->base->checkEntityDelete($user, $entity, $data);
    }
}
