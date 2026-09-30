<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\CustomField;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Acl;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Hooks\Common\ValidateCustomFieldRequirements;
use Espo\Modules\Global\Hooks\CustomFieldDef\ValidateConditions;
use Espo\Modules\Global\Tools\CustomField\MetaProvider;
use Espo\Modules\Global\Tools\CustomField\ConditionSchema;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class ConditionHooksTest extends TestCase
{
    public function testDefinitionRejectsCrossTenantStageReference(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type) => $type === 'OpportunityStage'
            ? $this->entity('OpportunityStage', ['funnelId' => 'funnel'])
            : $this->entity('Funnel', ['tenantId' => 'other-tenant']));
        $def = $this->entity('CustomFieldDef', ['entityType' => 'Opportunity', 'tenantId' => 'tenant',
            'appliesWhen' => (object) ['attribute' => 'opportunityStageId', 'operator' => 'equals', 'value' => 'stage']]);
        $hook = new ValidateConditions($em, $this->schema(), $this->acl());
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Condition references must belong to the custom field tenant.');
        $hook->beforeSave($def, SaveOptions::fromAssoc([]));
    }

    public function testDefinitionScopeEditCannotInvalidateAnExistingStageGate(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($this->entity('Funnel', ['tenantId' => 'tenant']));
        $stage = $this->entity('OpportunityStage', ['id' => 'proposal', 'funnelId' => 'original',
            'requiredCustomFieldKeys' => ['budget']]);
        $repo = $this->createMock(RDBRepository::class);
        $query = $this->createMock(RDBSelectBuilder::class);
        $em->method('getRDBRepository')->willReturn($repo);
        $repo->method('join')->willReturn($query);
        $query->method('where')->willReturnSelf();
        $query->method('find')->willReturn(new EntityCollection([$stage]));
        $def = $this->entity('CustomFieldDef', ['entityType' => 'Opportunity', 'tenantId' => 'tenant',
            'valueKey' => 'budget', 'isActive' => true]);
        $def->setAsFetched();
        $def->set('appliesWhen', (object) ['attribute' => 'funnelId', 'operator' => 'equals', 'value' => 'different']);
        $this->expectException(BadRequest::class);
        (new ValidateConditions($em, $this->schema(), $this->acl()))->beforeSave($def, SaveOptions::fromAssoc([]));
    }

    public function testNonOpportunitySavesEnforceApplicableRequirementsAndAcceptFalse(): void
    {
        $meta = $this->createMock(MetaProvider::class);
        $meta->method('isEntityEnabled')->willReturn(true);
        $meta->method('getGroupedMeta')->willReturn(['groups' => [['fields' => [[
            'valueKey' => 'confirmed', 'label' => 'Confirmed', 'type' => 'bool',
            'requiredWhen' => ['attribute' => 'status', 'operator' => 'equals', 'value' => 'Qualified'],
        ]]]]]);
        $hook = new ValidateCustomFieldRequirements($meta);
        $lead = $this->entity('Lead', ['tenantId' => 'tenant', 'status' => 'New']);
        $hook->beforeSave($lead, SaveOptions::fromAssoc([]));
        $lead->set(['status' => 'Qualified', 'customFields' => (object) ['confirmed' => false]]);
        $hook->beforeSave($lead, SaveOptions::fromAssoc([]));
        $lead->set('customFields', (object) []);
        $this->expectException(BadRequest::class);
        $hook->beforeSave($lead, SaveOptions::fromAssoc([]));
    }

    private function entity(string $type, array $data): BaseEntity
    {
        $attributes = array_fill_keys(['id', 'tenantId', 'entityType', 'funnelId', 'status', 'valueKey', 'type', 'industry', 'accountId', 'source', 'website'], ['type' => 'varchar']);
        foreach (['customFields', 'appliesWhen', 'requiredWhen'] as $name) $attributes[$name] = ['type' => 'jsonObject'];
        $attributes['requiredCustomFieldKeys'] = ['type' => 'jsonArray'];
        $attributes['isActive'] = ['type' => 'bool'];
        $attributes['doNotCall'] = ['type' => 'bool'];
        $attributes['tags'] = ['type' => 'jsonArray'];
        $entity = new BaseEntity($type, ['attributes' => $attributes]);
        $entity->set($data);
        return $entity;
    }

    public function testAccountAndContactConditionsValidateEffectiveIncomingValues(): void
    {
        foreach ([
            ['Account', ['type' => 'Customer', 'industry' => 'Healthcare'],
                ['attribute' => 'type', 'operator' => 'equals', 'value' => 'Customer']],
            ['Contact', ['source' => 'Campaign'], ['attribute' => 'source', 'operator' => 'equals', 'value' => 'Campaign']],
            ['Contact', ['tags' => ['vip', 'partner']], ['attribute' => 'tags', 'operator' => 'containsAll', 'value' => ['vip', 'partner']]],
            ['Contact', ['accountId' => 'account-a'], ['attribute' => 'accountId', 'operator' => 'equals', 'value' => 'account-a']],
            ['Contact', ['doNotCall' => true], ['attribute' => 'doNotCall', 'operator' => 'isTrue']],
            ['Account', ['website' => 'https://example.org'], ['attribute' => 'website', 'operator' => 'isFilled']],
        ] as [$type, $changes, $condition]) {
            $meta = $this->createMock(MetaProvider::class);
            $meta->method('isEntityEnabled')->willReturn(true);
            $meta->method('getGroupedMeta')->willReturn(['groups' => [['fields' => [[
                'valueKey' => 'reference', 'label' => 'Reference', 'type' => 'text', 'requiredWhen' => $condition,
            ]]]]]);
            $entity = $this->entity($type, ['tenantId' => 'tenant']);
            $entity->setAsFetched();
            $hook = new ValidateCustomFieldRequirements($meta);
            $hook->beforeSave($entity, SaveOptions::fromAssoc([]));
            $entity->set($changes);
            try {
                $hook->beforeSave($entity, SaveOptions::fromAssoc([]));
                $this->fail('Required condition did not use incoming changes: ' . $condition['attribute']);
            } catch (BadRequest $e) {
                $this->assertStringContainsString('Reference', $e->getMessage());
            }
            $entity->set('customFields', (object) ['reference' => 'supplied']);
            $hook->beforeSave($entity, SaveOptions::fromAssoc([]));
        }
    }

    public function testAccountReferenceMustExistBeReadableAndBelongToTheTenant(): void
    {
        foreach (['other-tenant', 'missing', 'unreadable', 'tenant'] as $scenario) {
            $em = $this->createMock(EntityManager::class);
            $em->method('getEntityById')->with('Account', 'account-a')->willReturn($scenario === 'missing' ? null :
                $this->entity('Account', ['tenantId' => $scenario === 'other-tenant' ? $scenario : 'tenant']));
            $acl = $this->acl($scenario !== 'unreadable');
            $def = $this->entity('CustomFieldDef', ['entityType' => 'Contact', 'tenantId' => 'tenant',
                'appliesWhen' => (object) ['attribute' => 'accountId', 'operator' => 'in', 'value' => ['account-a']]]);
            try {
                (new ValidateConditions($em, $this->schema(), $acl))->beforeSave($def, SaveOptions::fromAssoc([]));
                $this->assertSame('tenant', $scenario);
            } catch (BadRequest) {
                $this->assertNotSame('tenant', $scenario);
            }
        }
    }

    private function acl(bool $readable = true): Acl
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('check')->willReturn($readable);
        $acl->method('checkField')->willReturn(true);
        return $acl;
    }

    private function schema(): ConditionSchema
    {
        $definitions = ['entityDefs' => [
            'Opportunity' => ['fields' => ['funnel' => ['type' => 'link'], 'opportunityStage' => ['type' => 'link']],
                'links' => ['funnel' => ['type' => 'belongsTo', 'entity' => 'Funnel'],
                    'opportunityStage' => ['type' => 'belongsTo', 'entity' => 'OpportunityStage']]],
            'Contact' => ['fields' => ['account' => ['type' => 'link']],
                'links' => ['account' => ['type' => 'belongsTo', 'entity' => 'Account']]],
            'Account' => ['fields' => ['tenant' => ['type' => 'link']]],
            'Funnel' => ['fields' => ['tenant' => ['type' => 'link']]],
        ]];
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(function ($path) use ($definitions) {
            $value = $definitions;
            foreach ($path as $key) $value = $value[$key] ?? null;
            return $value;
        });
        return new ConditionSchema($metadata);
    }
}
