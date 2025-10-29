<?php
/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2025 EspoCRM, Inc.
 *
 * License ID: c4060ef13557322b374635a5ad844ab2
 ************************************************************************************/

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\Advanced\Tools\Workflow\Core\SaveContextHelper;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class UnrelateFromEntity extends BaseEntity
{
    /**
     * @throws Error
     */
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        if (empty($actionData->entityId) || empty($actionData->link)) {
            throw new Error('Workflow['.$this->getWorkflowId().']: Bad params defined for UnrelateFromEntity');
        }

        $foreignEntityType = $entity->getRelationParam($actionData->link, 'entity');

        if (!$foreignEntityType) {
            throw new Error('Workflow['.$this->getWorkflowId().
                ']: Could not find foreign entity type for UnrelateFromEntity');
        }

        $foreignEntity = $this->entityManager->getEntityById($foreignEntityType, $actionData->entityId);

        if (!$foreignEntity) {
            throw new Error('Workflow['.$this->getWorkflowId().
                ']: Could not find foreign entity for UnrelateFromEntity');
        }

        $relateOptions = [
            'context' => SaveContextHelper::obtainFromRawOptions($options),
        ];

        $this->entityManager
            ->getRDBRepository($entity->getEntityType())
            ->getRelation($entity, $actionData->link)
            ->unrelate($foreignEntity, $relateOptions);

        if ($entity->hasLinkMultipleField($actionData->link)) {
            $entity->loadLinkMultipleField($actionData->link);
        }

        return true;
    }
}
