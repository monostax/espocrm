<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiSession;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Entities\User;
use Espo\Modules\FeatureAiSession\Services\AgentInstructions;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class AgentInstructionsTest extends TestCase
{
    private const TEXT = '<p>Seja informal.</p>';
    /** @var list<object> */
    private array $updates = [];

    private function entity(array $values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($values['id']);
        $entity->method('get')->willReturnCallback(fn ($key) => $values[$key] ?? null);
        return $entity;
    }

    private function service(bool $instanceAdmin, bool $actsForTenant = true, bool $isAI = true): AgentInstructions
    {
        $this->updates = [];
        $agent = $this->entity(['id' => 'm1', 'name' => 'Sofia', 'isAI' => $isAI, 'aiPrompt' => self::TEXT, 'chatwootAccountId' => 'acc1']);
        $account = $this->entity(['id' => 'acc1', 'tenantId' => 't1']);
        $builder = $this->createMock(RDBSelectBuilder::class);
        foreach (['order', 'limit'] as $method) $builder->method($method)->willReturnSelf();
        $builder->method('find')->willReturn(new EntityCollection([]));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($builder);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repository);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => match ($type) {
            'ChatwootAccountUserMembership' => $id === 'm1' ? $agent : null,
            'ChatwootAccount' => $account,
            default => null,
        });
        $user = $this->createMock(User::class);
        $user->method('isActive')->willReturn(true);
        $user->method('isAdmin')->willReturn($instanceAdmin);
        // A regular member: no role at all, so not a tenant admin.
        $user->method('getLinkMultipleIdList')->willReturn([]);
        $acl = $this->createMock(Acl::class);
        foreach (['checkEntityRead', 'checkEntityEdit', 'checkField'] as $method) $acl->method($method)->willReturn(true);
        $record = $this->createMock(Service::class);
        $record->method('update')->willReturnCallback(function ($id, $data) {
            $this->updates[] = $data;
            return $this->createMock(\Espo\Core\Record\UpdateResult::class);
        });
        $records = $this->createMock(ServiceContainer::class);
        $records->method('get')->willReturn($record);
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('canActForTenant')->willReturn($actsForTenant);
        return new AgentInstructions($em, $user, $acl, $records, $tenants);
    }

    public function testAMemberWithEditAccessReadsButCannotChange(): void
    {
        $service = $this->service(false);
        $read = $service->read('m1');
        $this->assertSame(self::TEXT, $read->text);
        $this->assertFalse($read->canEdit);
        $this->assertSame([], $read->changes);
        try {
            $service->write('m1', '<p>Seja formal.</p>', $read->hash);
            $this->fail('A member changed the instructions.');
        } catch (Forbidden) {}
        try {
            $service->restore('m1', 'rev1');
            $this->fail('A member restored the instructions.');
        } catch (Forbidden) {}
        $this->assertSame([], $this->updates);
    }

    public function testAnAdminChangesThroughTheRecordServiceOnlyFromTheTextTheyRead(): void
    {
        $service = $this->service(true);
        $read = $service->read('m1');
        $this->assertTrue($read->canEdit);
        try {
            $service->write('m1', '<p>Seja formal.</p>', hash('sha256', 'something else'));
            $this->fail('A stale change was saved.');
        } catch (Conflict) {}
        $this->assertSame([], $this->updates);
        $saved = $service->write('m1', '<p>Seja formal.</p>', $read->hash);
        $this->assertTrue($saved->changed);
        $this->assertEquals([(object) ['aiPrompt' => '<p>Seja formal.</p>']], $this->updates);
        $this->assertFalse($service->write('m1', self::TEXT, $read->hash)->changed);
    }

    public function testHumanMembersAndUnknownAgentsAreNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->service(true, true, false)->read('m1');
    }
}
