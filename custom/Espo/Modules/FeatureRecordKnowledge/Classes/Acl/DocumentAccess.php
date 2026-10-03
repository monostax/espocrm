<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Classes\Acl;

use Espo\Core\Acl\AccessEntityReadChecker;
use Espo\Core\Acl\AccessEntityEditChecker;
use Espo\Core\Acl\AccessEntityDeleteChecker;
use Espo\Core\Acl\AccessEntityStreamChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class DocumentAccess implements AccessEntityReadChecker, AccessEntityEditChecker, AccessEntityDeleteChecker, AccessEntityStreamChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(DefaultAccessChecker $defaultAccessChecker, private EntityManager $em, private AclManager $acl)
    {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    private function parentAllowed(User $user, Entity $document, string $action): bool
    {
        $type = $document->get('knowledgeRecordType');
        if (!$type) return true;
        $parent = $this->em->getEntityById($type, $document->get('knowledgeRecordId'));
        return $parent && !$parent->get('knowledgeRecordType') && $this->acl->checkEntity($user, $parent, $action);
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityRead($user, $entity, $data) && $this->parentAllowed($user, $entity, 'read');
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityEdit($user, $entity, $data) && $this->parentAllowed($user, $entity, 'edit');
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return !$entity->get('knowledgeRecordType') && $this->defaultAccessChecker->checkEntityDelete($user, $entity, $data);
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityStream($user, $entity, $data) && $this->parentAllowed($user, $entity, 'read');
    }
}
