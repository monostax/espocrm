<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Query;

use Espo\Core\Utils\Config;
use Espo\Entities\Preferences;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/** The user's time zone, for `today` and relative dates, as the browser sends its own in Monostax. */
class UserTimeZone
{
    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Config $config,
    ) {}

    public function get(): ?string
    {
        $preferences = $this->entityManager->getEntityById(Preferences::ENTITY_TYPE, $this->user->getId());
        $zone = $preferences instanceof Preferences ? $preferences->getTimeZone() : null;

        return $zone ?: ($this->config->get('timeZone') ?: null);
    }
}
