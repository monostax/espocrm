<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\RecordHooks;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Core\Record\Hook\UnlinkHook;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\Entity;

class ValidateCrmTagLink implements LinkHook, UnlinkHook
{
    public function __construct(private CrmTags $tags, private Acl $acl) {}

    public function process(Entity $entity, string $link, Entity $foreignEntity): void
    {
        if ($entity->getEntityType() === 'CrmTag' && $link === 'teams' && $entity->get('visibility') === 'personal') {
            throw new Forbidden('Personal tags cannot be assigned to teams.');
        }
        if (in_array($entity->getEntityType(), CrmTags::TYPES, true) && $link === CrmTags::field($entity->getEntityType())) {
            $this->tags->validate($entity, [$foreignEntity->getId()]);
        } elseif ($entity->getEntityType() === 'CrmTag' && isset(CrmTags::LINKS[$link])) {
            if (!$this->acl->checkEntityEdit($foreignEntity)) throw new Forbidden();
            $this->tags->validate($foreignEntity, [$entity->getId()]);
        }
    }
}
