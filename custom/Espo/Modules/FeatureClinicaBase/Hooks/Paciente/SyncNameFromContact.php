<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeatureClinicaBase\Hooks\Paciente;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class SyncNameFromContact
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $contactId = $entity->get('contactId');
        if (!$contactId) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('contactId')) {
            return;
        }

        $contact = $this->entityManager->getEntityById('Contact', $contactId);
        if (!$contact) {
            $GLOBALS['log']->warning(
                "FeatureClinicaBase: SyncNameFromContact - Contact '{$contactId}' not found for Paciente '{$entity->getId()}'"
            );
            return;
        }

        $entity->set('name', $contact->get('name'));
    }
}
