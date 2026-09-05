<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Classes\FieldProcessing;

use Espo\Core\Acl;
use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\ORM\Entity;

/** User-specific, non-stored UI permissions, using the same ACL as API mutations. */
class AccessLoader implements Loader
{
    public function __construct(private Acl $acl) {}

    public function process(Entity $entity, Params $params): void
    {
        $access = (object) [];

        foreach (['read', 'edit', 'delete', 'stream'] as $action) {
            $access->$action = $this->acl->checkEntity($entity, $action);
        }

        // Always include, even with a restricted list select. Never reuse submitted flags.
        $entity->set('simpleJourneyAccess', $access);
    }
}
