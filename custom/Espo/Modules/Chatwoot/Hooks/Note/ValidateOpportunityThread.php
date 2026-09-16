<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Note;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateOpportunityThread implements BeforeSave
{
    public static int $order = 1;

    public function __construct(private EntityManager $entityManager, private Acl $acl, private User $user, private OpportunityAccess $access) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew()) {
            if ($entity->isAttributeChanged('opportunityThreadRootId')) {
                throw new BadRequest('A post cannot be moved between threads.');
            }
            if ($entity->getFetched('opportunityPostDeleted') && $entity->isAttributeChanged('post')) {
                throw new BadRequest('A deleted post cannot be edited.');
            }
            return;
        }
        $rootId = $entity->get('opportunityThreadRootId');
        if (!$rootId) {
            return;
        }
        $root = $this->entityManager->getRDBRepository('Note')->where(['id' => $rootId])->forUpdate()->findOne();
        if (!$root instanceof Note || $root->getType() !== Note::TYPE_POST || $root->getParentType() !== 'Opportunity' ||
            $entity->get('parentType') !== 'Opportunity' || $entity->get('type') !== Note::TYPE_POST ||
            $entity->get('parentId') !== $root->getParentId() || $root->get('opportunityThreadRootId')) {
            throw new BadRequest('The thread root must be a top-level post on the same opportunity.');
        }
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->access->canReadNote($this->user, $root) || !$this->acl->checkEntityRead($root)) {
            throw new Forbidden();
        }
        $entity->set('isInternal', $root->get('isInternal'));
    }
}
