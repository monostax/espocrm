<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSimpleJourney;

use Espo\Core\Acl;
use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureSimpleJourney\Services\JourneyAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class JourneyAccessTest extends TestCase
{
    private function access(?Entity $journey, bool $allowed = true): JourneyAccess
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($journey);
        $state = $this->createMock(ApplicationState::class);
        $state->method('isLogged')->willReturn(true);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntity')->willReturn($allowed);

        return new JourneyAccess($em, $state, $acl);
    }

    public function testRequiresExistingParent(): void
    {
        $this->expectException(BadRequest::class);
        $this->access(null)->requireParent($this->entity('SimpleJourneyRecord', ['journeyId' => 'missing']), 'read');
    }

    public function testRequiresKnownTenant(): void
    {
        $this->expectException(BadRequest::class);
        $this->access($this->entity('SimpleJourney'))->requireParent(
            $this->entity('SimpleJourneyRecord', ['journeyId' => 'journey-1']), 'read',
        );
    }

    public function testRejectsReparenting(): void
    {
        $record = $this->entity('SimpleJourneyRecord', ['journeyId' => 'journey-1'], true);
        $record->set('journeyId', 'journey-2');
        $this->expectException(BadRequest::class);
        $this->access(null)->requireParent($record, 'read');
    }

    public function testRequiresParentAccess(): void
    {
        $journey = $this->entity('SimpleJourney', ['tenantId' => 'tenant-1']);
        $this->expectException(Forbidden::class);
        $this->access($journey, false)->requireParent(
            $this->entity('SimpleJourneyStage', ['journeyId' => 'journey-1']), 'edit',
        );
    }

    public function testReturnsAccessibleParent(): void
    {
        $journey = $this->entity('SimpleJourney', ['tenantId' => 'tenant-1']);
        $result = $this->access($journey)->requireParent(
            $this->entity('SimpleJourneyRecord', ['journeyId' => 'journey-1']), 'read',
        );
        $this->assertSame($journey, $result);
    }

    public function testChildInheritsTenantFromJourney(): void
    {
        $journey = $this->entity('SimpleJourney', ['tenantId' => 'tenant-1', 'tenantName' => 'Workspace 1']);
        $record = $this->entity('SimpleJourneyRecord', ['journeyId' => 'journey-1']);
        $this->access($journey)->requireParent($record, 'read');
        $this->assertSame('tenant-1', $record->get('tenantId'));
        $this->assertSame('Workspace 1', $record->get('tenantName'));
    }

    public function testCannotOverrideChildTenant(): void
    {
        $journey = $this->entity('SimpleJourney', ['tenantId' => 'tenant-1']);
        $record = $this->entity('SimpleJourneyStage', ['journeyId' => 'journey-1', 'tenantId' => 'tenant-2']);
        $this->expectException(BadRequest::class);
        $this->access($journey)->requireParent($record, 'edit');
    }
}
