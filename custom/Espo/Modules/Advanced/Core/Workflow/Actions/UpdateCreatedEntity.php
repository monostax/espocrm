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
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\ORM\Entity as CoreEntity;
use stdClass;

class UpdateCreatedEntity extends BaseEntity
{
    /**
     * @throws FormulaError
     * @throws Error
     */
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        if (empty($actionData->target)) {
            return false;
        }

        $target = $actionData->target;

        $targetEntity = $this->getCreatedEntity($target);

        if (!$targetEntity) {
            return false;
        }

        if (property_exists($actionData, 'fields')) {
            $data = $this->getDataToFill($targetEntity, $actionData->fields);
            $targetEntity->set($data);
        }

        if (!empty($actionData->formula)) {
            $this->formulaManager->run(
                $actionData->formula,
                $targetEntity,
                $this->getFormulaVariables()
            );
        }

        if (!$targetEntity->has('modifiedById')) {
            $targetEntity->set('modifiedByName', 'System');
        }

        $this->entityManager->saveEntity($targetEntity, ['modifiedById' => 'system']);

        return true;
    }
}
