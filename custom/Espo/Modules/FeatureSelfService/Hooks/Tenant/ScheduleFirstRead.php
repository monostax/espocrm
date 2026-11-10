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

namespace Espo\Modules\FeatureSelfService\Hooks\Tenant;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureSelfService\Tools\Onboarding\FirstRead;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceTrial;
use Espo\ORM\Entity;
use Throwable;

/**
 * A self-service workspace's onboarding just opened (its newest history is in, or it has none): schedule its first
 * read. Onboarding opens once, so this schedules once.
 */
class ScheduleFirstRead
{
    public static int $order = 60;

    public function __construct(
        private FirstRead $firstRead,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if ($entity->isNew() || !$entity->get('plan') ||
            $entity->get('onboardingStep') !== WorkspaceTrial::STEP_DONE ||
            !$entity->isAttributeChanged('onboardingStep')) {
            return;
        }

        try {
            $this->firstRead->schedule((string) $entity->getId());
        } catch (Throwable $e) {
            // Never fail the onboarding over it; the deck still analyses its cards when opened.
            $this->log->error("SelfService: could not schedule the first read of tenant {$entity->getId()}: " . $e->getMessage());
        }
    }
}
