<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Services;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Ownership is independent of agent selection and any future sharing grants. */
class Access
{
    public function __construct(private EntityManager $em, private UserTenantResolver $tenants, private AclManager $acl) {}

    public function owner(User $user, Entity $session): bool
    {
        if (!$user->isActive() || (!$user->isRegular() && !$user->isAdmin()) || $session->get('assignedUserId') !== $user->getId()) return false;
        $account = $this->em->getEntityById('ChatwootAccount', (string) $session->get('chatwootAccountId'));
        return $account && $account->get('tenantId') && $account->get('platformId') &&
            $account->get('tenantId') === $session->get('tenantId') &&
            ($user->isAdmin() || $this->tenants->canActForTenant($user, (string) $session->get('tenantId'))) &&
            $this->acl->checkEntityRead($user, $account) && (bool) $this->em->getRDBRepository('ChatwootAccountUserMembership')->join('chatwootUser')->where([
                'chatwootAccountId' => $account->getId(), 'isAI' => false,
                'chatwootUser.deleted' => false, 'chatwootUser.assignedUserId' => $user->getId(),
                'chatwootUser.platformId' => $account->get('platformId'),
            ])->findOne();
    }

    public function agent(User $owner, Entity $session, string $id): Entity
    {
        $membership = $this->em->getEntityById('ChatwootAccountUserMembership', $id);
        $account = $this->em->getEntityById('ChatwootAccount', (string) $session->get('chatwootAccountId'));
        $identity = $membership ? $this->em->getEntityById('ChatwootUser', (string) $membership->get('chatwootUserId')) : null;
        $user = $identity ? $this->em->getEntityById('User', (string) $identity->get('assignedUserId')) : null;
        if (!$this->owner($owner, $session) || !$membership?->get('isAI') || !$account ||
            $membership->get('chatwootAccountId') !== $account->getId() || !$identity ||
            $identity->get('platformId') !== $account->get('platformId') || !$user instanceof User || !$user->isActive() ||
            !$this->acl->checkEntityRead($owner, $membership) ||
            !$this->acl->checkField($owner, 'AiSession', 'aiAgentMembership') ||
            !$this->acl->checkField($owner, 'ChatwootAccountUserMembership', 'name') ||
            !$this->acl->checkField($owner, 'ChatwootAccountUserMembership', 'aiPrompt') ||
            !$this->acl->checkField($owner, 'ChatwootAccountUserMembership', 'isAI')) throw new Forbidden('AI agent is unavailable in this workspace.');
        return $membership;
    }
}
