<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

class CrmTags
{
    public const TYPES = ['Opportunity', 'Task', 'Call', 'Meeting'];
    public const LINKS = ['opportunities' => 'Opportunity', 'tasks' => 'Task', 'calls' => 'Call', 'meetings' => 'Meeting'];

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private SelectBuilderFactory $select,
        private TenantResolver $tenants,
    ) {}

    public function query(): SelectBuilder
    {
        return $this->select->create()->from('CrmTag')->withStrictAccessControl()->buildQueryBuilder();
    }

    public function validate(Entity $record, array $ids): void
    {
        if (!$ids) return;
        $tenantId = $record->get('tenantId');
        if (!$tenantId) {
            $teamIds = $record instanceof CoreEntity ? $record->getLinkMultipleIdList('teams') : ($record->get('teamsIds') ?? []);
            $tenantId = $this->tenants->resolveUniqueFromTeamIds($teamIds);
        }
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
        if (!$rows || !$this->acl->checkField($type, 'tags') || !$this->acl->checkScope('CrmTag', 'read')) return $rows;
        $query = SelectBuilder::create()->from($type)->join('tags', 'crmTag')
            ->where(['id' => array_map(fn ($row) => $row->id, $rows), 'crmTag.id=s' => $this->query()->select(['id'])->build()])
            ->select(['id', ['crmTag.id', 'tagId'], ['crmTag.name', 'tagName']])->order('crmTag.name')->build();
        $assignments = [];
        foreach ($this->em->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $assignments[$row['id']][$row['tagId']] = $row['tagName'];
        }
        foreach ($rows as $row) {
            $row->tagsIds = array_keys($assignments[$row->id] ?? []);
            $row->tagsNames = (object) ($assignments[$row->id] ?? []);
        }
        return $rows;
    }

    /** Aggregate a semi-joined record scope so team joins never multiply tag counts. */
    public function counts(string $type, SelectBuilder $scope): array
    {
        if (!$this->acl->checkScope('CrmTag', 'read') || !$this->acl->checkField($type, 'tags')) return [];
        $ids = (clone $scope)->select(['id'])->order([])->limit(null, null)->build();
        $tags = $this->query()->select(['id'])->build();
        $query = SelectBuilder::create()->from($type)->where(['id=s' => $ids])
            ->join('tags', 'crmTag')->where(['crmTag.id=s' => $tags])
            ->select([['crmTag.id', 'tagId'], ['COUNT:id', 'count']])->group('crmTag.id')->build();
        $counts = [];
        foreach ($this->em->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[$row['tagId']] = (int) $row['count'];
        }
        return $counts;
    }
}
