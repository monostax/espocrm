<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Acl\Attachment;

use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Core\ORM\EntityManager;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAttachmentAccess;
use Espo\ORM\Entity;

class AccessChecker extends \Espo\Classes\Acl\Attachment\AccessChecker
{
    public function __construct(
        DefaultAccessChecker $defaultAccessChecker,
        AclManager $aclManager,
        EntityManager $entityManager,
        private OpportunityAttachmentAccess $access,
    ) {
        parent::__construct($defaultAccessChecker, $aclManager, $entityManager);
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->access->check($user, $entity) ?? parent::checkEntityRead($user, $entity, $data);
    }
}
