<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\Error;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Resolves the User identity whose ACL/ownership apply for a journey run.
 *
 * Never silently falls back to system. Prefer Journey.runAsUser, then
 * activate/manual clicker when eligible, then active createdBy.
 */
class JourneyRunIdentity
{
    public function __construct(
        private EntityManager $entityManager,
        private ApplicationState $applicationState,
    ) {}

    /**
     * @param string $context activate|enroll|transition|manual
     * @throws Error
     */
    public function resolve(
        Entity $journey,
        string $context = 'enroll',
        ?string $overrideUserId = null,
    ): User {
        if ($overrideUserId !== null && $overrideUserId !== '') {
            $configuredId = $this->stringId($journey->get('runAsUserId'));
            if ($configuredId !== null) {
                return $this->loadActiveUser($configuredId);
            }

            return $this->loadActiveUser($overrideUserId);
        }

        $runAsId = $this->stringId($journey->get('runAsUserId'));
        if ($runAsId !== null) {
            return $this->loadActiveUser($runAsId);
        }

        if (
            in_array($context, ['activate', 'manual'], true)
            && $this->applicationState->isLogged()
        ) {
            $current = $this->applicationState->getUser();
            if ($this->isEligibleActor($current)) {
                return $current;
            }
        }

        $createdById = $this->stringId($journey->get('createdById'));
        if ($createdById !== null) {
            return $this->loadActiveUser($createdById);
        }

        throw new Error(
            'Journey requires runAsUser (or active createdBy) for ACL identity. '
            . 'Set Run-as User on the journey before activate / continuous enrollment.'
        );
    }

    /**
     * Prefer JourneyRecord snapshot; fall back to journey-level resolve for
     * legacy records created before runAsUser existed.
     *
     * @throws Error
     */
    public function fromRecord(Entity $record, ?Entity $journey = null): User
    {
        $id = $this->stringId($record->get('runAsUserId'));
        if ($id !== null) {
            return $this->loadActiveUser($id);
        }

        if ($journey !== null) {
            return $this->resolve($journey, 'transition');
        }

        $journeyId = $this->stringId($record->get('journeyId'));
        if ($journeyId === null) {
            throw new Error(
                'JourneyRecord ' . (string) $record->getId() . ' missing runAsUserId snapshot and journeyId.'
            );
        }

        $loaded = $this->entityManager->getEntityById('Journey', $journeyId);
        if (!$loaded) {
            throw new Error(
                'JourneyRecord ' . (string) $record->getId() . ' missing runAsUserId; journey not found.'
            );
        }

        return $this->resolve($loaded, 'transition');
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
