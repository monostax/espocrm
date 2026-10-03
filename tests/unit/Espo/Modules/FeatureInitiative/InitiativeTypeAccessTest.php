<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureInitiative;

use Espo\Core\Acl;
use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureInitiative\Services\InitiativeTypeAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class InitiativeTypeAccessTest extends TestCase
{
    private function access(?Entity $type, bool $allowed = true): InitiativeTypeAccess
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($type);
        $state = $this->createMock(ApplicationState::class);
        $state->method('isLogged')->willReturn(true);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntity')->willReturn($allowed);

        return new InitiativeTypeAccess($em, $state, $acl);
    }

    public function testRequiresExistingParent(): void
    {
        $this->expectException(BadRequest::class);
        $this->access(null)->requireParent($this->entity('Initiative', ['initiativeTypeId' => 'missing']), 'read');
    }

    public function testRequiresKnownTenant(): void
    {
        $this->expectException(BadRequest::class);
        $this->access($this->entity('InitiativeType'))->requireParent(
            $this->entity('Initiative', ['initiativeTypeId' => 'type-1']), 'read',
        );
    }

    public function testRejectsReparenting(): void
    {
        $record = $this->entity('Initiative', ['initiativeTypeId' => 'type-1'], true);
        $record->set('initiativeTypeId', 'type-2');
        $this->expectException(BadRequest::class);
        $this->access(null)->requireParent($record, 'read');
    }

    public function testRequiresParentAccess(): void
    {
        $type = $this->entity('InitiativeType', ['tenantId' => 'tenant-1']);
        $this->expectException(Forbidden::class);
        $this->access($type, false)->requireParent(
            $this->entity('InitiativeStage', ['initiativeTypeId' => 'type-1']), 'edit',
        );
    }

    public function testReturnsAccessibleParent(): void
    {
        $type = $this->entity('InitiativeType', ['tenantId' => 'tenant-1']);
        $result = $this->access($type)->requireParent(
            $this->entity('Initiative', ['initiativeTypeId' => 'type-1']), 'read',
        );
        $this->assertSame($type, $result);
    }

    public function testChildInheritsTenantFromInitiativeType(): void
    {
        $type = $this->entity('InitiativeType', ['tenantId' => 'tenant-1', 'tenantName' => 'Workspace 1']);
        $record = $this->entity('Initiative', ['initiativeTypeId' => 'type-1']);
        $this->access($type)->requireParent($record, 'read');
        $this->assertSame('tenant-1', $record->get('tenantId'));
        $this->assertSame('Workspace 1', $record->get('tenantName'));
    }

    public function testCannotOverrideChildTenant(): void
    {
        $type = $this->entity('InitiativeType', ['tenantId' => 'tenant-1']);
        $record = $this->entity('InitiativeStage', ['initiativeTypeId' => 'type-1', 'tenantId' => 'tenant-2']);
        $this->expectException(BadRequest::class);
        $this->access($type)->requireParent($record, 'edit');
    }
}
