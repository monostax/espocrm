<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureLiveRecords;

use Espo\Modules\FeatureLiveRecords\Persistence\Routing;
use Espo\Modules\FeatureLiveRecords\Persistence\Observer;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use Espo\ORM\EntityCollection;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;

final class RoutingTest extends TestCase
{
    private function routeGraph(array $rows, string $start, array $fetched = []): array
    {
        $entities = [];
        foreach ($rows as $typeAndId => $values) {
            [$type, $id] = explode(':', $typeAndId);
            $entity = new BaseEntity($type, ['attributes' => array_fill_keys(['id', ...array_keys($values)], ['type' => 'varchar'])]);
            $entity->set(['id' => $id, ...$values]);
            foreach ($fetched[$typeAndId] ?? [] as $field => $value) $entity->setFetched($field, $value);
            $entities[$typeAndId] = $entity;
        }
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $entities["$type:$id"] ?? null);
        return (new Routing($em, $this->createMock(TenantResolver::class), $this->createMock(UserTenantResolver::class)))
            ->snapshot([$entities[$start]]);
    }

    public function testContactChannelIdentityInvalidatesItsContactWithoutRoutableValues(): void
    {
        $result = $this->routeGraph([
            'Contact:contact' => ['tenantId' => 'tenant'],
            'ContactChannelIdentity:identity' => ['tenantId' => 'tenant', 'contactId' => 'contact', 'sourceId' => 'private-address'],
        ], 'ContactChannelIdentity:identity');
        self::assertSame(['type' => 'Contact', 'id' => 'contact'], $result['tenant']['Contact:contact']);
        self::assertSame(['type' => 'ContactChannelIdentity', 'id' => 'identity'], $result['tenant']['ContactChannelIdentity:identity']);
        self::assertStringNotContainsString('private-address', json_encode($result));
    }

    public function testAppointmentChangesAndDiscussionInvalidateTheNativeCustomer(): void
    {
        $result = $this->routeGraph([
            'Contact:customer' => ['tenantId' => 'tenant'],
            'Appointment:visit' => ['tenantId' => 'tenant', 'customerId' => 'customer'],
            'Note:post' => ['parentType' => 'Appointment', 'parentId' => 'visit'],
        ], 'Note:post');
        self::assertArrayHasKey('Appointment:visit', $result['tenant']);
        self::assertArrayHasKey('Contact:customer', $result['tenant']);
        self::assertArrayHasKey('Note:post', $result['tenant']);
    }

    public function testCustomerReassignmentInvalidatesOldAndNewCollections(): void
    {
        $result = $this->routeGraph([
            'Contact:old' => ['tenantId' => 'tenant'],
            'Contact:new' => ['tenantId' => 'tenant'],
            'Appointment:visit' => ['tenantId' => 'tenant', 'customerId' => 'new'],
        ], 'Appointment:visit', ['Appointment:visit' => ['customerId' => 'old']]);
        self::assertArrayHasKey('Contact:old', $result['tenant']);
        self::assertArrayHasKey('Contact:new', $result['tenant']);
    }

    public function testExcludedRecordsAndTheirProvisionedDocumentsStaySilent(): void
    {
        $rows = [
            'Credential:credential' => ['tenantId' => 'tenant'],
            'Document:document' => ['tenantId' => 'tenant', 'knowledgeRecordType' => 'Credential', 'knowledgeRecordId' => 'credential'],
            'DocumentRevision:revision' => ['tenantId' => 'tenant', 'documentId' => 'document'],
            'Note:note' => ['tenantId' => 'tenant', 'parentType' => 'Credential', 'parentId' => 'credential'],
        ];
        foreach (array_keys($rows) as $start) {
            self::assertSame([], $this->routeGraph($rows, $start));
        }
    }

    public function testScopedDocumentRevisionAndRecordBindingReachTheirPrimaryRecord(): void
    {
        $rows = [
            'Contact:contact' => ['tenantId' => 'tenant'],
            'Document:document' => ['tenantId' => 'tenant', 'knowledgeRecordType' => 'Contact', 'knowledgeRecordId' => 'contact'],
            'DocumentRevision:revision' => ['tenantId' => 'tenant', 'documentId' => 'document'],
            'RecordDocument:binding' => ['recordType' => 'Contact', 'recordId' => 'contact'],
        ];
        foreach (['DocumentRevision:revision', 'RecordDocument:binding'] as $start) {
            $result = $this->routeGraph($rows, $start);
            self::assertArrayHasKey('Contact:contact', $result['tenant']);
            self::assertArrayHasKey($start, $result['tenant']);
        }
    }

    public function testUnrelatedReactionsDoNotInheritScopeFromTheirActor(): void
    {
        self::assertSame([], $this->routeGraph([
            'User:user' => ['tenantId' => 'tenant'],
            'Lead:lead' => ['tenantId' => 'tenant'],
            'Note:note' => ['parentType' => 'Lead', 'parentId' => 'lead'],
            'UserReaction:reaction' => ['userId' => 'user', 'parentType' => 'Note', 'parentId' => 'note'],
        ], 'UserReaction:reaction'));
    }

    public function testPlaybookAndStageDefinitionsInheritTheirDisplayedConfigurationOwner(): void
    {
        $rows = [
            'Playbook:book' => ['tenantId' => 'tenant'], 'PlaybookStep:step' => ['playbookId' => 'book'],
            'Journey:journey' => ['tenantId' => 'tenant'], 'JourneyStage:stage' => ['journeyId' => 'journey'],
            'InitiativeType:type' => ['tenantId' => 'tenant'], 'InitiativeStage:stage' => ['initiativeTypeId' => 'type'],
        ];
        foreach (['PlaybookStep:step', 'JourneyStage:stage', 'InitiativeStage:stage'] as $start) {
            self::assertArrayHasKey($start, $this->routeGraph($rows, $start)['tenant']);
        }
    }

    public function testUnrelatedAuditNotesAreSilentWhilePolicyChangesStillEmitResets(): void
    {
        $defs = ['attributes' => array_fill_keys(['id', 'tenantId', 'parentType', 'parentId'], ['type' => 'varchar'])];
        $note = new BaseEntity('Note', $defs);
        $note->set(['id' => 'audit-note', 'parentType' => 'Role', 'parentId' => 'shared-role']);
        $role = new BaseEntity('Role', $defs);
        $role->set('id', 'shared-role');
        $account = new BaseEntity('ChatwootAccount', $defs);
        $account->set(['id' => 'workspace', 'tenantId' => 'tenant']);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type) => $type === 'Role' ? $role : $note);
        $repository = $this->createMock(RDBRepository::class);
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('find')->willReturn(new EntityCollection([$account]));
        $repository->method('select')->willReturn($builder);
        $em->method('getRDBRepository')->with('ChatwootAccount')->willReturn($repository);
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE live_record_outbox (id TEXT, tenant_id TEXT, intent TEXT,
            created_at TEXT, due_at TEXT, attempts INTEGER)');
        $pdo->exec("CREATE TABLE role (id TEXT, data TEXT)");
        $pdo->exec("INSERT INTO role VALUES ('shared-role', 'old-policy')");
        $em->method('getPDO')->willReturn($pdo);
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->method('run')->willReturnCallback(function ($callback) use ($pdo) {
            $pdo->beginTransaction();
            try {
                $result = $callback();
                $pdo->commit();
                return $result;
            } catch (\Throwable $error) {
                $pdo->rollBack();
                throw $error;
            }
        });
        $em->method('getTransactionManager')->willReturn($transactions);
        $routing = new Routing($em, $this->createMock(TenantResolver::class), $this->createMock(UserTenantResolver::class));
        self::assertSame('saved', (new Observer($em, $routing))->run([$note], 'create', fn () => 'saved'));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn());
        self::assertSame('saved', (new Observer($em, $routing))->run([$role], 'update', function () use ($pdo) {
            $pdo->exec("UPDATE role SET data = 'new-policy'");
            return 'saved';
        }));
        $intent = json_decode($pdo->query('SELECT intent FROM live_record_outbox')->fetchColumn(), true);
        self::assertSame(['version' => 1, 'kind' => 'access', 'records' => []], $intent);
    }

    public function testNumericPlatformIdsDoNotBecomeCrmReferencesWhileActualLinksRemainRouted(): void
    {
        $defs = ['attributes' => ['id' => ['type' => 'varchar'], 'tenantId' => ['type' => 'varchar'],
            'accountId' => ['type' => 'int'], 'userId' => ['type' => 'int'], 'chatwootAccountId' => ['type' => 'varchar']]];
        $child = new BaseEntity('ChatwootAccountUserMembership', $defs);
        $child->set(['id' => 'membership', 'accountId' => 6, 'userId' => 24, 'chatwootAccountId' => 'crm-account']);
        $account = new BaseEntity('ChatwootAccount', $defs);
        $account->set(['id' => 'crm-account', 'accountId' => 6, 'tenantId' => 'tenant']);
        $lookups = [];
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(function (string $type, string $id) use (&$lookups, $child, $account) {
            $lookups[] = [$type, $id];
            return $id === 'membership' ? $child : ($id === 'crm-account' ? $account : null);
        });
        $routing = new Routing($em, $this->createMock(TenantResolver::class), $this->createMock(UserTenantResolver::class));
        $result = $routing->snapshot([$child]);
        self::assertArrayHasKey('ChatwootAccountUserMembership:membership', $result['tenant']);
        self::assertArrayHasKey('ChatwootAccount:crm-account', $result['tenant']);
        self::assertNotContains(['Account', '6'], $lookups);
        self::assertNotContains(['User', '24'], $lookups);
    }
}
