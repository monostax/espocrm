<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\RecordHooks;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Core\Record\Hook\UnlinkHook;
use Espo\ORM\Entity;

/** Cover both directions, including Tenant.crmTags and administrators. */
class ProtectPersonalTagRelationships implements LinkHook, UnlinkHook
{
    public function __construct(private Acl $acl) {}

    public function process(Entity $entity, string $link, Entity $foreignEntity): void
    {
        $tag = $entity->getEntityType() === 'CrmTag' ? $entity :
            ($foreignEntity->getEntityType() === 'CrmTag' ? $foreignEntity : null);
        if (!$tag) return;
        if (!$this->acl->checkEntityRead($tag)) throw new Forbidden();

        $other = $tag === $entity ? $foreignEntity : $entity;
        if (in_array($other->getEntityType(), ['Tenant', 'User'], true) ||
            ($other->getEntityType() === 'Team' && $tag->get('visibility') === 'personal')) {
            throw new Forbidden('Tag workspace, owner and personal visibility cannot be changed through relationships.');
        }
    }
}
