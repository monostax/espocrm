<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\Journey;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Journey> */
class ValidateStructure implements BeforeSave
{
    public static int $order = 20;

    private const LOCKED = [
        'targetEntityType',
        'allowReEnrollment',
    ];

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof Journey || $entity->isNew()) {
            return;
        }

        $status = $entity->getFetched('status') ?: $entity->get('status');

        if (in_array($status, [Journey::STATUS_DRAFT, Journey::STATUS_PAUSED], true)) {
            return;
        }

        foreach (self::LOCKED as $field) {
            if ($entity->isAttributeChanged($field)) {
                throw new BadRequest(
                    "Cannot change '{$field}' while journey status is {$status}."
                );
            }
        }
    }
}
