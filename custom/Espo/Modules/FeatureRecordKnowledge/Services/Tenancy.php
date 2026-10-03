<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;

class Tenancy
{
    public function __construct(private EntityManager $em, private User $user, private Acl $acl,
        private TenantResolver $teams, private UserTenantResolver $users, private TeamsAccess $teamAccess) {}

    /** null denotes an instance administrator/system context. */
    public function ids(?User $user = null, bool $manage = false): ?array
    {
        $user ??= $this->user;
        if ($manage && ($user->isApi() || $user->isPortal())) return [];
        if ($user->isAdmin() || $user->isSystem()) return null;
        $ids = $this->users->resolveTenantIds($user);
        if (!$manage) return $ids;
        $roles = TenantRoleAuth::tenantAdminRoleIds();
        if (array_intersect($roles, $user->getLinkMultipleIdList('roles'))) return $ids;
        $adminTeams = $this->em->getRDBRepository('Team')->select(['id'])->where(['id' => $user->getTeamIdList()])
            ->join('roles')->where(['roles.id' => $roles])->distinct()->find();
        $teamIds = [];
        foreach ($adminTeams as $team) $teamIds[] = $team->getId();
        return array_values(array_intersect($ids, $this->teams->resolveAllFromTeamIds($teamIds)));
    }

    public function allowed(User $user, string $id, bool $manage = false): bool
    {
        $ids = $this->ids($user, $manage);
        return $id !== '' && ($ids === null || in_array($id, $ids, true)) && (bool) $this->em->getEntityById('Tenant', $id);
    }

    public function assert(string $id, bool $manage = false): void
    {
        if (!$this->allowed($this->user, $id, $manage) || !$this->acl->checkScope('RecordPredicate', $manage ? 'edit' : 'read')) throw new Forbidden('Tenant predicate access denied.');
    }

    public function select(?string $id = null, bool $manage = false): string
    {
        if (!$id) {
            $ids = $this->ids(null, $manage);
            if ($ids === null || count($ids) !== 1) throw new BadRequest('Select an explicit accessible tenant.');
            $id = $ids[0];
        }
        $this->assert($id, $manage);
        return $id;
    }

    public function recordIds(Entity $record): array
    {
        if ($record->getEntityType() === 'Tenant') return [$record->getId()];
        if ($record->getEntityType() === 'User' && $record instanceof User) return $this->users->resolveTenantIds($record);
        if ($record->get('knowledgeRecordType')) {
            $parent = $this->em->getEntityById($record->get('knowledgeRecordType'), $record->get('knowledgeRecordId'));
            return $parent ? $this->recordIds($parent) : [];
        }
        $teamIds = $this->teams->resolveAllFromTeamIds($this->teamAccess->entityTeamIds($record));
        $declared = $record->get('tenantId');
        if ($declared) {
            if ($teamIds && ($teamIds !== [$declared])) throw new BadRequest('Record tenancy disagrees with its teams.');
            return $this->em->getEntityById('Tenant', $declared) ? [$declared] : [];
        }
        return $teamIds;
    }

    public function derive(Entity $subject, Entity $object, Entity $document, ?string $selected = null): string
    {
        $ids = array_values(array_intersect($this->recordIds($subject), $this->recordIds($object), $this->recordIds($document)));
        if (!$selected && count($ids) === 1) $selected = $ids[0];
        if (!$selected || !in_array($selected, $ids, true)) throw new BadRequest('Endpoint/evidence tenancy is missing, ambiguous or cross-tenant.');
        $this->assert($selected);
        return $selected;
    }

    public function contexts(): array
    {
        if (!$this->acl->checkScope('RecordPredicate', 'read')) throw new Forbidden();
        $ids = $this->ids();
        $query = $this->em->getRDBRepository('Tenant')->select(['id', 'name'])->order('name');
        if ($ids !== null) $query->where(['id' => $ids]);
        $list = [];
        foreach ($query->find() as $tenant) $list[] = ['id' => $tenant->getId(), 'name' => $tenant->get('name'),
            'editable' => $this->allowed($this->user, $tenant->getId(), true) && $this->acl->checkScope('RecordPredicate', 'edit')];
        return $list;
    }
}
