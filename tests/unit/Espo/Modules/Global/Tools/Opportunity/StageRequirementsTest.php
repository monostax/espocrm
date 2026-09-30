<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Opportunity;

use Espo\Core\Exceptions\ConflictSilent;
use Espo\Core\Exceptions\Conflict;
use Espo\Modules\Global\Tools\CustomField\MetaProvider;
use Espo\Modules\Global\Tools\Opportunity\StageRequirements;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
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

    public function testDestinationScopeCollectsMissingFieldsAndPreservesGateSemantics(): void
    {
        [$service, $opportunity] = $this->fixture([
            'appliesWhen' => ['attribute' => 'opportunityStageId', 'operator' => 'equals', 'value' => 'proposal'],
        ]);
        try {
            $service->validate($opportunity);
            $this->fail('Destination field must be collected.');
        } catch (ConflictSilent $e) {
            $body = json_decode($e->getBody(), true);
            $this->assertSame('opportunityStageRequirements', $body['code']);
            $this->assertSame('stageEntry', $body['requirementMode']);
            $this->assertSame('budget', $body['fields'][0]['valueKey']);
        }
        $opportunity->set('customFields', (object) ['budget' => 0, 'hidden' => 'keep']);
        $service->validate($opportunity);
        $this->assertSame('keep', $opportunity->get('customFields')->hidden);
        $opportunity->setAsFetched();
        $opportunity->set('customFields', (object) []);
        $service->validate($opportunity); // Entry-only requirement is not an ordinary-save invariant.
        $this->addToAssertionCount(1);
    }

    public function testRuntimeConditionCanSkipAnOtherwiseCompatibleStageRequirement(): void
    {
        [$service, $opportunity] = $this->fixture([
            'appliesWhen' => ['attribute' => 'status', 'operator' => 'equals', 'value' => 'Won'],
        ]);
        $service->validate($opportunity); // Status Open: no prompt.
        $opportunity->set('status', 'Won');
        $this->expectException(ConflictSilent::class);
        $service->validate($opportunity);
    }

    public function testRequiredWhenIsEnforcedOnOrdinarySavesUsingTheStoredBag(): void
    {
        [$service, $opportunity] = $this->fixture([
            'requiredWhen' => ['attribute' => 'opportunityStageId', 'operator' => 'equals', 'value' => 'proposal'],
        ], []);
        $opportunity->setAsFetched();
        $opportunity->set('name', 'An ordinary edit');
        try {
            $service->validate($opportunity);
            $this->fail('Ordinary-save requirements must be enforced.');
        } catch (ConflictSilent $e) {
            $this->assertSame('save', json_decode($e->getBody(), true)['requirementMode']);
        }
    }

    public function testIncompatibleStageConfigurationFailsRatherThanSilentlySkipping(): void
    {
        [$service, $opportunity] = $this->fixture([
            'appliesWhen' => ['attribute' => 'funnelId', 'operator' => 'equals', 'value' => 'another-funnel'],
        ]);
        try {
            $service->validate($opportunity);
            $this->fail('Incompatible requirement must be rejected.');
        } catch (ConflictSilent $e) {
            $this->assertSame('opportunityStageConfiguration', json_decode($e->getBody(), true)['code']);
        }
    }

    public function testDirectHostFieldConditionAppliesToDestinationStageGate(): void
    {
        [$service, $opportunity] = $this->fixture([
            'appliesWhen' => ['attribute' => 'accountId', 'operator' => 'equals', 'value' => 'account-a'],
        ]);
        $opportunity->set('accountId', 'account-b');
        $service->validate($opportunity);
        $opportunity->set('accountId', 'account-a');
        $this->expectException(ConflictSilent::class);
        $service->validate($opportunity);
    }

    public function testCompletionRejectsConcurrentChangesToConditionDependencies(): void
    {
        [$service, $stored] = $this->fixture([
            'requiredWhen' => ['attribute' => 'accountId', 'operator' => 'equals', 'value' => 'account-a'],
        ], []);
        $stored->set('accountId', 'account-a');
        $stored->setAsFetched();
        try {
            $service->validate($stored);
            $this->fail('Expected a completion prompt.');
        } catch (ConflictSilent $e) {
            $snapshot = json_decode($e->getBody())->snapshot;
            $this->assertNotEmpty($snapshot->conditionsHash);
        }
        $retry = clone $stored;
        $retry->set('stageRequirementsSnapshot', $snapshot);
        $retry->set('customFields', (object) ['budget' => 10]);
        $stored->set('accountId', 'account-b');
        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('The opportunity changed');
        $service->validate($retry);
    }

    private function fixture(array $rules, array $keys = ['budget']): array
    {
        $em = $this->createMock(EntityManager::class);
        $funnel = $this->entity('Funnel', ['id' => 'funnel', 'tenantId' => 'tenant']);
        $stage = $this->entity('OpportunityStage', ['id' => 'proposal', 'name' => 'Proposal',
            'funnelId' => 'funnel', 'requiredCustomFieldKeys' => $keys]);
        $opportunity = $this->entity('Opportunity', ['id' => 'deal', 'tenantId' => 'tenant',
            'funnelId' => 'funnel', 'opportunityStageId' => 'proposal', 'status' => 'Open']);
        $em->method('getEntityById')->willReturnCallback(fn ($type) => $type === 'Funnel' ? $funnel : $stage);
        $repo = $this->createMock(RDBRepository::class);
        $query = $this->createMock(RDBSelectBuilder::class);
        $em->method('getRDBRepository')->willReturn($repo);
        $repo->method('where')->willReturn($query);
        $query->method('forUpdate')->willReturnSelf();
        $query->method('findOne')->willReturn($opportunity);
        $meta = $this->createMock(MetaProvider::class);
        $meta->method('getGroupedMeta')->willReturn(['groups' => [['label' => 'General', 'fields' => [
            $rules + ['valueKey' => 'budget', 'label' => 'Budget', 'type' => 'int'],
        ]]]]);
        return [new StageRequirements($em, $meta, $this->createMock(\Espo\Core\Acl::class)), $opportunity];
    }

    private function entity(string $type, array $data): BaseEntity
    {
        $attributes = array_fill_keys(['id', 'tenantId', 'funnelId', 'name', 'opportunityStageId', 'status', 'currentStageVisitId', 'accountId'], ['type' => 'varchar']);
        $attributes['requiredCustomFieldKeys'] = ['type' => 'jsonArray'];
        $attributes['customFields'] = ['type' => 'jsonObject'];
        $attributes['stageRequirementsSnapshot'] = ['type' => 'jsonObject'];
        $entity = new BaseEntity($type, ['attributes' => $attributes]);
        $entity->set($data);
        return $entity;
    }
}
