<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureLiveRecords;

use Espo\Modules\FeatureLiveRecords\Classes\Metadata\LiveRecords;
use Espo\Modules\FeatureLiveRecords\Persistence\Observer;
use Espo\Modules\FeatureLiveRecords\Scope;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    public function testOnlyExplicitSurfaceTypesAndDependenciesAreRegistered(): void
    {
        $excluded = ['Lead', 'Credential', 'AutomationRun', 'AiSession', 'Appointment', 'UnrelatedFutureRecord'];
        $data = (object) ['entityDefs' => (object) [], 'scopes' => (object) []];
        foreach ([...Scope::all(), ...$excluded] as $type) {
            $data->entityDefs->$type = (object) ['fields' => (object) ['name' => (object) ['type' => 'varchar']]];
            $data->scopes->$type = (object) ['entity' => true, 'object' => true, 'tab' => true];
        }
        (new LiveRecords())->build($data);
        self::assertSame(Scope::RECORD_TYPES, $data->app->liveRecords->recordTypes);
        self::assertCount(8, $data->app->liveRecords->recordTypes);
        foreach (Scope::all() as $type) {
            self::assertSame(Observer::class, $data->entityDefs->$type->mutationObserverClassName);
            self::assertObjectNotHasProperty('optimisticConcurrencyControl', $data->entityDefs->$type);
            self::assertObjectNotHasProperty('atomicConcurrencyControl', $data->entityDefs->$type);
        }
        foreach ([...Scope::RECORD_TYPES, 'ContactChannelIdentity'] as $type) {
            self::assertTrue($data->entityDefs->$type->versionNumberEnabled);
        }
        foreach ($excluded as $type) {
            self::assertObjectNotHasProperty('mutationObserverClassName', $data->entityDefs->$type);
            self::assertObjectNotHasProperty('atomicConcurrencyControl', $data->entityDefs->$type);
        }
        self::assertContains('ContactChannelIdentity', $data->app->liveRecords->dependencyTypes);
        self::assertContains('Document', $data->app->liveRecords->dependencyTypes);
        self::assertNotContains('Document', $data->app->liveRecords->recordTypes);
        self::assertContains('Tenant', $data->app->liveRecords->accessTypes);
        self::assertNotContains('Tenant', $data->app->liveRecords->recordTypes);
    }

    public function testDisabledAndVirtualScopesAreNotRegistered(): void
    {
        $data = (object) ['entityDefs' => (object) ['Task' => (object) [], 'Meeting' => (object) []],
            'scopes' => (object) ['Task' => (object) ['disabled' => true], 'Meeting' => (object) ['isVirtual' => true]]];
        (new LiveRecords())->build($data);
        self::assertSame([], $data->app->liveRecords->recordTypes);
        self::assertObjectNotHasProperty('mutationObserverClassName', $data->entityDefs->Task);
        self::assertObjectNotHasProperty('mutationObserverClassName', $data->entityDefs->Meeting);
    }
}
