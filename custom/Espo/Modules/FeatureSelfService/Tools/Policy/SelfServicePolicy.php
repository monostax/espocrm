<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Tools\Policy;

use Espo\ORM\EntityManager;

/**
 * Whether self-service may create anything new right now.
 *
 *     enabled = deployment switch on AND runtime pause off
 *
 * - The deployment switch is the CRM_SELF_SERVICE_ENABLED environment
 *   variable, read from the process environment on every call. Only the exact
 *   string `true` enables it; missing or anything else is off. It is not
 *   copied into data/config.php, so removing the variable cannot leave an
 *   earlier `true` behind.
 * - The runtime pause is one database row, so every web and worker process
 *   sees a pause on its next check without a rollout.
 *
 * Callers check at request and provisioning-step boundaries. Existing
 * workspaces, logins and billing are not governed by this policy.
 */
class SelfServicePolicy
{
    public const ENV = 'CRM_SELF_SERVICE_ENABLED';
    public const CONTROL_ID = 'global';

    public const STATE_DISABLED = 'disabled';
    public const STATE_PAUSED = 'paused';
    public const STATE_ENABLED = 'enabled';

    private const CONTROL = 'SelfServiceControl';

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public static function isDeploymentEnabled(): bool
    {
        return getenv(self::ENV) === 'true';
    }

    /**
     * @return self::STATE_*
     */
    public function state(): string
    {
        if (!self::isDeploymentEnabled()) {
            return self::STATE_DISABLED;
        }

        return $this->isPaused() ? self::STATE_PAUSED : self::STATE_ENABLED;
    }

    public function isEnabled(): bool
    {
        return $this->state() === self::STATE_ENABLED;
    }

    /**
     * @throws SelfServiceUnavailable
     */
    public function assertEnabled(): void
    {
        $state = $this->state();

        if ($state !== self::STATE_ENABLED) {
            throw new SelfServiceUnavailable($state);
        }
    }

    public function isPaused(): bool
    {
        return (bool) $this->entityManager->getEntityById(self::CONTROL, self::CONTROL_ID)?->get('paused');
    }

    public function pauseReason(): ?string
    {
        return $this->entityManager->getEntityById(self::CONTROL, self::CONTROL_ID)?->get('reason');
    }

    public function pause(string $reason, ?string $userId = null): void
    {
        $this->write(true, $reason, $userId);
    }

    public function resume(?string $userId = null): void
    {
        $this->write(false, null, $userId);
    }

    private function write(bool $paused, ?string $reason, ?string $userId): void
    {
        $values = [
            'paused' => $paused,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'changedById' => $userId,
            'changedAt' => gmdate('Y-m-d H:i:s'),
        ];

        $control = $this->entityManager->getEntityById(self::CONTROL, self::CONTROL_ID);

        if (!$control) {
            $this->entityManager->createEntity(self::CONTROL, ['id' => self::CONTROL_ID, ...$values], ['silent' => true]);

            return;
        }

        $control->set($values);
        $this->entityManager->saveEntity($control, ['silent' => true]);
    }
}
