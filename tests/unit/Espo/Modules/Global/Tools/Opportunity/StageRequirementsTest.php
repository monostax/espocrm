<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Opportunity;

use Espo\Core\Exceptions\ConflictSilent;
use Espo\Modules\Global\Tools\CustomField\MetaProvider;
use Espo\Modules\Global\Tools\Opportunity\StageRequirements;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class StageRequirementsTest extends TestCase
{
    public function testPresenceAndTypedConstraints(): void
    {
        foreach ([
            ['text', ' ', 'required'], ['text', null, 'required'], ['text', false, 'valid'],
            ['text', 'Hello', null], ['int', 0, null], ['int', '0', 'valid'],
            ['int', 1.5, 'valid'], ['int', -1, 'range'], ['int', 21, 'range'],
            ['float', 0.5, null], ['float', INF, 'valid'],
            ['bool', false, null], ['bool', null, 'required'], ['bool', 'false', 'valid'],
            ['enum', 'a', null], ['enum', 'unknown', 'valid'],
            ['multiEnum', [], 'required'], ['multiEnum', ['a'], null],
            ['multiEnum', ['a', 'bad'], 'valid'], ['multiEnum', [false], 'valid'],
            ['date', '2026-02-29', 'valid'], ['date', '2028-02-29', null],
            ['datetime', '2026-09-29 10:00:00', null], ['datetime', '2026-09-29', 'valid'],
        ] as [$type, $value, $reason]) {
            $field = ['type' => $type, 'options' => ['a', 'b'], 'min' => 0, 'max' => 20];
            $this->assertSame($reason, StageRequirements::invalidReason($field, $value), $type);
        }
        $this->assertSame('maxLength', StageRequirements::invalidReason(['type' => 'text', 'maxLength' => 2], 'abc'));
    }

    public function testConfigurationUsesOnlyTheFunnelsOpportunitySchema(): void
    {
        $em = $this->createMock(EntityManager::class);
        $funnel = $this->entity('Funnel', ['tenantId' => 'tenant']);
        $em->method('getEntityById')->with('Funnel', 'funnel')->willReturn($funnel);
        $meta = $this->createMock(MetaProvider::class);
        $meta->expects($this->once())->method('getGroupedMeta')->with('Opportunity', 'tenant')->willReturn([
            'groups' => [['label' => 'General', 'fields' => [['valueKey' => 'budget', 'type' => 'int']]]],
        ]);
        $stage = $this->entity('OpportunityStage', ['id' => 'stage', 'funnelId' => 'funnel',
            'requiredCustomFieldKeys' => ['otherTenantField']]);
        $this->expectException(ConflictSilent::class);
        (new StageRequirements($em, $meta, $this->createMock(\Espo\Core\Acl::class)))->getFields($stage);
    }

    private function entity(string $type, array $data): BaseEntity
    {
        $attributes = array_fill_keys(['id', 'tenantId', 'funnelId', 'name'], ['type' => 'varchar']);
        $attributes['requiredCustomFieldKeys'] = ['type' => 'jsonArray'];
        $entity = new BaseEntity($type, ['attributes' => $attributes]);
        $entity->set($data);
        return $entity;
    }
}
