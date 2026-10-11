<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\RecordHooks;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\CreateHook;
use Espo\Core\Record\Hook\UpdateHook;
use Espo\Core\Record\Hook\DeleteHook;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Core\Record\Hook\UnlinkHook;
use Espo\ORM\Entity;

/** Generic record services must never act as financial writers, even for administrators. */
final class DenyMutation implements CreateHook, UpdateHook, DeleteHook, LinkHook, UnlinkHook
{
    public function process(Entity $entity, mixed ...$params): void
    {
        throw new Forbidden('Credit accounting records can only be changed by accounting services.');
    }
}
