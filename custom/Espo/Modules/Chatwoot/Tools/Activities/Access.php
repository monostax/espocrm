<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Activities;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\AccessControl\FilterFactory;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

/** Activity tenancy predates tenantId: Task/Meeting ownership is expressed by teams. */
class Access
{
    public const TYPES = ['Task', 'Call', 'Meeting'];

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private UserTenantResolver $tenants,
        private SelectBuilderFactory $select,
        private FilterFactory $filters,
    ) {}

    public function type(string $type): string
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new BadRequest('Invalid activity type.');
        }
        return $type;
    }

    public function workspace(int $accountId): Entity
    {
        if ($accountId < 1 || (!$this->user->isRegular() && !$this->user->isAdmin())) {
            throw new Forbidden();
        }
        $accounts = $this->em->getRDBRepository('ChatwootAccount')->where(['chatwootAccountId' => $accountId])->limit(0, 2)->find();
        if (count($accounts) !== 1) {
            throw new NotFound('Workspace integration not found.');
        }
        $account = iterator_to_array($accounts, false)[0];
        $tenant = $this->em->getEntityById('Tenant', (string) $account->get('tenantId'));
        if (!$tenant || (!$this->user->isAdmin() && !$this->tenants->canActForTenant($this->user, $tenant->getId()))) {
            throw new Forbidden();
        }
        return $tenant;
    }

    public function teamIds(Entity $tenant): array
    {
        $ids = [$tenant->get('baseUserTeamId')];
        foreach ($this->em->getRDBRepository('Tenant')->getRelation($tenant, 'otherUserTeams')->find() as $team) {
            $ids[] = $team->getId();
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public function query(string $type, Entity $tenant, bool $stream = false): SelectBuilder
    {
        $this->type($type);
        $query = $this->select->create()->from($type)->withStrictAccessControl()->buildQueryBuilder();
        $teams = SelectBuilder::create()->from($type, 'workspaceActivity')->select('id')
            ->join('teams', 'workspaceTeam')->where(['workspaceTeam.id' => $this->teamIds($tenant)])->build();
        $where = ['id=s' => $teams];
        // VoIP Calls have authoritative tenancy; manually scheduled calls may only have teams.
        if ($type === 'Call' && $this->em->getDefs()->getEntity('Call')->hasAttribute('tenantId')) {
            $where = ['OR' => [['tenantId' => $tenant->getId()], ['tenantId' => null, $where]]];
        }
        $query->where($where);
        if ($stream) {
            if (!$this->acl->checkScope('Note', 'read')) return $query->where(['id' => null]);
            $level = $this->acl->getLevel($type, 'stream');
            $filter = match ($level) {
                'own' => 'onlyOwn', 'team' => 'onlyTeam', 'all', 'yes', true => null, default => false,
            };
            if ($filter === false) $query->where(['id' => null]);
            elseif ($filter) $this->filters->create($type, $this->user, $filter)->apply($query);
        }
        return $query;
    }

    public function record(string $type, string $id, Entity $tenant, bool $stream = false): Entity
    {
        $entity = $this->em->getRDBRepository($this->type($type))->clone($this->query($type, $tenant)->where(['id' => $id])->build())->findOne();
        if (!$entity) {
            throw new NotFound();
        }
        if ($stream && (!$this->acl->checkEntity($entity, 'stream') || !$this->acl->checkScope('Note', 'read'))) {
            throw new Forbidden();
        }
        return $entity;
    }

    public function validateTeams(object $data, Entity $tenant, bool $creating): void
    {
        $allowed = $this->teamIds($tenant);
        if ($creating && !isset($data->teamsIds)) {
            $data->teamsIds = [$tenant->get('baseUserTeamId')];
        }
        if (isset($data->teamsIds) && (!is_array($data->teamsIds) || !$data->teamsIds || array_diff($data->teamsIds, $allowed))) {
            throw new BadRequest('Choose teams from this workspace.');
        }
    }
}
