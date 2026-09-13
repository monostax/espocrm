<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\Funnel;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Global\Tools\RecordIcon\Validator;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Entity> */
class ValidateIcon implements BeforeSave
{
    public static int $order = 10;

    public function __construct(private Validator $validator) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew() || $entity->isAttributeChanged('icon')) {
            $this->validator->validate($entity->get('icon'));
        }
    }
}
