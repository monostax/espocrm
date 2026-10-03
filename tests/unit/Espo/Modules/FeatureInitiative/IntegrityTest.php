<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureInitiative;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureInitiative\Classes\RecordHooks\BlockRelationshipMutation;
use Espo\Modules\FeatureInitiative\Hooks\InitiativeType\PreventOrphans as TypeGuard;
use Espo\Modules\FeatureInitiative\Hooks\InitiativeStage\PreventOrphans as StageGuard;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\Repository\Option\RemoveOptions;
use PHPUnit\Framework\Attributes\DataProvider;

class IntegrityTest extends TestCase
{
    public static function relationships(): array
    {
        return array_map(fn ($link) => [$link], ['initiativeType', 'stage', 'stages', 'initiatives', 'teams', 'tenant', 'initiative', 'parent', 'relations', 'assignedUser']);
    }

    #[DataProvider('relationships')]
    public function testRelationshipEndpointsCannotBypassSaveValidation(string $link): void
    {
        $this->expectException(BadRequest::class);
        (new BlockRelationshipMutation())->process(
            $this->entity('InitiativeType'), $link, $this->entity('Initiative'),
        );
    }

    public static function guardedTypes(): array
    {
        return [['InitiativeType', TypeGuard::class], ['InitiativeStage', StageGuard::class]];
    }

    #[DataProvider('guardedTypes')]
    public function testCannotDeleteReferencedConfiguration(string $type, string $guardClass): void
    {
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('findOne')->willReturn($this->entity('Initiative'));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($select);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repository);
        $this->expectException(BadRequest::class);
        (new $guardClass($em))->beforeRemove($this->entity($type, ['id' => 'id-1']), RemoveOptions::fromAssoc([]));
    }

    #[DataProvider('guardedTypes')]
    public function testCanDeleteUnusedConfiguration(string $type, string $guardClass): void
    {
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('findOne')->willReturn(null);
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($select);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repository);
        (new $guardClass($em))->beforeRemove($this->entity($type, ['id' => 'id-1']), RemoveOptions::fromAssoc([]));
        $this->addToAssertionCount(1);
    }
}
