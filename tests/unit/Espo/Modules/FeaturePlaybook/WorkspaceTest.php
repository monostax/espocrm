<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeaturePlaybook;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\SelectBuilder as AccessSelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\FeaturePlaybook\Services\Playbooks;
use Espo\Modules\FeaturePlaybook\Services\Workspace;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\QueryBuilder;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class WorkspaceTest extends TestCase
{
    private array $rows = [];
    private array $queries = [];
    private array $accessScopes = [];
    private bool $regular = true;
    private bool $member = true;
    private bool $create = true;
    private Workspace $workspace;
    private Playbooks $playbooks;

    private function entity(string $type, array $values): Entity
    {
        $attributes = array_fill_keys(array_keys($values), ['type' => 'varchar']);
        $attributes['editable'] = ['type' => 'bool'];
        $entity = new BaseEntity($type, ['attributes' => $attributes]);
        foreach ($values as $name => $value) {
            $entity->set($name, $value);
        }
        return $entity;
    }

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getQueryBuilder')->willReturn(new QueryBuilder());
        $em->method('getRDBRepository')->willReturnCallback(function ($type) {
            $repo = $this->createMock(RDBRepository::class);
            $repo->method('clone')->willReturnCallback(function (Select $query) use ($type) {
                $this->queries[$type][] = $query;
                $builder = $this->createMock(RDBSelectBuilder::class);
                $rows = $this->rows[$type] ?? [];
                $where = $query->getWhere()->getRaw();
                array_walk_recursive($where, function ($value, $key) use (&$rows) {
                    if ($key === 'id<') {
                        $rows = array_values(array_filter($rows, fn ($row) => $row->getId() < $value));
                    }
                });
                $builder->method('find')->willReturn(new EntityCollection(array_slice($rows, 0, $query->getLimit())));
                $builder->method('findOne')->willReturn($this->rows[$type][0] ?? null);
                return $builder;
            });
            $repo->method('where')->willReturnCallback(function () use ($type) {
                $builder = $this->createMock(RDBSelectBuilder::class);
                $builder->method('count')->willReturn(count($this->rows[$type] ?? []));
                $builder->method('find')->willReturn(new EntityCollection($this->rows[$type] ?? []));
                return $builder;
            });
            return $repo;
        });
        $select = $this->createMock(SelectBuilderFactory::class);
        $select->method('create')->willReturnCallback(function () {
            $builder = $this->createMock(AccessSelectBuilder::class);
            $type = '';
            $builder->method('from')->willReturnCallback(function ($value) use (&$type, $builder) { $type = $value; return $builder; });
            $builder->method('withStrictAccessControl')->willReturnCallback(function () use (&$type, $builder) {
                $this->accessScopes[] = $type;
                return $builder;
            });
            $builder->method('buildQueryBuilder')->willReturnCallback(function () use (&$type) {
                return (new SelectBuilder())->from($type);
            });
            return $builder;
        });
        $user = $this->createMock(User::class);
        $user->method('isRegular')->willReturnCallback(fn () => $this->regular);
        $user->method('getTeamIdList')->willReturn(['team-a']);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturnCallback(fn () => $this->create);
        $acl->method('checkEntityRead')->willReturn(true);
        $acl->method('checkEntityEdit')->willReturnCallback(fn ($row) => $row->get('editable') === true);
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('canActForTenant')->willReturnCallback(fn () => $this->member);
        $teams = $this->createMock(TeamsAccess::class);
        $teams->method('entityTeamIds')->willReturn(['team-a']);
        $teams->method('userSharesTeam')->willReturn(true);
        $this->playbooks = $this->createMock(Playbooks::class);
        $this->workspace = new Workspace($em, $select, $acl, $user, $tenants, $teams, $this->playbooks);
        $this->rows['ChatwootAccount'] = [$this->entity('ChatwootAccount', ['id' => 'workspace', 'tenantId' => 'tenant-a'])];
    }

    private function request(array $query = []): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getRouteParam')->with('accountId')->willReturn('6');
        $request->method('getQueryParam')->willReturnCallback(fn ($key) => $query[$key] ?? null);
        $request->method('getQueryParams')->willReturn($query);
        return $request;
    }

    public function testPortalUserCannotListOrSaveWorkspaceTemplates(): void
    {
        $this->regular = false;
        $this->playbooks->expects($this->never())->method('saveWorkspaceTemplate');
        $this->expectException(Forbidden::class);
        $this->workspace->save('6', (object) ['name' => 'Template']);
    }

    public function testTenantMembershipIsRequiredEvenWhenAccountQueryReturnsARecord(): void
    {
        $this->member = false;
        $this->expectException(Forbidden::class);
        $this->workspace->listing($this->request());
    }

    public function testAmbiguousWorkspaceIdsAreRejected(): void
    {
        $this->rows['ChatwootAccount'][] = $this->entity('ChatwootAccount', ['id' => 'second', 'tenantId' => 'tenant-b']);
        $this->expectException(Forbidden::class);
        $this->workspace->listing($this->request());
    }

    public function testTemplatesAreTenantAndTeamBoundedAndPrivateDraftsAreOmitted(): void
    {
        $this->rows['Playbook'] = [
            $this->entity('Playbook', ['id' => 'private', 'status' => 'Draft']),
            $this->entity('Playbook', ['id' => 'published', 'status' => 'Published']),
            $this->entity('Playbook', ['id' => 'editable', 'status' => 'Draft', 'editable' => true]),
        ];
        $result = $this->workspace->listing($this->request());
        self::assertSame(['published', 'editable'], array_column($result->items, 'id'));
        self::assertSame(['ChatwootAccount', 'Playbook'], $this->accessScopes);
        $where = $this->queries['Playbook'][0]->getWhere()->getRaw();
        self::assertSame('tenant-a', $where['tenantId']);
        self::assertSame(['team-a'], $where['id=s']->getWhere()->getRaw()['teamId']);
        self::assertSame('Playbook', $where['id=s']->getWhere()->getRaw()['entityType']);
    }

    public function testRunsAreRestrictedByReadableOpportunitiesInTheSelectedTenant(): void
    {
        $this->workspace->listing($this->request(['view' => 'runs', 'opportunityId' => 'deal', 'status' => 'Active']));
        self::assertSame(['ChatwootAccount', 'Opportunity'], $this->accessScopes);
        $where = $this->queries['PlaybookRun'][0]->getWhere()->getRaw();
        self::assertSame('Active', $where['status']);
        $opportunities = $where['opportunityId=s']->getRaw();
        self::assertSame('Opportunity', $opportunities['from']);
        self::assertSame('tenant-a', $opportunities['whereClause']['tenantId']);
        self::assertSame('deal', $opportunities['whereClause']['id']);
        self::assertSame(26, $this->queries['PlaybookRun'][0]->getLimit());
    }

    public function testCannotSaveWithoutCreatePermission(): void
    {
        $this->create = false;
        $this->playbooks->expects($this->never())->method('saveWorkspaceTemplate');
        $this->expectException(Forbidden::class);
        $this->workspace->save('6', (object) ['name' => 'Template']);
    }

    public function testRelatedRunsKeepTemplateAndOpportunityAccessBoundaries(): void
    {
        $this->rows['Playbook'] = [$this->entity('Playbook', ['id' => 'template', 'status' => 'Published'])];
        $this->workspace->listing($this->request(['view' => 'runs', 'templateId' => 'template']));
        self::assertSame(['ChatwootAccount', 'Playbook', 'Opportunity'], $this->accessScopes);
        $where = $this->queries['PlaybookRun'][0]->getWhere()->getRaw();
        self::assertSame('template', $where['playbookId']);
        self::assertSame('tenant-a', $where['opportunityId=s']->getWhere()->getRaw()['tenantId']);
        self::assertSame('template', $this->queries['Playbook'][0]->getWhere()->getRaw()['id']);
    }

    public function testRelatedRunsRejectAnInaccessibleTemplate(): void
    {
        $this->rows['Playbook'] = [$this->entity('Playbook', ['id' => 'private', 'status' => 'Draft'])];
        $this->expectException(Forbidden::class);
        $this->workspace->listing($this->request(['view' => 'runs', 'templateId' => 'private']));
    }

    public function testNativeSearchAndStatusFiltersPreserveTheWorkspaceBoundary(): void
    {
        $this->workspace->listing($this->request(['whereGroup' => [
            ['type' => 'textFilter', 'value' => 'Follow-up'],
            ['type' => 'in', 'attribute' => 'status', 'value' => ['Draft', 'Published']],
        ]]));
        $where = $this->queries['Playbook'][0]->getWhere()->getRaw();
        self::assertSame('%Follow-up%', $where['name*']);
        self::assertSame(['Draft', 'Published'], $where['status']);
        self::assertSame('tenant-a', $where['tenantId']);
        self::assertSame(['team-a'], $where['id=s']->getWhere()->getRaw()['teamId']);
    }

    public function testNativeEnumExclusionSupportsTheStandardNullAlternative(): void
    {
        $this->workspace->listing($this->request(['view' => 'runs', 'whereGroup' => [
            ['type' => 'or', 'value' => [
                ['type' => 'notIn', 'attribute' => 'status', 'value' => ['Completed']],
                ['type' => 'isNull', 'attribute' => 'status'],
            ]],
        ]]));
        $where = $this->queries['PlaybookRun'][0]->getWhere()->getRaw();
        self::assertSame([['status!=' => ['Completed']], ['status' => null]], $where['OR']);
        self::assertSame('tenant-a', $where['opportunityId=s']->getWhere()->getRaw()['tenantId']);
    }

    public function testNativeSearchRejectsFieldsOutsideTheWorkspaceSearchLayout(): void
    {
        $this->expectException(BadRequest::class);
        $this->workspace->listing($this->request(['whereGroup' => [
            ['type' => 'equals', 'attribute' => 'tenantId', 'value' => 'other-tenant'],
        ]]));
    }

    public function testListsReturnBoundedPagesWithAnAuthorizedContinuationCursor(): void
    {
        $this->rows['Playbook'] = array_map(fn ($index) => $this->entity('Playbook', [
            'id' => sprintf('template-%02d', $index), 'status' => 'Published',
        ]), range(30, 5));
        $result = $this->workspace->listing($this->request(['search' => 'Qualification', 'cursor' => 'template-31']));
        self::assertCount(25, $result->items);
        self::assertSame('template-06', $result->cursor);
        $where = $this->queries['Playbook'][0]->getWhere()->getRaw();
        self::assertSame('template-31', $where['id<']);
        self::assertSame('%Qualification%', $where['name*']);
    }

    public function testCannotEditReadOnlyPublishedTemplate(): void
    {
        $this->rows['Playbook'] = [$this->entity('Playbook', ['id' => 'published', 'status' => 'Published'])];
        $this->playbooks->expects($this->never())->method('saveWorkspaceTemplate');
        $this->expectException(Forbidden::class);
        $this->workspace->save('6', (object) ['id' => 'published', 'name' => 'Changed']);
    }

    public function testNativePaginationCountsOnlyAuthorizedRows(): void
    {
        $this->rows['Playbook'] = [
            $this->entity('Playbook', ['id' => 'private', 'status' => 'Draft']),
            $this->entity('Playbook', ['id' => 'first', 'status' => 'Published']),
            $this->entity('Playbook', ['id' => 'second', 'status' => 'Draft', 'editable' => true]),
            $this->entity('Playbook', ['id' => 'third', 'status' => 'Published']),
        ];
        $page = $this->workspace->listing($this->request(['offset' => '1', 'maxSize' => '1']));
        self::assertSame(['second'], array_column($page->list, 'id'));
        self::assertSame(-1, $page->total);
        self::assertSame($page->items, $page->list);
        $last = $this->workspace->listing($this->request(['offset' => '2', 'maxSize' => '1']));
        self::assertSame(['third'], array_column($last->list, 'id'));
        self::assertSame(3, $last->total);
        self::assertNull($last->cursor);
        $beyond = $this->workspace->listing($this->request(['offset' => '10', 'maxSize' => '1']));
        self::assertSame([], $beyond->list);
        self::assertSame(3, $beyond->total);
    }

    public function testNativePaginationRejectsOversizedPages(): void
    {
        $this->expectException(BadRequest::class);
        $this->workspace->listing($this->request(['maxSize' => '201']));
    }

    public function testNativePagesScanAcrossBatchesWithoutCountingPrivateDrafts(): void
    {
        $this->rows['Playbook'] = array_map(fn ($index) => $this->entity('Playbook', [
            'id' => sprintf('template-%02d', $index), 'status' => $index % 2 ? 'Draft' : 'Published',
        ]), range(80, 1));
        $page = $this->workspace->listing($this->request(['offset' => '5', 'maxSize' => '30']));
        self::assertCount(30, $page->list);
        self::assertSame('template-70', $page->list[0]->id);
        self::assertSame('template-12', $page->list[29]->id);
        self::assertSame(-1, $page->total);
        $last = $this->workspace->listing($this->request(['offset' => '35', 'maxSize' => '30']));
        self::assertSame(['template-10', 'template-08', 'template-06', 'template-04', 'template-02'], array_column($last->list, 'id'));
        self::assertSame(40, $last->total);
    }

    public function testCreationUsesResolvedWorkspaceContextWithoutAnOpportunity(): void
    {
        $body = (object) ['name' => 'Template', 'tenantId' => 'untrusted'];
        $this->playbooks->expects($this->once())->method('saveWorkspaceTemplate')
            ->with($this->rows['ChatwootAccount'][0], $body)->willReturn((object) ['id' => 'new']);
        self::assertSame('new', $this->workspace->save('6', $body)->id);
    }
}
