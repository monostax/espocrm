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

namespace Espo\Modules\FeatureClinica\Hooks\Profissional;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ValidateCRM
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        $this->validateCrmRequirement($entity);
        $this->validateUserId($entity);
    }

    /**
     * @throws BadRequest
     */
    private function validateCrmRequirement(Entity $entity): void
    {
        $tipoProfissionalId = $entity->get('tipoProfissionalId');
        if (!$tipoProfissionalId) {
            return;
        }

        $tipoProfissional = $this->entityManager->getEntityById('TipoProfissional', $tipoProfissionalId);
        if (!$tipoProfissional) {
            return;
        }

        if (!$tipoProfissional->get('requerCRM')) {
            return;
        }

        $crm = $entity->get('crm');
        $crmUf = $entity->get('crmUf');

        if (empty($crm) || empty($crmUf)) {
            throw new BadRequest('CRM e CRM UF são obrigatórios para este tipo de profissional.');
        }

        $where = [
            'crm' => $crm,
            'crmUf' => $crmUf,
        ];

        if (!$entity->isNew()) {
            $where['id!='] = $entity->getId();
        }

        $existing = $this->entityManager
            ->getRDBRepository('Profissional')
            ->where($where)
            ->findOne();

        if ($existing) {
            throw new BadRequest('Já existe um profissional com este CRM e UF.');
        }
    }

    /**
     * @throws BadRequest
     */
    private function validateUserId(Entity $entity): void
    {
        $userId = $entity->get('userId');
        if (!$userId) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('userId')) {
            return;
        }

        $user = $this->entityManager->getEntityById('User', $userId);
        if (!$user) {
            throw new BadRequest('Usuário informado não encontrado.');
        }
    }
}
