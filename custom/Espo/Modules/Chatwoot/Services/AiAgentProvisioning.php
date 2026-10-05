<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** The only CRM entry point for reserving and provisioning managed AI identities. */
class AiAgentProvisioning
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private ChatwootApiClient $apiClient,
        private User $user,
        private UserTenantResolver $tenants,
        private TeamTenantAccess $teamTenantAccess,
    ) {}

    public function create(string $accountId, object $input): Entity
    {
        if (!$this->acl->check('ChatwootAccountUserMembership', 'create')) {
            throw new Forbidden();
        }
        $name = $input->name ?? null;
        $instructions = $input->instructions ?? '';
        $operationId = $input->operationId ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100 ||
            !is_string($instructions) || mb_strlen($instructions) > 10000 ||
            !is_string($operationId) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $operationId)) {
            throw new BadRequest('Invalid agent name, instructions, or operation ID.');
        }
        $hash = hash('sha256', json_encode([$accountId, trim($name), $instructions], JSON_THROW_ON_ERROR));

        // Commit the reservation BEFORE any remote work. A timeout must reuse this identity.
        $identity = $this->entityManager->getTransactionManager()->run(function () use ($accountId, $operationId, $hash, $name, $instructions) {
            $account = $this->authorizeAccount($accountId);
            $this->entityManager->getRDBRepository('ChatwootAccount')
                ->where(['id' => $accountId])->select(['id'])->forUpdate()->findOne();
            $account = $this->authorizeAccount($accountId);
            $existing = $this->entityManager->getRDBRepository('ChatwootMachineIdentity')
                ->where(['operationId' => $operationId])->findOne();
            if ($existing) {
                if ($existing->get('accountId') !== $accountId || $existing->get('payloadHash') !== $hash ||
                    $existing->get('status') === 'retired') {
                    throw new BadRequest('aiAgentOperationConflict');
                }
                return $existing;
            }

            $namespace = $this->reserveNamespace($account);
            // UUID v4, encoded as lowercase hex independently of the editable name.
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $machineId = bin2hex($bytes);
            $email = "agent.{$machineId}@{$namespace}.monostax-ext.com";
            $user = $this->entityManager->createEntity('ChatwootUser', [
                'name' => trim($name),
                'emailAddress' => $email,
                'platformId' => $account->get('platformId'),
                'teamsIds' => $this->teamTenantAccess->resolveTeamIds($account, true),
            ], ['silent' => true, 'managedIdentityProvisioning' => true]);
            $membership = $this->entityManager->createEntity('ChatwootAccountUserMembership', [
                'name' => trim($name),
                'chatwootAccountId' => $accountId,
                'chatwootUserId' => $user->getId(),
                'email' => $email,
                'role' => 'agent',
                'isAI' => true,
                'syncStatus' => 'pending',
                'aiPrompt' => nl2br(htmlspecialchars($instructions, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                'teamsIds' => $this->teamTenantAccess->resolveTeamIds($account, true),
            ], ['silent' => true, 'skipAssignedUserApiKeyProvision' => true]);
            return $this->entityManager->createEntity('ChatwootMachineIdentity', [
                'machineIdentityId' => $machineId,
                'kind' => 'agent',
                'operationId' => $operationId,
                'payloadHash' => $hash,
                'tenantId' => $account->get('tenantId'),
                'accountId' => $accountId,
                'membershipId' => $membership->getId(),
                'chatwootUserId' => $user->getId(),
                'platformId' => $account->get('platformId'),
                'remoteAccountId' => $account->get('chatwootAccountId'),
                'email' => $email,
                'status' => 'pending',
            ]);
        });

        return $this->entityManager->getTransactionManager()->run(function () use ($identity, $accountId) {
            $identity = $this->entityManager->getRDBRepository('ChatwootMachineIdentity')
                ->where(['id' => $identity->getId()])->forUpdate()->findOne();
            $account = $this->authorizeAccount($accountId);
            $membership = $this->entityManager->getEntityById('ChatwootAccountUserMembership', $identity->get('membershipId'));
            $user = $this->entityManager->getEntityById('ChatwootUser', $identity->get('chatwootUserId'));
            if (!$membership || !$user || $identity->get('status') === 'retired' ||
                $account->get('tenantId') !== $identity->get('tenantId') ||
                $account->get('platformId') !== $identity->get('platformId') ||
                (int) $account->get('chatwootAccountId') !== (int) $identity->get('remoteAccountId')) {
                throw new BadRequest('aiAgentOperationConflict');
            }
            if ($identity->get('status') === 'active') return $membership;

            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $identity->get('platformId'));
            $result = $this->apiClient->provisionMachineIdentity(
                $platform->get('backendUrl'), $platform->get('accessToken'), (int) $identity->get('remoteAccountId'), [
                    'machine_identity_id' => $identity->get('machineIdentityId'),
                    'operation_id' => $identity->get('operationId'),
                    'tenant_id' => $identity->get('tenantId'),
                    'crm_account_id' => $accountId,
                    'crm_agent_id' => $membership->getId(),
                    'email' => $identity->get('email'),
                    'name' => $membership->get('name'),
                ]
            );
            if (($result['machine_identity_id'] ?? null) !== $identity->get('machineIdentityId') ||
                ($result['operation_id'] ?? null) !== $identity->get('operationId') ||
                ($result['email'] ?? null) !== $identity->get('email') ||
                (int) ($result['account_id'] ?? 0) !== (int) $identity->get('remoteAccountId') ||
                ($result['confirmed'] ?? false) !== true || empty($result['user_id'])) {
                throw new Error('Chatwoot did not confirm the reserved machine identity.');
            }
            $user->set('chatwootUserId', (int) $result['user_id']);
            $this->entityManager->saveEntity($user, ['silent' => true, 'managedIdentityProvisioning' => true]);
            $identity->set(['remoteUserId' => (int) $result['user_id'], 'status' => 'active']);
            $this->entityManager->saveEntity($identity);
            // Existing agent synchronization stores the Account API's user ID here.
            $membership->set([
                'chatwootAccountUserId' => (int) $result['user_id'],
                'confirmed' => true, 'syncStatus' => 'synced', 'lastSyncedAt' => date('Y-m-d H:i:s'),
                'lastSyncError' => null,
            ]);
            $this->entityManager->saveEntity($membership, ['silent' => true, 'skipAssignedUserApiKeyProvision' => true]);
            return $membership;
        });
    }

    private function authorizeAccount(string $id): Entity
    {
        $account = $this->entityManager->getEntityById('ChatwootAccount', $id);
        if (!$account) throw new NotFound();
        if (!$this->acl->check($account, 'edit')) throw new Forbidden();
        if (!$account->get('tenantId')) throw new BadRequest('aiAgentTenantRequired');
        $derivedTenantId = $this->teamTenantAccess->deriveTenantId($account, 'Chatwoot account', includePersistedTeams: true);
        if ($derivedTenantId !== $account->get('tenantId')) throw new BadRequest('aiAgentTenantRequired');
        if (!$this->user->isAdmin() && !$this->tenants->canActForTenant($this->user, $account->get('tenantId'))) {
            throw new Forbidden();
        }
        $platform = $account->get('platformId')
            ? $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId')) : null;
        if ($account->get('status') !== 'active' || !$account->get('chatwootAccountId') ||
            !$platform?->get('backendUrl') || !$platform->get('accessToken')) {
            throw new BadRequest('aiAgentAccountNotReady');
        }
        return $account;
    }

    private function reserveNamespace(Entity $account): string
    {
        $tenantId = $account->get('tenantId');
        $tenant = $tenantId ? $this->entityManager->getRDBRepository('Tenant')
            ->where(['id' => $tenantId])->select(['id', 'slug'])->forUpdate()->findOne() : null;
        if (!$tenant) throw new BadRequest('aiAgentTenantRequired');
        $repository = $this->entityManager->getRDBRepository('ChatwootIdentityNamespace');
        $existing = $repository->where(['tenantId' => $tenantId])->findOne();
        if ($existing) return $existing->get('namespace');
        $slug = (string) $tenant->get('slug');
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $slug) ||
            $repository->where(['namespace' => $slug])->findOne()) {
            throw new BadRequest('aiAgentTenantRequired');
        }
        $this->entityManager->createEntity('ChatwootIdentityNamespace', ['tenantId' => $tenantId, 'namespace' => $slug]);
        return $slug;
    }
}
