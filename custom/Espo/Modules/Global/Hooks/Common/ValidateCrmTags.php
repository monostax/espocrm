<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\Common;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateCrmTags implements BeforeSave
{
    public static int $order = 90;

    public function __construct(private CrmTags $tags, private EntityManager $em) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!in_array($entity->getEntityType(), CrmTags::TYPES, true)) return;
        $changed = $entity->isAttributeChanged('tagsIds');
        if (!$changed && !$entity->isAttributeChanged('tenantId') && !$entity->isAttributeChanged('teamsIds')) return;
        $ids = $changed || $entity->isNew() ? ($entity->get('tagsIds') ?? []) : array_map(
            fn ($tag) => $tag->getId(), [...$this->em->getRDBRepository($entity->getEntityType())->getRelation($entity, 'tags')->find()]
        );
        $this->tags->validate($entity, $ids);
    }
}
