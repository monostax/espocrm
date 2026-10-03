<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\CrmTag;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateWorkspace implements BeforeSave
{
    public static int $order = 10;

    public function __construct(private TeamTenantAccess $teams) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $tenantId = $entity->get('tenantId');
        $derived = $this->teams->deriveTenantId($entity, 'CRM tag', includePersistedTeams: true);
        if (!$derived || ($tenantId && $tenantId !== $derived)) {
            throw new BadRequest('Choose teams from the tag workspace.');
        }
        $this->teams->assertCanAssignTeams($this->teams->resolveTeamIds($entity, true), $derived, 'CRM tag');
        $entity->set('tenantId', $derived);
    }
}
