<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\Automation;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureAutomation\Entities\Automation as AutomationEntity;
use Espo\Modules\Global\Services\RunAsUserAccess;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<AutomationEntity> */
class ValidateRunAsUser implements BeforeSave
{
    /** After AssignTenantFromTeam ($order = 9). */
    public static int $order = 15;

    public function __construct(
        private RunAsUserAccess $runAsUserAccess,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof AutomationEntity || $options->get('silent')) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('runAsUserId')) {
            return;
        }

        $runAsUserId = $entity->get('runAsUserId');
        $runAsUserId = is_string($runAsUserId) && $runAsUserId !== '' ? $runAsUserId : null;

        $tenantId = $entity->get('tenantId');
        $tenantId = is_string($tenantId) && $tenantId !== '' ? $tenantId : null;

        $this->runAsUserAccess->assertCanSet($runAsUserId, $tenantId);
    }
}
