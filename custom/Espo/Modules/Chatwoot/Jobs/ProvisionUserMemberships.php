<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootAccountMembershipOrchestrator;
use Espo\ORM\EntityManager;

/** Retryable provisioning; re-resolves access instead of trusting queued teams. */
class ProvisionUserMemberships implements Job
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootAccountMembershipOrchestrator $orchestrator,
    ) {}

    public function run(Data $data): void
    {
        $id = $data->get('userId');
        if (!is_string($id) || $id === '') {
            return;
        }

        $user = $this->entityManager->getEntityById('User', $id);
        if (!$user instanceof User || !$user->isActive() ||
            !in_array($user->getType(), [User::TYPE_REGULAR, User::TYPE_ADMIN], true)) {
            return;
        }

        $teamIds = $user->getLinkMultipleIdList('teams');
        if (!$teamIds) {
            return;
        }

        // Tenant.users adds the tenant base team through SyncUserTeams. Keep the
        // actual account-team boundary: sharing a tenant alone is not enough to
        // grant access to every account owned by that tenant.
        $accounts = $this->entityManager->getRDBRepository('ChatwootAccount')
            ->join('teams')->where(['teams.id' => $teamIds, 'status' => 'active'])
            ->distinct()->find();

        $failure = null;
        foreach ($accounts as $account) {
            try {
                // Null preserves an existing role; new memberships are agents.
                $this->orchestrator->ensureUserMembership($account, $user, null);
            } catch (\Throwable $e) {
                $failure = $e;
            }
        }

        if ($failure) {
            throw $failure;
        }
    }
}
