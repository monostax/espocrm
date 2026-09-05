<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSimpleJourney;

use Espo\Core\Acl;
use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\FieldProcessing\Relation\LinkMultipleSaver;
use Espo\Core\FieldProcessing\Saver\Params;
use Espo\Core\Hook\GeneralInvoker;
use Espo\Core\HookManager;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\ORM\EntityFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\Hook\Provider;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Core\Record\HookManager as RecordHookManager;
use Espo\Core\Repositories\Database;
use Espo\Core\Utils\Config\SystemConfig;
use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Id\RecordIdGenerator;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Module\PathProvider;
use Espo\Core\Utils\SystemUser;
use Espo\Entities\User;
use Espo\Modules\FeatureSimpleJourney\Hooks\Common\BlockRelationshipStubs;
use Espo\ORM\Defs;
use Espo\ORM\Defs\EntityDefs;
use Espo\ORM\Mapper\RDBMapper;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real API hook dispatch, repository save lifecycle and relation saver; no live database. */
class RelationshipStubsIntegrationTest extends TestCase
{
    public static function stubs(): array
    {
        $cases = [];
        foreach ([['SimpleJourney', 'stages'], ['SimpleJourney', 'records'], ['SimpleJourneyStage', 'records'], ['SimpleJourneyRecord', 'parents']] as [$type, $link]) {
            foreach (['Ids', 'Columns'] as $suffix) {
                $cases["$type.$link$suffix"] = [$type, $link, $suffix];
            }
        }
        return $cases;
    }

    private function payload(string $type, string $link, string $suffix): CoreEntity
    {
        $entity = new CoreEntity($type, [
            'attributes' => [
                'id' => ['type' => 'varchar'],
                'journeyId' => ['type' => 'varchar'],
                $link . 'Ids' => ['type' => 'jsonArray', 'notStorable' => true],
                $link . 'Columns' => ['type' => 'jsonObject', 'notStorable' => true],
            ],
            'relations' => [$link => ['type' => 'hasMany']],
        ]);
        // Exercise deserialized create payloads, including the column-only variant.
        $value = $suffix === 'Ids' ? ['existing-child'] : (object) ['existing-child' => (object) []];
        $entity->set(json_decode(json_encode([
            'id' => 'new-owner', 'journeyId' => 'valid-journey', $link . $suffix => $value,
        ])));
        return $entity;
    }

    private function apiHooks(string $type): RecordHookManager
    {
        $path = dirname(__DIR__, 5) . "/custom/Espo/Modules/FeatureSimpleJourney/Resources/metadata/recordDefs/$type.json";
        $defs = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(
            fn ($key, $default = null) => $key === "recordDefs.$type.earlyBeforeCreateHookClassNameList"
                ? $defs['earlyBeforeCreateHookClassNameList'] : ($default ?? []),
        );
        $factory = $this->createMock(InjectableFactory::class);
        $noop = $this->createMock(SaveHook::class);
        $factory->method('createWithBinding')->willReturnCallback(
            fn ($class) => $class === BlockRelationshipStubs::class ? new BlockRelationshipStubs() : $noop,
        );
        return new RecordHookManager(new Provider(
            $metadata, $factory, $this->createMock(Acl::class), $this->createMock(User::class),
        ));
    }

    private function ormHooks(): HookManager
    {
        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->with(BlockRelationshipStubs::class)->willReturn(new BlockRelationshipStubs());
        $cache = $this->createMock(DataCache::class);
        $cache->method('has')->willReturn(true);
        $cache->method('get')->willReturn(['Common' => ['beforeSave' => [[
            'className' => BlockRelationshipStubs::class, 'order' => BlockRelationshipStubs::$order,
        ]]]]);
        $config = $this->createMock(SystemConfig::class);
        $config->method('useCache')->willReturn(true);
        return new HookManager(
            $factory, $this->createMock(FileManager::class), $this->createMock(Metadata::class),
            $cache, $this->createMock(Log::class), $this->createMock(PathProvider::class), new GeneralInvoker(), $config,
        );
    }

    #[DataProvider('stubs')]
    public function testCreateApiRejectsStubsBeforeRelationshipProcessing(string $type, string $link, string $suffix): void
    {
        $entity = $this->payload($type, $link, $suffix);
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('getRDBRepository');
        $this->expectException(BadRequest::class);
        $this->apiHooks($type)->processEarlyBeforeCreate($entity, new CreateParams());
        (new LinkMultipleSaver($em))->process($entity, $link, new Params());
    }

    #[DataProvider('stubs')]
    public function testOrmSaveRejectsStubsBeforeAnyInsertOrChildMutation(string $type, string $link, string $suffix): void
    {
        $entity = $this->payload($type, $link, $suffix);
        $em = $this->createMock(EntityManager::class);
        $mapper = $this->createMock(RDBMapper::class);
        $mapper->expects($this->never())->method('insert');
        $mapper->expects($this->never())->method('update');
        $em->method('getMapper')->willReturn($mapper);
        $repository = new Database(
            $type, $em, $this->createMock(EntityFactory::class), $this->createMock(Metadata::class),
            $this->ormHooks(), $this->createMock(ApplicationState::class), $this->createMock(RecordIdGenerator::class),
            $this->createMock(SystemUser::class), null,
        );
        $this->expectException(BadRequest::class);
        // Silent internal saves must not accidentally bypass this guard either.
        $repository->save($entity, ['silent' => true]);
    }

    #[DataProvider('stubs')]
    public function testUnguardedFrameworkSaverWouldRelateExistingChild(string $type, string $link, string $suffix): void
    {
        $entity = $this->payload($type, $link, $suffix);
        $this->assertFalse($entity->hasLinkMultipleField($link));
        $em = $this->createMock(EntityManager::class);
        $defs = $this->createMock(Defs::class);
        $defs->method('getEntity')->willReturn(EntityDefs::fromRaw(['fields' => []], $type));
        $em->method('getDefs')->willReturn($defs);
        $relation = $this->createMock(RDBRelation::class);
        $relation->expects($this->once())->method('relateById')->with('existing-child', null, $this->anything());
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('getRelation')->with($entity, $link)->willReturn($relation);
        $em->method('getRDBRepository')->willReturn($repository);
        (new LinkMultipleSaver($em))->process($entity, $link, new Params());
    }

    public function testNormalChildForeignKeyAndEmptyListsAreAllowed(): void
    {
        $entity = $this->payload('SimpleJourneyStage', 'records', 'Ids');
        $entity->set('recordsIds', []);
        $entity->set('recordsColumns', (object) []);
        $this->apiHooks('SimpleJourneyStage')->processEarlyBeforeCreate($entity, new CreateParams());
        $this->ormHooks()->process('SimpleJourneyStage', 'beforeSave', $entity);
        $this->assertSame('valid-journey', $entity->get('journeyId'));
    }
}
