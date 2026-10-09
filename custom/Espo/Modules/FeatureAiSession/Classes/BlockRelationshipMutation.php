<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Classes;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Core\Record\Hook\UnlinkHook;
use Espo\ORM\Entity;

class BlockRelationshipMutation implements LinkHook, UnlinkHook
{
    public function process(Entity $entity, string $link, Entity $foreignEntity): void
    {
        throw new Forbidden('Update session fields through the record API.');
    }
}
