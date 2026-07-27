<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\Automation;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ApplicationState;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureAutomation\Entities\Automation as AutomationEntity;
use Espo\Modules\FeatureAutomation\Services\CrossTenantAccess;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * `crossTenant` disables the tenant predicate on automation reads, so only an
 * instance admin may set or clear it. Tenant-admins authoring automations in
 * their own workspace must never be able to opt out of tenant isolation.
 *
 * @implements BeforeSave<AutomationEntity>
 */
class ValidateCrossTenant implements BeforeSave
{
    /** After ValidateRunAsUser ($order = 15). */
    public static int $order = 16;

    public function __construct(
        private ApplicationState $applicationState,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof AutomationEntity || $options->get('silent')) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged(CrossTenantAccess::FIELD)) {
            return;
        }

        // Nothing to authorize when the flag is (and stays) off.
        if (!$entity->get(CrossTenantAccess::FIELD)) {
            return;
        }

        if (!$this->applicationState->isLogged()) {
            throw new Forbidden('Authentication required to set crossTenant.');
        }

        if (!TenantRoleAuth::isInstanceAdmin($this->applicationState->getUser())) {
            throw new Forbidden(
                'Only an instance administrator may enable crossTenant on an automation. '
                . 'Automations are tenant-scoped by default.'
            );
        }
    }
}
