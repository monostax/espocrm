<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

class CrmTags
{
    public const TYPES = ['Opportunity', 'Task', 'Call', 'Meeting', 'Initiative', 'Contact', 'Account'];
    public const LINKS = ['opportunities' => 'Opportunity', 'tasks' => 'Task', 'calls' => 'Call', 'meetings' => 'Meeting', 'initiatives' => 'Initiative', 'contacts' => 'Contact', 'accounts' => 'Account'];

    public static function field(string $type): string
    {
        return in_array($type, ['Contact', 'Account'], true) ? 'crmTags' : 'tags';
    }

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private SelectBuilderFactory $select,
        private TenantResolver $tenants,
        private User $user,
        private UserTenantResolver $userTenants,
    ) {}

    public function defaultWorkspace(): \stdClass
    {
        $ids = $this->userTenants->resolveTenantIds($this->user);
        if (count($ids) !== 1) return (object) [];

        $tenant = $this->em->getEntityById('Tenant', $ids[0]);
        return (object) ['tenantId' => $ids[0], 'tenantName' => $tenant?->get('name')];
    }

    /** Never serialize a hidden tag, even through a record's linkMultiple data. */
    public function filterOutput(Entity $entity): void
    {
        if ($entity->getEntityType() === 'CrmTag') {
            if (!$this->acl->checkEntityRead($entity)) throw new Forbidden();
            return;
        }
        if (!in_array($entity->getEntityType(), self::TYPES, true)) return;
        $field = self::field($entity->getEntityType());
        if (!$entity->has($field . 'Ids') && !$entity->has($field . 'Names') && !$entity->has($field . 'Columns')) return;

        $rows = $this->decorate($entity->getEntityType(), [(object) ['id' => $entity->getId()]]);
        $entity->set($field . 'Ids', $rows[0]->{$field . 'Ids'} ?? []);
        $entity->set($field . 'Names', $rows[0]->{$field . 'Names'} ?? (object) []);
        if ($entity->has($field . 'Columns')) {
            $entity->set($field . 'Columns', (object) array_intersect_key(
                (array) $entity->get($field . 'Columns'), array_flip($rows[0]->{$field . 'Ids'} ?? [])
            ));
        }
    }

    /** Replace only the actor's visible set; retain hidden associations in storage. */
    public function preserveHidden(Entity $record): void
    {
        if ($record->isNew()) return;
        $field = self::field($record->getEntityType());
        $ids = $record->get($field . 'Ids') ?? [];
        foreach ($this->em->getRDBRepository($record->getEntityType())->getRelation($record, $field)->find() as $tag) {
            if (!$this->acl->checkEntityRead($tag)) {
                $ids[] = $tag->getId();
            }
        }
        $record->set($field . 'Ids', array_values(array_unique($ids)));
    }

    public function validateStoredWorkspace(Entity $record): void
    {
        if ($record->isNew()) return;
        $tenantId = $this->recordTenantId($record);
        foreach ($this->em->getRDBRepository($record->getEntityType())->getRelation($record, self::field($record->getEntityType()))->find() as $tag) {
            if ($tag->get('tenantId') !== $tenantId) {
                throw new BadRequest('Tagged records must stay in the tag workspace.');
            }
        }
    }

    private function recordTenantId(Entity $record): ?string
    {
        $tenantId = $record->get('tenantId');
        if (!$tenantId) {
            $teamIds = $record instanceof CoreEntity ? $record->getLinkMultipleIdList('teams') : ($record->get('teamsIds') ?? []);
            $tenantId = $this->tenants->resolveUniqueFromTeamIds($teamIds);
        }
        return $tenantId;
    }

    public function query(): SelectBuilder
    {
        return $this->select->create()->from('CrmTag')->withStrictAccessControl()->buildQueryBuilder();
    }

    public function validate(Entity $record, array $ids): void
    {
        if (!$ids) return;
        $tenantId = $this->recordTenantId($record);
        if (!$tenantId) throw new BadRequest('Tags require a workspace.');
        foreach (array_unique($ids) as $id) {
            $tag = $this->em->getEntityById('CrmTag', $id);
            if (!$tag || $tag->get('tenantId') !== $tenantId || !$this->acl->checkEntityRead($tag)) {
                throw new BadRequest('Choose CRM tags from this workspace.');
            }
        }
    }

    /** Hydrate page badges in one query; default list loaders omit linkMultiple IDs. */
    public function decorate(string $type, array $rows): array
    {
        if (!$rows) return $rows;
        $field = self::field($type);
        if (!$this->acl->checkField($type, $field) || !$this->acl->checkScope('CrmTag', 'read')) {
            foreach ($rows as $row) {
                $row->{$field . 'Ids'} = [];
                $row->{$field . 'Names'} = (object) [];
                if (isset($row->{$field . 'Columns'})) $row->{$field . 'Columns'} = (object) [];
            }
            return $rows;
        }
        $query = SelectBuilder::create()->from($type)->join($field, 'crmTag')
            ->where(['id' => array_map(fn ($row) => $row->id, $rows), 'crmTag.id=s' => $this->query()->select(['id'])->build()])
            ->select(['id', ['crmTag.id', 'tagId'], ['crmTag.name', 'tagName']])->order('crmTag.name')->build();
        $assignments = [];
        foreach ($this->em->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $assignments[$row['id']][$row['tagId']] = $row['tagName'];
        }
        foreach ($rows as $row) {
            $row->{$field . 'Ids'} = array_keys($assignments[$row->id] ?? []);
            $row->{$field . 'Names'} = (object) ($assignments[$row->id] ?? []);
        }
        return $rows;
    }

    /** Aggregate a semi-joined record scope so team joins never multiply tag counts. */
    public function counts(string $type, SelectBuilder $scope): array
    {
        $field = self::field($type);
        if (!$this->acl->checkScope('CrmTag', 'read') || !$this->acl->checkField($type, $field)) return [];
        $ids = (clone $scope)->select(['id'])->order([])->limit(null, null)->build();
        $tags = $this->query()->select(['id'])->build();
        $query = SelectBuilder::create()->from($type)->where(['id=s' => $ids])
            ->join($field, 'crmTag')->where(['crmTag.id=s' => $tags])
            ->select([['crmTag.id', 'tagId'], ['COUNT:id', 'count']])->group('crmTag.id')->build();
        $counts = [];
        foreach ($this->em->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[$row['tagId']] = (int) $row['count'];
        }
        return $counts;
    }
}
