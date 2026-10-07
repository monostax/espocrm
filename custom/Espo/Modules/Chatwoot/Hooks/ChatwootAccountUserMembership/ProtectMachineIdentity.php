<?php

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccountUserMembership;

use Espo\Modules\Chatwoot\Services\ManagedIdentityPolicy;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ManagedAgentCrmUser;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ProtectMachineIdentity
{
    public static int $order = 0;

    public function __construct(
        private ManagedIdentityPolicy $policy,
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private ManagedAgentCrmUser $managedCrmUser,
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $this->policy->assertMembership($entity);
        if (!empty($options['silent']) || $entity->isNew() || !$entity->isAttributeChanged('name')) return;
        $identity = $this->policy->forUser((string) $entity->get('chatwootUserId'));
        if (!$identity || $identity->get('status') !== 'active') return;
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $identity->get('platformId'));
        $this->apiClient->provisionMachineIdentity(
            $platform->get('backendUrl'), $platform->get('accessToken'), (int) $identity->get('remoteAccountId'), [
                'machine_identity_id' => $identity->get('machineIdentityId'),
                'operation_id' => $identity->get('operationId'),
                'tenant_id' => $identity->get('tenantId'),
                'crm_account_id' => $identity->get('accountId'),
                'crm_agent_id' => $identity->get('membershipId'),
                'email' => $identity->get('email'),
                'name' => $entity->get('name'),
            ]
        );
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        $identity = $this->policy->forUser((string) $entity->get('chatwootUserId'));
        if (!$identity) return;
        $this->entityManager->getTransactionManager()->run(function () use ($identity, $options) {
            $identity = $this->entityManager->getRDBRepository('ChatwootMachineIdentity')
                ->where(['id' => $identity->getId()])->forUpdate()->findOne();
            if ($identity->get('status') === 'retired') {
                $this->managedCrmUser->retire($identity);
                return;
            }
            if (empty($options['skipChatwootSync']) && empty($options['cascadeParent'])) {
                $platform = $this->entityManager->getEntityById('ChatwootPlatform', $identity->get('platformId'));
                $this->apiClient->retireMachineIdentity(
                    $platform->get('backendUrl'), $platform->get('accessToken'), (int) $identity->get('remoteAccountId'), [
                        'machine_identity_id' => $identity->get('machineIdentityId'),
                        'operation_id' => $identity->get('operationId'),
                        'tenant_id' => $identity->get('tenantId'),
                        'crm_account_id' => $identity->get('accountId'),
                        'crm_agent_id' => $identity->get('membershipId'),
                        'email' => $identity->get('email'),
                    ]
                );
            }
            $identity->set('status', 'retired');
            $this->entityManager->saveEntity($identity);
            $this->managedCrmUser->retire($identity);
        });
    }
}
