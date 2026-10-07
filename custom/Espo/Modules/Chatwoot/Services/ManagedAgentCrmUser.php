<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\Util;
use Espo\Entities\User;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Internal provisioning shared by new agents, retries, and the legacy backfill. */
class ManagedAgentCrmUser
{
    public function __construct(
        private EntityManager $entityManager,
        private TeamTenantAccess $teamTenantAccess,
    ) {}

    public function ensure(string $identityId): User
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($identityId) {
            $identity = $this->entityManager->getRDBRepository('ChatwootMachineIdentity')
                ->where(['id' => $identityId])->forUpdate()->findOne();
            if (!$identity || $identity->get('status') !== 'active') {
                throw new Conflict('Only an active managed identity can provision a CRM user.');
            }
            $account = $this->entityManager->getEntityById('ChatwootAccount', $identity->get('accountId'));
            $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $identity->get('chatwootUserId'));
            $membership = $this->entityManager->getEntityById('ChatwootAccountUserMembership', $identity->get('membershipId'));
            $this->assertBinding($identity, $account, $chatwootUser, $membership);

            $tenant = $this->entityManager->getEntityById('Tenant', $identity->get('tenantId'));
            $baseTeamId = $tenant?->get('baseUserTeamId');
            $teamIds = $this->teamTenantAccess->resolveTeamIds($account, true);
            if (!$baseTeamId || !in_array($baseTeamId, $teamIds, true) ||
                $this->teamTenantAccess->deriveTenantId($account, 'Chatwoot account', includePersistedTeams: true) !== $identity->get('tenantId')) {
                throw new Conflict('The managed agent must belong to its workspace base team.');
            }

            $crmUserId = $identity->get('crmUserId');
            if ($crmUserId) {
                $user = $this->entityManager->getEntityById('User', $crmUserId);
                if (!$user instanceof User || !$user->isApi() || !$user->isActive() ||
                    $user->getUserName() !== $identity->get('email') || $user->get('emailAddress') !== $identity->get('email')) {
                    throw new Conflict('The managed CRM user no longer matches its protected binding.');
                }
            } else {
                // Never adopt a human/API user from an email match or an editable assignedUser link.
                if ($chatwootUser->get('assignedUserId') || $this->entityManager->getRDBRepository('User')->where([
                    'OR' => [['userName' => $identity->get('email')], ['emailAddress' => $identity->get('email')]],
                ])->findOne()) {
                    throw new Conflict('The managed CRM identity is already claimed.');
                }
                $parts = preg_split('/\s+/u', trim((string) $chatwootUser->get('name')), 2);
                $user = $this->entityManager->createEntity('User', [
                    'userName' => $identity->get('email'),
                    'emailAddress' => $identity->get('email'),
                    'firstName' => $parts[0],
                    'lastName' => $parts[1] ?? '',
                    'type' => User::TYPE_API,
                    'authMethod' => 'ApiKey',
                    'isActive' => true,
                    'defaultTeamId' => $baseTeamId,
                    'teamsIds' => [$baseTeamId],
                ], ['silent' => true, 'managedIdentityProvisioning' => true, 'skipChatwootProvisioning' => true]);
                $identity->set('crmUserId', $user->getId());
                $this->entityManager->saveEntity($identity);
                // Stock API-user persistence uses the username as lastName on creation.
                $user = $this->entityManager->getEntityById('User', $user->getId());
                $user->set(['firstName' => $parts[0], 'lastName' => $parts[1] ?? '']);
                $this->entityManager->saveEntity($user, ['silent' => true]);
                // Fail atomically if credential creation fails; a successful agent needs both links and access.
                $this->entityManager->createEntity('UserApiKey', [
                    'name' => 'Chat AI Agent — ' . mb_substr((string) $chatwootUser->get('name'), 0, 80),
                    'description' => 'Managed AI agent membership ' . $membership->getId(),
                    'userId' => $user->getId(),
                    'apiKey' => Util::generateApiKey(),
                    'isActive' => true,
                ], ['silent' => true]);
            }

            if ($chatwootUser->get('assignedUserId') && $chatwootUser->get('assignedUserId') !== $user->getId()) {
                throw new Conflict('The Chatwoot user is linked to another CRM user.');
            }
            if ($chatwootUser->get('assignedUserId') !== $user->getId()) {
                $chatwootUser->set('assignedUserId', $user->getId());
                $this->entityManager->saveEntity($chatwootUser, ['silent' => true]);
            }
            $user = $this->entityManager->getEntityById('User', $user->getId());
            if ($user->get('emailAddress') !== $identity->get('email') ||
                $user->getTeamIdList() !== [$baseTeamId] || $user->getLinkMultipleIdList('roles') !== []) {
                throw new Conflict('The managed CRM user must retain its workspace membership and non-admin role.');
            }
            return $user;
        });
    }

    /** Called inside the identity retirement transaction; preserve attribution but revoke CRM access. */
    public function retire(Entity $identity): void
    {
        if (!$identity->get('crmUserId')) return;
        $user = $this->entityManager->getEntityById('User', $identity->get('crmUserId'));
        if ($user) {
            $user->set('isActive', false);
            $this->entityManager->saveEntity($user, ['silent' => true]);
        }
        foreach ($this->entityManager->getRDBRepository('UserApiKey')->where([
            'userId' => $identity->get('crmUserId'), 'isActive' => true,
        ])->find() as $key) {
            $key->set('isActive', false);
            $this->entityManager->saveEntity($key, ['silent' => true]);
        }
    }

    private function assertBinding(Entity $identity, ?Entity $account, ?Entity $user, ?Entity $membership): void
    {
        if (!$account || !$user || !$membership || $account->get('status') !== 'active' ||
            $account->get('tenantId') !== $identity->get('tenantId') ||
            $account->get('platformId') !== $identity->get('platformId') ||
            (int) $account->get('chatwootAccountId') !== (int) $identity->get('remoteAccountId') ||
            $user->get('platformId') !== $identity->get('platformId') ||
            !$identity->get('remoteUserId') || (int) $user->get('chatwootUserId') !== (int) $identity->get('remoteUserId') ||
            $user->get('emailAddress') !== $identity->get('email') ||
            $membership->get('chatwootUserId') !== $user->getId() || $membership->get('chatwootAccountId') !== $account->getId()) {
            throw new Conflict('The managed agent ownership does not match its protected binding.');
        }
    }
}
