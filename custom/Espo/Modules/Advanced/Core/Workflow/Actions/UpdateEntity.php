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
use Espo\Modules\Advanced\Tools\Workflow\Core\SaveContextHelper;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class UpdateEntity extends BaseEntity
{
    /**
     * @throws FormulaError
     * @throws Error
     */
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        $reloadedEntity = $this->entityManager->getEntityById($entity->getEntityType(), $entity->getId());

        $data = $this->getDataToFill($reloadedEntity, $actionData->fields);

        $reloadedEntity->set($data);
        $entity->set($data);

        $formula = $actionData->formula ?? null;

        if ($formula) {
            $this->formulaManager->run($formula, $reloadedEntity, $this->getFormulaVariables());
        }

        foreach ($reloadedEntity->getAttributeList() as $attribute) {
            if ($reloadedEntity->isAttributeChanged($attribute)) {
                $entity->set($attribute, $reloadedEntity->get($attribute));
            }
        }

        $saveOptions = [
            'modifiedById' => 'system',
            'skipWorkflow' => !$this->bpmnProcess,
            'workflowId' => $this->getWorkflowId(),
            'skipAudited' => $entity->isNew(),
            'context' => SaveContextHelper::createDerived($options),
        ];

        $this->entityManager->saveEntity($reloadedEntity, $saveOptions);

        return true;
    }
}
