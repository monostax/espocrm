<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class OpportunityBulkPostAccess
{
    public function __construct(
        private EntityManager $em,
        private User $user,
        private Acl $acl,
        private UserTenantResolver $tenants,
    ) {}

    public function workspace(int $accountId): Entity
    {
        if (!$this->user->isActive() || (!$this->user->isRegular() && !$this->user->isAdmin())) {
            throw new Forbidden();
        }
        // Numeric Chatwoot IDs are not globally unique across platforms. Fail closed on ambiguity.
        $accounts = $this->em->getRDBRepository('ChatwootAccount')
            ->where(['chatwootAccountId' => $accountId])->limit(0, 2)->find();
        if (count($accounts) !== 1) throw new NotFound('Workspace not found.');
        $account = iterator_to_array($accounts, false)[0];
        if (!$account->get('tenantId') || !$account->get('platformId') || !$this->acl->checkEntityRead($account) ||
            (!$this->user->isAdmin() && !$this->tenants->canActForTenant($this->user, $account->get('tenantId')))) {
            throw new Forbidden();
        }
        return $account;
    }

    public function operation(Entity $operation, Entity $account): void
    {
        // Even administrators only see their own operations through this API.
        if ($operation->get('userId') !== $this->user->getId() ||
            $operation->get('accountId') !== $account->getId() ||
            (int) $operation->get('chatwootAccountId') !== (int) $account->get('chatwootAccountId') ||
            $operation->get('tenantId') !== $account->get('tenantId') ||
            $operation->get('platformId') !== $account->get('platformId')) {
            throw new NotFound();
        }
    }

    /** The mural record (Opportunity, Contact, Account or Initiative) a bulk post lands on. */
    public function target(?Entity $record, Entity $account): void
    {
        // Exact workspace match also applies to instance administrators. Same checks as the record inboxes' stream access.
        if (!$record || $record->get('tenantId') !== $account->get('tenantId') ||
            !$this->acl->checkEntityRead($record) || !$this->acl->checkEntityStream($record) ||
            !$this->acl->checkScope('Note', 'read')) {
            throw new Forbidden('Record is unavailable in this workspace.');
        }
        $note = $this->em->getNewEntity('Note');
        $note->set([
            'type' => 'Post', 'parentType' => $record->getEntityType(), 'parentId' => $record->getId(),
            'createdById' => $this->user->getId(),
        ]);
        if (!$this->acl->check('Note', 'create') || !$this->acl->check($note, 'create')) {
            throw new Forbidden('No permission to post in this record.');
        }
    }
}
