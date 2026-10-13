<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Acl;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Enrichment\ApplicationRecords;
use Espo\Modules\FeatureRecordKnowledge\Services\Relations;
use Espo\ORM\{BaseEntity,EntityCollection,EntityManager};
use Espo\ORM\Repository\{RDBRepository,RDBRelation};
use PHPUnit\Framework\TestCase;

class EnrichmentCreationFormTest extends TestCase
{
    private function form(array $definitions,?string $denied = null): array
    {
        $em = $this->createMock(EntityManager::class);
        $owner = new BaseEntity('Tenant',['attributes' => ['baseUserTeamId' => ['type' => 'varchar']]]);
        $owner->set('baseUserTeamId','base');
        $teams = [];
        foreach (['base','other','hidden'] as $id) {
            $team = new BaseEntity('Team',['attributes' => ['name' => ['type' => 'varchar']]]);
            $team->set('id',$id); $team->set('name',strtoupper($id)); $teams[$id] = $team;
        }
        $em->method('getEntityById')->willReturnCallback(fn ($type,$id) => $type === 'Tenant' ? $owner : ($teams[$id] ?? null));
        $relation = $this->createMock(RDBRelation::class);
        $relation->method('find')->willReturn(new EntityCollection([$teams['other'],$teams['hidden']]));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('getRelation')->with($owner,'otherUserTeams')->willReturn($relation);
        $em->method('getRDBRepository')->with('Tenant')->willReturn($repository);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkField')->willReturnCallback(fn ($type,$field) => $field !== $denied);
        $acl->method('check')->willReturnCallback(fn ($entity) => $entity->getId() !== 'hidden');
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(fn ($path,$default = null) =>
            count($path) === 3 ? $definitions : ($definitions['teams']['required'] ?? false));
        return (new ApplicationRecords($em,$this->createMock(ServiceContainer::class),$this->createMock(Relations::class),
            $this->createMock(User::class),$acl,$metadata))->creationForm('tenant');
    }

    public function testRequiredScalarFieldsAndReadableTenantTeamsOnly(): void
    {
        $result = $this->form(['lastName' => ['type' => 'varchar'],
            'cConsent' => ['type' => 'bool','required' => true],
            'cLevel' => ['type' => 'enum','required' => true,'options' => ['A','B']],
            'optional' => ['type' => 'varchar']]);
        self::assertTrue($result['available']);
        self::assertSame(['lastName','cConsent','cLevel'],array_column($result['fields'],'name'));
        self::assertTrue($result['fields'][0]['required']);
        self::assertSame(['A','B'],$result['fields'][2]['options']);
        self::assertSame(['base','other'],array_column($result['teams'],'id'));
    }

    public function testRequiredDeniedOrUnsupportedFieldDisablesCreationWithoutExposingIt(): void
    {
        $definitions = ['lastName' => ['type' => 'varchar'],'secret' => ['type' => 'varchar','required' => true]];
        $result = $this->form($definitions,'secret');
        self::assertFalse($result['available']);
        self::assertSame(['lastName'],array_column($result['fields'],'name'));
        $definitions['secret']['type'] = 'link';
        self::assertFalse($this->form($definitions)['available']);
    }

    public function testRequiredTeamsCannotBeBypassedWithHiddenTeamNames(): void
    {
        $result = $this->form(['teams' => ['required' => true]],'name');
        self::assertFalse($result['available']);
        self::assertSame([],$result['teams']);
    }
}
