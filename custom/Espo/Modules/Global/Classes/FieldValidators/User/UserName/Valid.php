<?php

namespace Espo\Modules\Global\Classes\FieldValidators\User\UserName;

use Espo\Core\FieldValidation\Validator\Data;
use Espo\Core\FieldValidation\Validator\Failure;
use Espo\ORM\Entity;

class Valid extends \Espo\Classes\FieldValidators\User\UserName\Valid
{
    public function validate(Entity $entity, string $field, Data $data): ?Failure
    {
        if ($entity->isApi() || $entity->isSystem()) {
            return parent::validate($entity, $field, $data);
        }

        return filter_var($entity->get('userName'), FILTER_VALIDATE_EMAIL) ? null : Failure::create();
    }
}
