<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\CrmTag;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Entities\User;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateWorkspace implements BeforeSave, SaveHook
{
    public static int $order = 10;

    public function __construct(
        private TeamTenantAccess $teams,
        private User $user,
        private UserTenantResolver $tenants,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->process($entity);
    }

    public function process(Entity $entity): void
    {
        $visibility = $entity->get('visibility') ?? 'team';
        if (!in_array($visibility, ['team', 'personal'], true)) {
            throw new BadRequest('Choose Team or Personal visibility.');
        }
        if (!$entity->isNew() && (
            $visibility !== ($entity->getFetched('visibility') ?? 'team') ||
            $entity->isAttributeChanged('tenantId') ||
            $entity->isAttributeChanged('ownerUserId')
        )) {
            throw new BadRequest('Tag visibility, workspace and owner cannot be changed.');
        }
        $entity->set('visibility', $visibility);
        $tenantId = $entity->get('tenantId');

        if ($visibility === 'personal') {
            $tenantIds = $this->tenants->resolveTenantIds($this->user);
            $tenantId ??= count($tenantIds) === 1 ? $tenantIds[0] : null;
            if (!$tenantId || !in_array($tenantId, $tenantIds, true)) {
                throw new BadRequest('Choose one of your workspaces for this personal tag.');
            }
            $ownerId = $entity->isNew() ? $this->user->getId() : $entity->getFetched('ownerUserId');
            if (!$ownerId || $ownerId !== $this->user->getId()) {
                throw new Forbidden();
            }
            $entity->set([
                'tenantId' => $tenantId,
                'ownerUserId' => $ownerId,
                'nameScope' => 'user:' . $ownerId,
                'teamsIds' => [],
                'teamsNames' => (object) [],
            ]);
            return;
        }

        if ($entity->has('teamsIds') && !$entity->get('teamsIds')) {
            throw new BadRequest('Team tags require at least one team.');
        }
        $derived = $this->teams->deriveTenantId($entity, 'CRM tag', includePersistedTeams: true);
        if (!$derived || ($tenantId && $tenantId !== $derived)) {
            throw new BadRequest('Choose teams from the tag workspace.');
        }
        $this->teams->assertCanAssignTeams($this->teams->resolveTeamIds($entity, true), $derived, 'CRM tag');
        $entity->set('tenantId', $derived);
        $entity->set('ownerUserId', null);
        $entity->set('nameScope', 'team');
    }
}
