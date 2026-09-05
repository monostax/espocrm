<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSimpleJourney;

use Espo\Core\Acl;
use Espo\Core\FieldProcessing\ListLoadProcessor;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\FieldProcessing\ReadLoadProcessor;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\Select\Applier;
use Espo\Core\Select\Select\MetadataProvider;
use Espo\Core\Utils\FieldUtil;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\FeatureSimpleJourney\Classes\FieldProcessing\AccessLoader;
use Espo\ORM\Defs;
use Espo\ORM\Defs\EntityDefs;
use Espo\ORM\Query\SelectBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

class AccessLoaderTest extends TestCase
{
    public static function responses(): array
    {
        $cases = [];
        foreach (['SimpleJourney', 'SimpleJourneyStage', 'SimpleJourneyRecord', 'SimpleJourneyRecordParent'] as $type) {
            foreach (['read', 'list'] as $mode) {
                $cases[] = [$type, $mode];
            }
        }
        return $cases;
    }

    #[DataProvider('responses')]
    public function testApiResponseProcessorsAlwaysEmitFreshPermissions(string $type, string $mode): void
    {
        $root = dirname(__DIR__, 5) . '/custom/Espo/Modules/FeatureSimpleJourney/Resources/metadata/';
        $recordDefs = json_decode(file_get_contents($root . "recordDefs/$type.json"), true);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(
            fn ($key) => is_array($key) && ($key[0] ?? '') === 'recordDefs' ? ($recordDefs[$key[2]] ?? []) : [],
        );
        $defs = $this->createMock(Defs::class);
        $defs->method('getEntity')->willReturn(EntityDefs::fromRaw(['fields' => []], $type));
        $entity = $this->entity($type, ['id' => 'row-1']);
        // Forged or stale flags must be overwritten, never echoed or persisted.
        $entity->set('simpleJourneyAccess', (object) ['delete' => true]);
        $access = ['read' => true, 'edit' => true, 'delete' => false, 'stream' => false];
        $acl = $this->createMock(Acl::class);
        $acl->expects($this->exactly(4))->method('checkEntity')->willReturnCallback(
            function ($checked, $action) use ($entity, $access) {
                $this->assertSame($entity, $checked);
                return $access[$action];
            },
        );
        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('createWithBinding')->with(AccessLoader::class, $this->anything())->willReturn(new AccessLoader($acl));
        $user = $this->createMock(User::class);
        $processor = $mode === 'read'
            ? new ReadLoadProcessor($factory, $metadata, $acl, $user, $defs)
            : new ListLoadProcessor($factory, $metadata, $acl, $user, $defs, $this->createMock(FieldUtil::class));
        $processor->process($entity, Params::create()->withSelect(['id', 'name']));
        $this->assertSame($access, (array) $entity->get('simpleJourneyAccess'));
        $this->assertTrue($recordDefs['loadAdditionalFieldsAfterUpdate']);
    }

    public function testRestrictedSelectIncludesInheritedAclInputs(): void
    {
        foreach (['SimpleJourneyStage', 'SimpleJourneyRecord', 'SimpleJourneyRecordParent'] as $type) {
            $path = dirname(__DIR__, 5) . "/custom/Espo/Modules/FeatureSimpleJourney/Resources/metadata/selectDefs/$type.json";
            $defs = json_decode(file_get_contents($path), true);
            $provider = $this->createMock(MetadataProvider::class);
            $provider->method('getAclAttributeList')->willReturn($defs['aclAttributeList']);
            $provider->method('hasAttribute')->willReturn(true);
            $applier = new Applier($type, $this->createMock(User::class), $this->createMock(FieldUtil::class), $provider);
            $query = SelectBuilder::create()->from($type);
            $applier->apply($query, SearchParams::fromRaw((object) ['select' => ['name']]));
            $select = $query->build()->getRaw()['select'];
            $this->assertContains('journeyId', $select);
            $this->assertContains('tenantId', $select);
            if ($type === 'SimpleJourneyRecordParent') {
                $this->assertContains('recordId', $select);
            }
        }
    }
}
