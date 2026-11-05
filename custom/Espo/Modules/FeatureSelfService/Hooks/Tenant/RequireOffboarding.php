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

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Hooks\Tenant;

use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureSelfService\Tools\Offboarding\Offboarder;
use Espo\ORM\Entity;

/**
 * A Tenant is removed only by its offboarding, which first ends its WhatsApp
 * sessions and Chatwoot accounts and removes its users and data. Removing
 * the record alone (detail, mass delete, API) left all of that running.
 */
class RequireOffboarding
{
    public static int $order = 1;

    /**
     * @param array<string, mixed> $options
     * @throws Forbidden
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        if (empty($options[Offboarder::REMOVE_OPTION])) {
            throw Forbidden::createWithBody(
                'A workspace is removed by offboarding it, not by deleting the Tenant.',
                Body::create()->withMessageTranslation('removeByOffboarding', 'Tenant'),
            );
        }
    }
}
