<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\RecordHooks;

use Espo\Core\Name\Field;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\ORM\Type\FieldType;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\Hook\CreateHook;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantFromTeamsSync;
use Espo\ORM\Defs\Params\FieldParam;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Record API creates (REST, MCP tools, integrations) on tenant-isolated entities.
 *
 * The web UI pre-fills `teams` with the user's default team and the tenant from
 * it. API clients usually send neither, so the create failed with "teams/tenant
 * required". Runs as an early before-create hook: after defaults, before
 * validation and the entity create-ACL check, which still decide the outcome.
 *
 * - No teams on an entity that requires them: add the user's default team,
 *   i.e. their own tenant. Never touches a create that already names teams.
 * - No tenant: derive it from the teams (TenantFromTeamsSync, the same rule the
 *   beforeSave hooks use; teams spanning tenants are refused, not guessed).
 *
 * Only fills values that are missing, so no create that works today changes.
 *
 * @implements CreateHook<Entity>
 */
class FillTeamAndTenantOnCreate implements CreateHook
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
        private TenantFromTeamsSync $tenantFromTeamsSync,
    ) {}

    public function process(Entity $entity, CreateParams $params): void
    {
        if (!$entity instanceof CoreEntity || $this->user->isPortal()) {
            return;
        }

        $this->fillDefaultTeam($entity);

        if ($entity->hasAttribute('tenantId') && !$entity->get('tenantId')) {
            $this->tenantFromTeamsSync->applyBeforeSave($entity, $entity->getEntityType());
        }
    }

    private function fillDefaultTeam(CoreEntity $entity): void
    {
        $defaultTeamId = $this->user->getDefaultTeam()?->getId();

        if (!$defaultTeamId) {
            return;
        }

        $field = $this->entityManager
            ->getDefs()
            ->getEntity($entity->getEntityType())
            ->tryGetField(Field::TEAMS);

        if (
            $field?->getType() !== FieldType::LINK_MULTIPLE ||
            !$field->getParam(FieldParam::REQUIRED) ||
            $entity->getLinkMultipleIdList(Field::TEAMS) !== []
        ) {
            return;
        }

        $entity->addLinkMultipleId(Field::TEAMS, $defaultTeamId);
    }
}
