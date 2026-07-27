<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace tests\unit\Espo\Modules\Chatwoot\Tools;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\Modules\Chatwoot\Tools\ContactImportUpdateEntityFinder;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class ContactImportUpdateEntityFinderTest extends TestCase
{
    private function createUser(): User
    {
        $user = $this->createMock(User::class);
        $user->method('get')->with('defaultTeamId')->willReturn('team-1');
        $user->method('isSystem')->willReturn(false);

        return $user;
    }

    /**
     * Tenant resolution now lives in TenantResolver / UserTenantResolver (both
     * covered by their own tests). This suite exercises the finder's matching
     * and authorisation logic, so membership is stubbed: the user belongs to
     * tenant-1, and teams resolve to tenant-1.
     *
     * @return array{TenantResolver, UserTenantResolver}
     */
    private function createTenantResolvers(): array
    {
        $tenantResolver = $this->createMock(TenantResolver::class);
        $tenantResolver->method('resolveAllFromTeamIds')->willReturn(['tenant-1']);

        $userTenantResolver = $this->createMock(UserTenantResolver::class);
        $userTenantResolver->method('resolveTenantIds')->willReturn(['tenant-1']);

        return [$tenantResolver, $userTenantResolver];
    }

    /** @return RDBRepository<Entity> */
    private function createTenantRepository(): RDBRepository
    {
        $tenant = $this->createMock(Entity::class);
        $tenant->method('getId')->willReturn('tenant-1');

        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('where')
            ->with(['baseUserTeamId' => 'team-1'])
            ->willReturnSelf();
        $builder->method('findOne')->willReturn($tenant);

        $repository = $this->createMock(RDBRepository::class);
        $repository->method('select')->with(['id'])->willReturn($builder);

        return $repository;
    }

    public function testFindsContactByNormalizedWhatsAppNumberAndDirectField(): void
    {
        $identity = $this->createMock(Entity::class);
        $identity->method('get')->with('contactId')->willReturn('contact-1');

        $contact = $this->createMock(CoreEntity::class);
        $user = $this->createUser();
        $aclManager = $this->createMock(AclManager::class);
        $aclManager->expects($this->once())
            ->method('checkEntityEdit')
            ->with($user, $contact)
            ->willReturn(true);

        $identityBuilder = $this->createMock(RDBSelectBuilder::class);
        $identityBuilder->expects($this->once())
            ->method('where')
            ->with([
                'tenantId' => 'tenant-1',
                'channelType' => 'whatsapp',
                'sourceId' => '+5516993921469',
            ])
            ->willReturnSelf();
        $identityBuilder->method('find')->willReturn(new EntityCollection([$identity]));

        $identityRepository = $this->createMock(RDBRepository::class);
        $identityRepository->expects($this->once())
            ->method('select')
            ->with(['contactId'])
            ->willReturn($identityBuilder);

        $contactBuilder = $this->createMock(RDBSelectBuilder::class);
        $contactBuilder->expects($this->once())
            ->method('find')
            ->willReturn(new EntityCollection([$contact]));

        $contactRepository = $this->createMock(RDBRepository::class);
        $contactRepository->expects($this->once())
            ->method('where')
            ->with([
                'lastName' => 'Silva',
                'id' => ['contact-1'],
            ])
            ->willReturn($contactBuilder);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->willReturnMap([
            ['Tenant', $this->createTenantRepository()],
            ['ContactChannelIdentity', $identityRepository],
            ['Contact', $contactRepository],
        ]);

        [$tenantResolver, $userTenantResolver] = $this->createTenantResolvers();

        $finder = new ContactImportUpdateEntityFinder(
            $entityManager,
            $aclManager,
            $tenantResolver,
            $userTenantResolver,
        );

        $this->assertSame($contact, $finder->find([
            'whatsappNumber' => '(16) 99392-1469',
            'lastName' => 'Silva',
            'id' => 'contact-1',
        ], $user, (object) []));
    }

    public function testFindsContactByAnyOfMultipleWhatsAppHelperColumns(): void
    {
        $identity = $this->createMock(Entity::class);
        $identity->method('get')->with('contactId')->willReturn('contact-1');

        $contact = $this->createMock(CoreEntity::class);
        $user = $this->createUser();
        $aclManager = $this->createMock(AclManager::class);
        $aclManager->expects($this->once())
            ->method('checkEntityEdit')
            ->with($user, $contact)
            ->willReturn(true);

        $identityBuilder = $this->createMock(RDBSelectBuilder::class);
        $identityBuilder->expects($this->exactly(2))
            ->method('where')
            ->willReturnCallback(function (array $where) use ($identityBuilder) {
                $this->assertSame('tenant-1', $where['tenantId']);
                $this->assertSame('whatsapp', $where['channelType']);
                $this->assertContains($where['sourceId'], [
                    '+5516993921469',
                    '+5516999990001',
                ]);

                return $identityBuilder;
            });
        // First number misses; second hits — OR within channel must still match.
        $identityBuilder->expects($this->exactly(2))
            ->method('find')
            ->willReturnOnConsecutiveCalls(
                new EntityCollection([]),
                new EntityCollection([$identity]),
            );

        $identityRepository = $this->createMock(RDBRepository::class);
        $identityRepository->expects($this->exactly(2))
            ->method('select')
            ->with(['contactId'])
            ->willReturn($identityBuilder);

        $contactBuilder = $this->createMock(RDBSelectBuilder::class);
        $contactBuilder->expects($this->once())
            ->method('find')
            ->willReturn(new EntityCollection([$contact]));

        $contactRepository = $this->createMock(RDBRepository::class);
        $contactRepository->expects($this->once())
            ->method('where')
            ->with(['id' => ['contact-1']])
            ->willReturn($contactBuilder);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->willReturnMap([
            ['Tenant', $this->createTenantRepository()],
            ['ContactChannelIdentity', $identityRepository],
            ['Contact', $contactRepository],
        ]);

        [$tenantResolver, $userTenantResolver] = $this->createTenantResolvers();

        $finder = new ContactImportUpdateEntityFinder(
            $entityManager,
            $aclManager,
            $tenantResolver,
            $userTenantResolver,
        );

        $this->assertSame($contact, $finder->find([
            'whatsappNumber' => '+5516993921469',
            'whatsappNumber2' => '+5516999990001',
        ], $user, (object) []));
    }

    public function testFindsInstagramHandleByHandleOrLegacySourceId(): void
    {
        $identity = $this->createMock(Entity::class);
        $identity->method('get')->with('contactId')->willReturn('contact-1');

        $contact = $this->createMock(CoreEntity::class);
        $user = $this->createUser();
        $aclManager = $this->createMock(AclManager::class);
        $aclManager->method('checkEntityEdit')->willReturn(true);

        $identityBuilder = $this->createMock(RDBSelectBuilder::class);
        $identityBuilder->expects($this->once())
            ->method('where')
            ->with([
                'tenantId' => 'tenant-1',
                'channelType' => 'instagram',
                'OR' => [
                    ['sourceId' => 'maria_oliveira'],
                    ['handle' => 'maria_oliveira'],
                ],
            ])
            ->willReturnSelf();
        $identityBuilder->method('find')->willReturn(new EntityCollection([$identity]));

        $identityRepository = $this->createMock(RDBRepository::class);
        $identityRepository->method('select')->willReturn($identityBuilder);

        $contactBuilder = $this->createMock(RDBSelectBuilder::class);
        $contactBuilder->method('find')->willReturn(new EntityCollection([$contact]));

        $contactRepository = $this->createMock(RDBRepository::class);
        $contactRepository->method('where')->willReturn($contactBuilder);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->willReturnMap([
            ['Tenant', $this->createTenantRepository()],
            ['ContactChannelIdentity', $identityRepository],
            ['Contact', $contactRepository],
        ]);

        [$tenantResolver, $userTenantResolver] = $this->createTenantResolvers();

        $finder = new ContactImportUpdateEntityFinder(
            $entityManager,
            $aclManager,
            $tenantResolver,
            $userTenantResolver,
        );

        $this->assertSame(
            $contact,
            $finder->find(['instagramHandle' => ' @Maria_Oliveira '], $user, (object) [])
        );
    }

    public function testExplicitIdMustAlsoMatchIdentityContact(): void
    {
        $identity = $this->createMock(Entity::class);
        $identity->method('get')->with('contactId')->willReturn('contact-1');

        $identityBuilder = $this->createMock(RDBSelectBuilder::class);
        $identityBuilder->method('where')->willReturnSelf();
        $identityBuilder->method('find')->willReturn(new EntityCollection([$identity]));

        $identityRepository = $this->createMock(RDBRepository::class);
        $identityRepository->method('select')->willReturn($identityBuilder);

        $contactRepository = $this->createMock(RDBRepository::class);
        $contactRepository->expects($this->never())->method('where');

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->willReturnMap([
            ['Tenant', $this->createTenantRepository()],
            ['ContactChannelIdentity', $identityRepository],
            ['Contact', $contactRepository],
        ]);

        [$tenantResolver, $userTenantResolver] = $this->createTenantResolvers();

        $finder = new ContactImportUpdateEntityFinder(
            $entityManager,
            $this->createMock(AclManager::class),
            $tenantResolver,
            $userTenantResolver,
        );

        $this->assertNull($finder->find([
            'id' => 'contact-2',
            'whatsappNumber' => '+5516993921469',
        ], $this->createUser(), (object) []));
    }

    public function testExplicitTargetTenantOverridesUserDefaultTenant(): void
    {
        $user = $this->createMock(User::class);
        $user->method('get')->with('defaultTeamId')->willReturn('team-1');
        $user->method('getTeamIdList')->willReturn([]);
        $user->method('isAdmin')->willReturn(true);

        $identityBuilder = $this->createMock(RDBSelectBuilder::class);
        $identityBuilder->expects($this->once())
            ->method('where')
            ->with([
                'tenantId' => 'tenant-2',
                'channelType' => 'whatsapp',
                'sourceId' => '+5516993921469',
            ])
            ->willReturnSelf();
        $identityBuilder->method('find')->willReturn(new EntityCollection());

        $identityRepository = $this->createMock(RDBRepository::class);
        $identityRepository->method('select')->willReturn($identityBuilder);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')
            ->with('Tenant', 'tenant-2')
            ->willReturn($this->createMock(Entity::class));
        $entityManager->method('getRDBRepository')->willReturnMap([
            ['ContactChannelIdentity', $identityRepository],
        ]);

        [$tenantResolver, $userTenantResolver] = $this->createTenantResolvers();

        $finder = new ContactImportUpdateEntityFinder(
            $entityManager,
            $this->createMock(AclManager::class),
            $tenantResolver,
            $userTenantResolver,
        );

        $this->assertNull($finder->find(
            ['whatsappNumber' => '+5516993921469'],
            $user,
            (object) ['tenantId' => 'tenant-2'],
        ));
    }

    public function testRejectsConflictingTargetTenantAndJsonTeamList(): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')
            ->with('Tenant')
            ->willReturn($this->createTenantRepository());

        [$tenantResolver, $userTenantResolver] = $this->createTenantResolvers();

        $finder = new ContactImportUpdateEntityFinder(
            $entityManager,
            $this->createMock(AclManager::class),
            $tenantResolver,
            $userTenantResolver,
        );

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('conflicts with the target teams');

        $finder->find(
            ['whatsappNumber' => '+5516993921469'],
            $this->createUser(),
            (object) [
                'tenantId' => 'tenant-2',
                'teamsIds' => '["team-1"]',
            ],
        );
    }

    public function testRejectsInvalidWhatsAppMatchValue(): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->with('Tenant')->willReturn($this->createTenantRepository());

        [$tenantResolver, $userTenantResolver] = $this->createTenantResolvers();

        $finder = new ContactImportUpdateEntityFinder(
            $entityManager,
            $this->createMock(AclManager::class),
            $tenantResolver,
            $userTenantResolver,
        );

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Invalid phone number');

        $finder->find(['whatsappNumber' => 'not-a-phone'], $this->createUser(), (object) []);
    }

    public function testRejectsAmbiguousEditableContacts(): void
    {
        $identity1 = $this->createMock(Entity::class);
        $identity1->method('get')->with('contactId')->willReturn('contact-1');
        $identity2 = $this->createMock(Entity::class);
        $identity2->method('get')->with('contactId')->willReturn('contact-2');

        $contact1 = $this->createMock(CoreEntity::class);
        $contact2 = $this->createMock(CoreEntity::class);

        $identityBuilder = $this->createMock(RDBSelectBuilder::class);
        $identityBuilder->method('where')->willReturnSelf();
        $identityBuilder->method('find')->willReturn(new EntityCollection([$identity1, $identity2]));

        $identityRepository = $this->createMock(RDBRepository::class);
        $identityRepository->method('select')->willReturn($identityBuilder);

        $contactBuilder = $this->createMock(RDBSelectBuilder::class);
        $contactBuilder->method('find')->willReturn(new EntityCollection([$contact1, $contact2]));

        $contactRepository = $this->createMock(RDBRepository::class);
        $contactRepository->method('where')->willReturn($contactBuilder);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->willReturnMap([
            ['Tenant', $this->createTenantRepository()],
            ['ContactChannelIdentity', $identityRepository],
            ['Contact', $contactRepository],
        ]);

        $user = $this->createUser();
        $aclManager = $this->createMock(AclManager::class);
        $aclManager->method('checkEntityEdit')->willReturn(true);

        [$tenantResolver, $userTenantResolver] = $this->createTenantResolvers();

        $finder = new ContactImportUpdateEntityFinder(
            $entityManager,
            $aclManager,
            $tenantResolver,
            $userTenantResolver,
        );

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('multiple editable contacts');

        $finder->find(['whatsappNumber' => '+5516993921469'], $user, (object) []);
    }
}
