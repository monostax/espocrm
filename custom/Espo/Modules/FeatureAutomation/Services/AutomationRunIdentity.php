<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\Error;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Resolves the User identity whose ACL/ownership apply for an automation run.
 *
 * Never silently falls back to system — schedule/entityChange/signal require
 * runAsUser (or active createdBy). Manual may use the clicking user when no
 * runAsUser is configured.
 */
class AutomationRunIdentity
{
    public function __construct(
        private EntityManager $entityManager,
        private ApplicationState $applicationState,
    ) {}

    /**
     * @throws Error
     */
    public function resolve(
        Entity $automation,
        string $triggeredBy,
        ?string $overrideUserId = null,
    ): User {
        if ($overrideUserId !== null && $overrideUserId !== '') {
            // Configured runAs still wins over manual clicker / API override.
            $configuredId = $this->stringId($automation->get('runAsUserId'));
            if ($configuredId !== null) {
                return $this->loadActiveUser($configuredId);
            }

            return $this->loadActiveUser($overrideUserId);
        }

        $runAsId = $this->stringId($automation->get('runAsUserId'));
        if ($runAsId !== null) {
            return $this->loadActiveUser($runAsId);
        }

        if ($triggeredBy === 'manual' && $this->applicationState->isLogged()) {
            $current = $this->applicationState->getUser();
            if ($this->isEligibleActor($current)) {
                return $current;
            }
        }

        $createdById = $this->stringId($automation->get('createdById'));
        if ($createdById !== null) {
            return $this->loadActiveUser($createdById);
        }

        throw new Error(
            'Automation requires runAsUser (or active createdBy) for ACL identity. '
            . 'Set Run-as User on the automation before schedule/entityChange/signal runs.'
        );
    }

    /**
     * @throws Error
     */
    public function fromRun(Entity $run): User
    {
        $id = $this->stringId($run->get('runAsUserId'));
        if ($id === null) {
            throw new Error(
                'AutomationRun ' . (string) $run->getId() . ' missing runAsUserId snapshot.'
            );
        }

        return $this->loadActiveUser($id);
    }

    /**
     * @throws Error
     */
    public function loadActiveUser(string $userId): User
    {
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);
        if (!$user instanceof User) {
            throw new Error("runAsUser '{$userId}' not found.");
        }

        if (!$this->isEligibleActor($user)) {
            throw new Error(
                "runAsUser '{$userId}' is not an eligible active non-system user."
            );
        }

        return $user;
    }

    private function isEligibleActor(User $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        if ($user->isSystem() || $user->isPortal()) {
            return false;
        }

        // regular | admin | super-admin | api
        return $user->isRegular() || $user->isAdmin() || $user->isApi() || $user->isSuperAdmin();
    }

    private function stringId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (string) $value;

        return $id !== '' ? $id : null;
    }
}
