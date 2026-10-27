<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureLiveRecords;

use Espo\Modules\FeatureLiveRecords\Persistence\Observer;
use Espo\Modules\FeatureLiveRecords\Persistence\Routing;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use Espo\ORM\Metadata;
use Espo\ORM\TransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;

/** Which link mutations reset a whole tenant, and which stay record-scoped. */
final class AccessIntentTest extends TestCase
{
    private PDO $pdo;
    private Observer $observer;
    private array $entities = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE live_record_outbox (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64),
            intent TEXT, created_at TIMESTAMP, due_at TIMESTAMP, attempts INTEGER)');
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->method('run')->willReturnCallback(function (callable $run) {
            $this->pdo->beginTransaction();
            $result = $run();
            $this->pdo->commit();
            return $result;
        });
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);
        $em->method('getTransactionManager')->willReturn($transactions);
        $em->method('getMetadata')->willReturn($this->createMock(Metadata::class));
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $this->entities["$type:$id"] ?? null);
        $tenants = $this->createMock(TenantResolver::class);
        $tenants->method('resolveAllFromTeamIds')->willReturnCallback(
            static fn (array $ids) => array_values(array_map(static fn ($id) => ['team-a' => 'tenant-a', 'team-b' => 'tenant-b'][$id], $ids)));
        $routing = new Routing($em, $tenants, $this->createMock(UserTenantResolver::class), ['Contact']);
        $this->observer = new Observer($em, $routing);
        $this->entity('Contact', 'contact-a', ['tenantId' => 'tenant-a']);
        $this->entity('Team', 'team-a');
        $this->entity('Team', 'team-b');
        $this->entity('User', 'user-a');
    }

    private function entity(string $type, string $id, array $values = []): BaseEntity
    {
        $attributes = ['id' => ['type' => 'varchar'], 'tenantId' => ['type' => 'varchar']];
        $entity = new BaseEntity($type, ['attributes' => $attributes, 'relations' => []]);
        $entity->set(['id' => $id, ...$values]);
        $entity->setAsNotNew();
        return $this->entities["$type:$id"] = $entity;
    }

    /** @return array<int, array{tenant: string, intent: array}> */
    private function link(string $operation, string ...$keys): array
    {
        $this->observer->run(array_map(fn ($key) => $this->entities[$key], $keys), $operation, static fn () => true);
        $rows = $this->pdo->query('SELECT tenant_id, intent FROM live_record_outbox ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn ($row) => ['tenant' => $row['tenant_id'],
            'intent' => json_decode($row['intent'], true, flags: JSON_THROW_ON_ERROR)], $rows);
    }

    public function testLinkingARecordToATeamIsRecordScoped(): void
    {
        $rows = $this->link('link', 'Contact:contact-a', 'Team:team-a');
        self::assertSame([['tenant' => 'tenant-a', 'intent' => ['version' => 1, 'kind' => 'link',
            'records' => [['type' => 'Contact', 'id' => 'contact-a']]]]], $rows);
    }

    public function testUnlinkingARecordFromATeamIsRecordScoped(): void
    {
        $rows = $this->link('unlink', 'Team:team-a', 'Contact:contact-a');
        self::assertSame('unlink', $rows[0]['intent']['kind']);
        self::assertSame([['type' => 'Contact', 'id' => 'contact-a']], $rows[0]['intent']['records']);
    }

    public function testAnotherTenantsTeamResetsThatTenantWithoutLeakingTheRecord(): void
    {
        $rows = $this->link('link', 'Contact:contact-a', 'Team:team-b');
        self::assertCount(2, $rows);
        self::assertSame(['tenant' => 'tenant-a', 'intent' => ['version' => 1, 'kind' => 'link',
            'records' => [['type' => 'Contact', 'id' => 'contact-a']]]], $rows[0]);
        // tenant-b only owns the team: a reset with no record identity from tenant-a.
        self::assertSame(['tenant' => 'tenant-b', 'intent' => ['version' => 1, 'kind' => 'access', 'records' => []]], $rows[1]);
    }

    public function testTeamMembershipLinksStayFullResets(): void
    {
        $rows = $this->link('link', 'Team:team-a', 'User:user-a');
        foreach ($rows as $row) {
            self::assertSame(['version' => 1, 'kind' => 'access', 'records' => []], $row['intent']);
        }
        self::assertNotEmpty($rows);
    }

    public function testRecordUpdatesWithATeamInTheBatchStayFullResets(): void
    {
        // Only link/unlink is narrowed; any other mutation touching a team resets.
        $rows = $this->link('delete', 'Contact:contact-a', 'Team:team-a');
        foreach ($rows as $row) self::assertSame('access', $row['intent']['kind']);
        self::assertNotEmpty($rows);
    }
}
