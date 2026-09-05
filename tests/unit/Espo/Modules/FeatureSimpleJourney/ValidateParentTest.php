<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSimpleJourney;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourneyRecordParent\ValidateParent;
use Espo\Modules\FeatureSimpleJourney\Services\JourneyAccess;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidateParentTest extends TestCase
{
    private EntityManager $em;
    private JourneyAccess $access;
    private BaseEntity $record;
    private ?BaseEntity $parent;
    private BaseEntity $account;
    private ?string $taskTenant = 'tenant-1';
    private bool $duplicate = false;
    private array $queriedLinks = [];

    protected function setUp(): void
    {
        $this->record = $this->entity('SimpleJourneyRecord', ['id' => 'source', 'journeyId' => 'journey-1', 'tenantId' => 'tenant-1']);
        $this->parent = $this->entity('Contact', ['id' => 'parent-1', 'tenantId' => 'tenant-1', 'name' => 'Parent']);
        $this->account = $this->entity('ChatwootAccount', ['id' => 'chatwoot-account', 'tenantId' => 'tenant-1']);
        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('getEntityById')->willReturnCallback(function ($type, $id) {
            if ($type === 'SimpleJourneyRecord' && $id === 'source') {
                return $this->record;
            }
            if ($type === 'ChatwootAccount' && $id === 'chatwoot-account') {
                return $this->account;
            }
            return $this->parent && $type === $this->parent->getEntityType() && $id === $this->parent->getId()
                ? $this->parent : null;
        });
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('findOne')->willReturnCallback(fn () => $this->duplicate ? $this->entity('SimpleJourneyRecordParent') : null);
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturnCallback(function ($where) use ($select) {
            $this->queriedLinks[] = $where;
            return $select;
        });
        $this->em->method('getRDBRepository')->willReturn($repository);
        $this->access = $this->createMock(JourneyAccess::class);
        $this->access->method('requireParent')->willReturnCallback(function (Entity $entity) {
            $entity->set('tenantId', 'tenant-1');
            return $this->entity('SimpleJourney', ['id' => 'journey-1', 'tenantId' => 'tenant-1']);
        });
    }

    private function hook(): ValidateParent
    {
        $teams = $this->createMock(TeamsAccess::class);
        $teams->method('entityTeamIds')->willReturn(['task-team']);
        $tenants = $this->createMock(TenantResolver::class);
        $tenants->method('resolveUniqueFromTeamIds')->willReturnCallback(fn () => $this->taskTenant);
        return new ValidateParent($this->em, $this->access, $teams, $tenants);
    }

    private function link(string $type = 'Contact', string $id = 'parent-1'): BaseEntity
    {
        return $this->entity('SimpleJourneyRecordParent', ['recordId' => 'source', 'parentType' => $type, 'parentId' => $id]);
    }

    public static function types(): array
    {
        return array_map(fn ($type) => [$type], ValidateParent::PARENT_TYPES);
    }

    #[DataProvider('types')]
    public function testAllSupportedParentTypesInheritSourceJourneyAndTenant(string $type): void
    {
        $this->parent = $this->entity($type, [
            'id' => 'parent-1', 'name' => 'Parent', 'tenantId' => 'tenant-1', 'chatwootAccountId' => 'chatwoot-account',
        ]);
        $link = $this->link($type);
        $this->access->expects($this->exactly(2))->method('assertReadable')->willReturnCallback(function ($entity, $action = 'read') {
            $this->assertSame($entity === $this->record ? 'edit' : 'read', $action);
        });
        $this->hook()->process($link);
        $this->assertSame('journey-1', $link->get('journeyId'));
        $this->assertSame('tenant-1', $link->get('tenantId'));
        $this->assertSame('Parent', $link->get('name'));
    }

    public function testCanAddManyParentsIncludingSeveralOfSameType(): void
    {
        $hook = $this->hook();
        $hook->process($this->link());
        $this->parent = $this->entity('Contact', ['id' => 'parent-2', 'tenantId' => 'tenant-1']);
        $hook->process($this->link('Contact', 'parent-2'));
        $this->parent = $this->entity('Account', ['id' => 'parent-2', 'tenantId' => 'tenant-1']);
        $hook->process($this->link('Account', 'parent-2'));
        $this->assertSame(['Contact', 'Contact', 'Account'], array_column($this->queriedLinks, 'parentType'));
        $this->assertSame(['parent-1', 'parent-2', 'parent-2'], array_column($this->queriedLinks, 'parentId'));
        $this->assertSame(['source', 'source', 'source'], array_column($this->queriedLinks, 'recordId'));
    }

    public function testCrossTenantParentIsRejected(): void
    {
        $this->parent->set('tenantId', 'tenant-2');
        $this->expectException(BadRequest::class);
        $this->hook()->process($this->link());
    }

    public function testConversationInAnotherTenantIsRejected(): void
    {
        $this->parent = $this->entity('ChatwootConversation', ['id' => 'parent-1', 'chatwootAccountId' => 'chatwoot-account']);
        $this->account->set('tenantId', 'tenant-2');
        $this->expectException(BadRequest::class);
        $this->hook()->process($this->link('ChatwootConversation'));
    }

    public function testTaskWithAmbiguousOrUnknownTenantIsRejected(): void
    {
        $this->parent = $this->entity('Task', ['id' => 'parent-1']);
        $this->taskTenant = null;
        $this->expectException(BadRequest::class);
        $this->hook()->process($this->link('Task'));
    }

    public function testTaskFromAnotherTenantIsRejected(): void
    {
        $this->parent = $this->entity('Task', ['id' => 'parent-1']);
        $this->taskTenant = 'tenant-2';
        $this->expectException(BadRequest::class);
        $this->hook()->process($this->link('Task'));
    }

    public function testMissingParentIsRejected(): void
    {
        $this->parent = null;
        $this->expectException(BadRequest::class);
        $this->hook()->process($this->link());
    }

    public function testUnsupportedTypeIsRejected(): void
    {
        $this->expectException(BadRequest::class);
        $this->hook()->process($this->link('User'));
    }

    public function testCannotLinkToSelf(): void
    {
        $this->expectException(BadRequest::class);
        $this->hook()->process($this->link('SimpleJourneyRecord', 'source'));
    }

    public function testDuplicateLinkIsRejected(): void
    {
        $this->duplicate = true;
        $this->expectException(BadRequest::class);
        $this->hook()->process($this->link());
    }

    public function testCannotMoveLinkToAnotherSourceRecord(): void
    {
        $link = $this->link();
        $link->setAsNotNew();
        $link->updateFetchedValues();
        $link->set('recordId', 'other-record');
        $this->expectException(BadRequest::class);
        $this->hook()->process($link);
    }

    public function testSourceRequiresEditPermission(): void
    {
        $this->access->method('assertReadable')->willThrowException(new Forbidden());
        $this->expectException(Forbidden::class);
        $this->hook()->process($this->link());
    }
}
