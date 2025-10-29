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

namespace Espo\Modules\Advanced\Core\Workflow\Conditions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Entity as CoreEntity;
use stdClass;

class Equals extends Base
{
    /**
     * @param mixed $fieldValue
     * @throws Error
     */
    protected function compare($fieldValue): bool
    {
        $subjectValue = $this->getSubjectValue();

        return ($fieldValue == $subjectValue);
    }

    protected function compareComplex(CoreEntity $entity, stdClass $condition): bool
    {
        if (empty($condition->fieldValueMap)) {
            return false;
        }

        $fieldValueMap = $condition->fieldValueMap;

        foreach ($fieldValueMap as $field => $value) {
            $itemValue = $this->fieldValueHelper->getValue(
                entity: $entity,
                path: $field,
                createdEntitiesData: $this->createdEntitiesData,
            );

            if ($itemValue !== $value) {
                return false;
            }
        }

        return true;
    }
}
