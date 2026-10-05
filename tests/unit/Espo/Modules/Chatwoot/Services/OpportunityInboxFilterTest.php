<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SelectBuilder as AccessBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\OpportunityBulkPostAccess;
use Espo\Modules\Chatwoot\Services\OpportunityInboxFilter;
use Espo\Modules\Chatwoot\Tools\Acl\InboxAccessResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

/** Real ORM queries against disposable data, with explicit CRM and Chatwoot permission grants. */
class OpportunityInboxFilterTest extends TestCase
{
    private PDO $pdo;
    private array $defs = [];
    private MysqlQueryComposer|PostgresqlQueryComposer $composer;
    private MysqlQueryComposer $mysql;
    private PostgresqlQueryComposer $postgres;
    private OpportunityInboxFilter $service;
    private array $hiddenScopes = [];
    private array $hiddenFields = [];
    private array $allowedInboxes = ['a', 'b'];
    private array $remoteVisibility = [];
    private array $apiBatches = [];
    private bool $admin = false;
    private bool $tenantMember = true;
    private bool $tokenAvailable = true;
    private bool $apiFails = false;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = [
            'Opportunity' => ['id', 'tenantId', 'assignedUserId', 'readable', 'deleted'],
            'ChatwootAccount' => ['id', 'tenantId', 'platformId', 'chatwootAccountId', 'readable', 'deleted'],
            'ChatwootInbox' => ['id', 'name', 'chatwootAccountId', 'chatwootInboxId', 'readable', 'deleted'],
            'ChatwootConversation' => ['id', 'chatwootAccountId', 'chatwootConversationId', 'inboxId', 'readable', 'entityReadable', 'deleted'],
            'ChatwootConversationOpportunity' => ['id', 'opportunityId', 'chatwootConversationId', 'deleted'],
            'ChatwootUser' => ['id', 'assignedUserId', 'platformId', 'userAccessToken', 'deleted'],
            'ChatwootPlatform' => ['id', 'backendUrl', 'deleted'],
        ];
        foreach ($tables as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $numeric = in_array($field, ['readable', 'entityReadable', 'deleted']);
                $columns[] = $this->snake($field) . ($numeric ? ' INTEGER DEFAULT 0' : ' TEXT');
                $this->defs[$type]['attributes'][$field] = ['type' => $numeric ? 'int' : 'varchar'];
            }
            $this->pdo->exec('CREATE TABLE ' . $this->snake($type) . ' (' . implode(', ', $columns) . ')');
        }
        $this->defs['Opportunity']['relations']['chatwootConversations'] = [
            'type' => 'manyMany', 'entity' => 'ChatwootConversation',
            'relationName' => 'ChatwootConversationOpportunity', 'midKeys' => ['opportunityId', 'chatwootConversationId'],
        ];
        $this->defs['ChatwootConversation']['relations']['opportunities'] = [
            'type' => 'manyMany', 'entity' => 'Opportunity',
            'relationName' => 'ChatwootConversationOpportunity', 'midKeys' => ['chatwootConversationId', 'opportunityId'],
        ];
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($this->defs);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $this->defs[$type]));
        $metadata = new Metadata($provider);
        $this->mysql = new MysqlQueryComposer($this->pdo, $entities, $metadata);
        $this->postgres = new PostgresqlQueryComposer($this->pdo, $entities, $metadata);
        $this->composer = $this->mysql;

        $em = $this->createMock(EntityManager::class);
        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('execute')->willReturnCallback(fn ($query) => $this->pdo->query($this->composer->composeSelect($query)));
        $em->method('getQueryExecutor')->willReturn($executor);
        $em->method('getRDBRepository')->willReturnCallback(function ($type) {
            $repo = $this->createMock(RDBRepository::class);
            $repo->method('clone')->willReturnCallback(fn ($query) => $this->repositoryQuery($type, SelectBuilder::create()->clone($query)));
            $repo->method('where')->willReturnCallback(fn ($where) => $this->repositoryQuery($type, SelectBuilder::create()->from($type)->where($where)));
            return $repo;
        });
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) =>
            $this->repositoryQuery($type, SelectBuilder::create()->from($type)->where(['id' => $id]))->findOne());
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturnCallback(fn ($type) => !in_array($type, $this->hiddenScopes));
        $acl->method('checkField')->willReturnCallback(fn ($type, $field) => !in_array("$type.$field", $this->hiddenFields));
        $acl->method('checkLink')->willReturnCallback(fn ($type, $field) => !in_array("$type.$field", $this->hiddenFields));
        $acl->method('checkEntityRead')->willReturnCallback(fn ($entity) =>
            $this->admin || ($entity->get('readable') && $entity->get('entityReadable') !== 0));
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('viewer');
        $user->method('isActive')->willReturn(true);
        $user->method('isRegular')->willReturn(true);
        $user->method('isAdmin')->willReturnCallback(fn () => $this->admin);
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('canActForTenant')->willReturnCallback(fn ($user, $tenant) => $this->tenantMember && $tenant === 'tenant-a');
        $workspace = new OpportunityBulkPostAccess($em, $user, $acl, $tenants);
        $inboxAccess = $this->createMock(InboxAccessResolver::class);
        $inboxAccess->method('getAllowedInboxIdList')->willReturnCallback(fn () => $this->admin ? null : $this->allowedInboxes);
        $factory = $this->createMock(SelectBuilderFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $builder = $this->createMock(AccessBuilder::class);
            $type = null;
            $strict = false;
            $builder->method('from')->willReturnCallback(function ($entity) use (&$type, $builder) {
                $type = $entity;
                return $builder;
            });
            $builder->method('withStrictAccessControl')->willReturnCallback(function () use (&$strict, $builder) {
                $strict = true;
                return $builder;
            });
            $builder->method('buildQueryBuilder')->willReturnCallback(function () use (&$type, &$strict) {
                self::assertTrue($strict, 'CRM candidates must use strict ACL.');
                $query = SelectBuilder::create()->from($type);
                if (!$this->admin) $query->where(['readable' => 1]);
                return $query;
            });
            return $builder;
        });
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getConversationVisibility')->willReturnCallback(function ($url, $token, $accountId, $ids) {
            self::assertSame('https://chat.example', $url);
            self::assertSame('personal-token', $token);
            self::assertSame(1, $accountId);
            self::assertLessThanOrEqual(500, count($ids));
            $this->apiBatches[] = $ids;
            if ($this->apiFails) throw new \Espo\Core\Exceptions\Error('Chatwoot unavailable');
            $rows = [];
            foreach ($ids as $id) {
                if (isset($this->remoteVisibility[$id])) $rows[] = ['id' => $id, 'inbox_id' => $this->remoteVisibility[$id]];
            }
            return $rows;
        });
        $this->service = new OpportunityInboxFilter($em, $user, $acl, $factory, $workspace, $inboxAccess, $api);
        $this->insert('ChatwootAccount', ['id' => 'account-a', 'tenantId' => 'tenant-a', 'platformId' => 'platform', 'chatwootAccountId' => 1, 'readable' => 1]);
        $this->insert('ChatwootAccount', ['id' => 'account-b', 'tenantId' => 'tenant-b', 'platformId' => 'platform', 'chatwootAccountId' => 2, 'readable' => 1]);
        $this->insert('ChatwootPlatform', ['id' => 'platform', 'backendUrl' => 'https://chat.example']);
        $this->insert('ChatwootUser', ['id' => 'chat-user', 'assignedUserId' => 'viewer', 'platformId' => 'platform', 'userAccessToken' => 'personal-token']);
        foreach (['a' => 11, 'b' => 12, 'nonmember' => 13, 'hidden' => 14, 'foreign' => 21] as $id => $remote) {
            $this->insert('ChatwootInbox', ['id' => $id, 'name' => strtoupper($id), 'chatwootAccountId' => $id === 'foreign' ? 'account-b' : 'account-a',
                'chatwootInboxId' => $remote, 'readable' => $id === 'hidden' ? 0 : 1]);
        }
        foreach (['one', 'two', 'hidden', 'other', 'denied', 'empty'] as $id) {
            $this->insert('Opportunity', ['id' => $id, 'tenantId' => $id === 'other' ? 'tenant-b' : 'tenant-a',
                'assignedUserId' => $id === 'one' ? 'viewer' : null, 'readable' => $id === 'hidden' ? 0 : 1]);
        }
        $this->conversation('c1', 101, 'a', ['one', 'two', 'other', 'hidden']);
        $this->conversation('c2', 102, 'a', ['one']);
        $this->conversation('c3', 103, 'b', ['one']);
        $this->conversation('crm-denied', 104, 'a', ['denied'], ['readable' => 0]);
        $this->conversation('entity-denied', 105, 'a', ['denied'], ['entityReadable' => 0]);
        $this->conversation('native-denied', 106, 'a', ['denied']);
        unset($this->remoteVisibility[106]);
        $this->conversation('moved', 107, 'a', ['denied']);
        $this->remoteVisibility[107] = 99;
        $this->conversation('wrong-account', 108, 'a', ['denied'], ['chatwootAccountId' => 'account-b']);
        $this->conversation('foreign', 109, 'foreign', ['denied'], ['chatwootAccountId' => 'account-b']);
        $this->conversation('no-link', 110, 'a', []);
        $this->conversation('deleted', 111, 'a', ['denied'], ['deleted' => 1]);
        $this->conversation('nonmember', 112, 'nonmember', ['denied']);
        $this->conversation('hidden-inbox', 113, 'hidden', ['denied']);
        $this->conversation('unlinked', 114, 'a', ['denied']);
        $this->pdo->exec("UPDATE chatwoot_conversation_opportunity SET deleted = 1 WHERE chatwoot_conversation_id = 'unlinked'");
    }

    private function snake(string $value): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    private function insert(string $type, array $row): void
    {
        $columns = implode(', ', array_map($this->snake(...), array_keys($row)));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $this->pdo->prepare('INSERT INTO ' . $this->snake($type) . " ($columns) VALUES ($placeholders)")->execute(array_values($row));
    }

    private function conversation(string $id, int $remote, string $inbox, array $opportunities, array $overrides = []): void
    {
        $this->insert('ChatwootConversation', $overrides + ['id' => $id, 'chatwootAccountId' => 'account-a',
            'chatwootConversationId' => $remote, 'inboxId' => $inbox, 'readable' => 1, 'entityReadable' => 1]);
        $this->remoteVisibility[$remote] = $inbox === 'b' ? 12 : 11;
        foreach ($opportunities as $opp) {
            $this->insert('ChatwootConversationOpportunity', ['id' => "$id-$opp", 'opportunityId' => $opp, 'chatwootConversationId' => $id]);
        }
    }

    private function repositoryQuery(string $type, SelectBuilder $query): RDBSelectBuilder
    {
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('limit')->willReturnCallback(function ($offset, $limit) use ($query, $builder) {
            $query->limit($offset, $limit);
            return $builder;
        });
        $find = function () use ($type, $query) {
            $rows = $this->pdo->query($this->composer->composeSelect($query->build()))->fetchAll(PDO::FETCH_ASSOC);
            return array_map(function ($row) use ($type) {
                if ($type === 'ChatwootUser' && !$this->tokenAvailable) $row['userAccessToken'] = null;
                $entity = new BaseEntity($type, $this->defs[$type]);
                $entity->set($row);
                return $entity;
            }, $rows);
        };
        $builder->method('find')->willReturnCallback(fn () => new EntityCollection($find()));
        $builder->method('findOne')->willReturnCallback(fn () => $find()[0] ?? null);
        return $builder;
    }

    private function counts(array $where = []): array
    {
        $scope = SelectBuilder::create()->from('Opportunity')->where($where);
        if (!$this->admin) $scope->where(['readable' => 1]);
        return $this->service->counts($scope, 1);
    }

    private function ids(string $inbox = 'a', int $account = 1): array
    {
        $query = $this->service->matchingIds(['inboxId' => $inbox, 'chatwootAccountId' => $account]);
        $ids = $this->pdo->query($this->composer->composeSelect($query))->fetchAll(PDO::FETCH_COLUMN);
        sort($ids);
        return $ids;
    }

    public function testCountsAndResultsAgreeWithoutDuplicatesOrHiddenRelationships(): void
    {
        foreach ([$this->mysql, $this->postgres] as $this->composer) {
            self::assertSame([['id' => 'a', 'name' => 'A', 'count' => 2], ['id' => 'b', 'name' => 'B', 'count' => 1]], $this->counts());
            self::assertSame(['one', 'two'], $this->ids());
            self::assertSame(['one'], $this->ids('b'));
        }
    }

    public function testAssigneeScopeAppliesBeforeCounts(): void
    {
        self::assertSame([['id' => 'a', 'name' => 'A', 'count' => 1]], $this->counts(['assignedUserId' => null]));
        self::assertSame([], $this->counts(['id' => 'empty']));
    }

    public function testMissingScopeOrFieldPermissionNeverContributesToCounts(): void
    {
        foreach (['Opportunity', 'ChatwootConversation', 'ChatwootInbox', 'ChatwootAccount'] as $scope) {
            $this->hiddenScopes = [$scope];
            self::assertSame([], $this->counts());
        }
        $this->hiddenScopes = [];
        foreach (['Opportunity.chatwootConversations', 'ChatwootConversation.inbox', 'ChatwootInbox.name'] as $field) {
            $this->hiddenFields = [$field];
            self::assertSame([], $this->counts());
        }
        self::assertSame([], $this->apiBatches);
        $this->expectException(Forbidden::class);
        $this->ids();
    }

    public function testForgedForeignInboxIsRejectedEvenWhenMembershipIncludesIt(): void
    {
        $this->allowedInboxes[] = 'foreign';
        $this->expectException(Forbidden::class);
        $this->ids('foreign');
    }

    public function testNonmemberInboxIsRejected(): void
    {
        $this->expectException(Forbidden::class);
        $this->ids('nonmember');
    }

    public function testForeignWorkspaceIsRejected(): void
    {
        $this->expectException(Forbidden::class);
        $this->ids('foreign', 2);
    }

    public function testRevokedTenantMembershipIsRejected(): void
    {
        $this->tenantMember = false;
        $this->expectException(Forbidden::class);
        $this->counts();
    }

    public function testAmbiguousNumericAccountIdsFailClosed(): void
    {
        $this->insert('ChatwootAccount', ['id' => 'collision', 'tenantId' => 'tenant-b', 'platformId' => 'other-platform', 'chatwootAccountId' => 1, 'readable' => 1]);
        $this->expectException(NotFound::class);
        $this->counts();
    }

    public function testAdminStillCannotMixWorkspacesOrBypassLiveConversationPermissions(): void
    {
        $this->admin = true;
        $this->remoteVisibility = [101 => 11, 102 => 11, 103 => 12];
        self::assertSame(['hidden', 'one', 'two'], $this->ids());
        self::assertSame([['id' => 'a', 'name' => 'A', 'count' => 3], ['id' => 'b', 'name' => 'B', 'count' => 1]], $this->counts());
    }

    public function testNoPersonalTokenDoesNotFallBackToAccountCredentials(): void
    {
        $this->tokenAvailable = false;
        self::assertSame([], $this->counts());
        self::assertSame([], $this->ids());
        self::assertSame([], $this->apiBatches);
    }

    public function testAuthorizationServiceFailureFailsClosed(): void
    {
        $this->apiFails = true;
        $this->expectException(\Espo\Core\Exceptions\Error::class);
        $this->counts();
    }

    public function testLargeCandidateSetsAreFullyAuthorizedInBoundedBatches(): void
    {
        for ($i = 1000; $i < 1600; $i++) $this->conversation("batch-$i", $i, 'a', ['one']);
        self::assertSame([['id' => 'a', 'name' => 'A', 'count' => 2], ['id' => 'b', 'name' => 'B', 'count' => 1]], $this->counts());
        self::assertCount(2, $this->apiBatches);
    }

    public function testMalformedFilterCannotBecomeAnUnfilteredList(): void
    {
        $this->expectException(BadRequest::class);
        $this->service->matchingIds(['inboxId' => 'a']);
    }
}
