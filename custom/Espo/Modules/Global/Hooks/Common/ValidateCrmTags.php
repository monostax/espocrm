<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\Common;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateCrmTags implements BeforeSave
{
    public static int $order = 90;

    public function __construct(private CrmTags $tags) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!in_array($entity->getEntityType(), CrmTags::TYPES, true)) return;
        $attribute = CrmTags::field($entity->getEntityType()) . 'Ids';
        $changed = $entity->isAttributeChanged($attribute);
        if (!$changed && !$entity->isAttributeChanged('tenantId') && !$entity->isAttributeChanged('teamsIds')) return;
        if ($changed || $entity->isNew()) {
            $this->tags->validate($entity, $entity->get($attribute) ?? []);
            $this->tags->preserveHidden($entity);
        }
        if ($entity->isAttributeChanged('tenantId') || $entity->isAttributeChanged('teamsIds')) {
            $this->tags->validateStoredWorkspace($entity);
        }
    }
}
