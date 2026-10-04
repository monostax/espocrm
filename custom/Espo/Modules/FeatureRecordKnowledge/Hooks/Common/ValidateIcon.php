<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Hooks\Common;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Tools\RecordIcon\Validator;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Entity> */
class ValidateIcon implements BeforeSave
{
    public static int $order = 10;

    public function __construct(private Metadata $metadata, private Validator $validator) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $attribute = $this->metadata->get(['clientDefs', $entity->getEntityType(), 'recordIconAttribute']);
        if ($attribute && ($entity->isNew() || $entity->isAttributeChanged($attribute))) {
            $this->validator->validate($entity->get($attribute));
        }
    }
}
