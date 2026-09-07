<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\AclPortal\Note;

use Espo\Core\Acl\ScopeData;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Portal\Acl\DefaultAccessChecker;
use Espo\Core\Portal\AclManager;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\ORM\Entity;

class AccessChecker extends \Espo\Classes\AclPortal\Note\AccessChecker
{
    public function __construct(
        DefaultAccessChecker $defaultAccessChecker,
        private AclManager $aclManager,
        private EntityManager $entityManager,
        Config $config,
        private OpportunityAccess $access,
    ) {
        parent::__construct($defaultAccessChecker, $aclManager, $entityManager, $config);
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return !in_array($entity->get('type'), OpportunityStreamEvents::EVENT_TYPES, true) &&
            $this->access->canReadNote($user, $entity) && parent::checkEntityCreate($user, $entity, $data);
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        if (!$this->access->canReadNote($user, $entity) || !parent::checkEntityRead($user, $entity, $data)) {
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
            $this->access->canReadNote($user, $entity) && parent::checkEntityEdit($user, $entity, $data);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data) && parent::checkEntityDelete($user, $entity, $data);
    }
}
