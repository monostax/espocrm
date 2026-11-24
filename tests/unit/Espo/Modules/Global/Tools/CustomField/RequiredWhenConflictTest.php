<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\CustomField;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Hooks\CustomFieldDef\ValidateConditions;
use Espo\Modules\Global\Tools\CustomField\ConditionSchema;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11-24 Nowle: Required + Required When on one field made a stage-only field
 * required in every stage (Required wins), so every AI and person save of a deal
 * outside those stages got a 409. The definition hook now rejects the pair.
 */
class RequiredWhenConflictTest extends TestCase
{
    public function testRequiredAndRequiredWhenTogetherAreRejected(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('not both');
        $this->save(['isRequired' => true, 'requiredWhen' => $this->onStage()]);
    }

    public function testEitherAloneIsAccepted(): void
    {
        $this->save(['isRequired' => true, 'requiredWhen' => null]);
        $this->save(['isRequired' => false, 'requiredWhen' => $this->onStage()]);
        $this->addToAssertionCount(2);
    }

    private function onStage(): object
    {
        return (object) ['all' => [(object) ['attribute' => 'opportunityStageId', 'operator' => 'in', 'value' => ['stage']]]];
    }

    private function save(array $values): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn (string $type) => $type === 'OpportunityStage'
            ? $this->entity('OpportunityStage', ['funnelId' => 'funnel'])
            : $this->entity('Funnel', ['tenantId' => 'tenant']));
        $acl = $this->createMock(Acl::class);
        $acl->method('check')->willReturn(true);
        $acl->method('checkField')->willReturn(true);
        $def = $this->entity('CustomFieldDef', ['entityType' => 'Opportunity', 'tenantId' => 'tenant'] + $values);
        (new ValidateConditions($em, $this->schema(), $acl))->beforeSave($def, SaveOptions::fromAssoc([]));
    }

    private function schema(): ConditionSchema
    {
        $definitions = ['entityDefs' => [
            'Opportunity' => ['fields' => ['opportunityStage' => ['type' => 'link']],
                'links' => ['opportunityStage' => ['type' => 'belongsTo', 'entity' => 'OpportunityStage']]],
            'Funnel' => ['fields' => ['tenant' => ['type' => 'link']]],
        ]];
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(function ($path) use ($definitions) {
            $value = $definitions;
            foreach ((array) $path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    return null;
                }
                $value = $value[$key];
            }
            return $value;
        });
        return new ConditionSchema($metadata);
    }

    private function entity(string $type, array $values): BaseEntity
    {
        $attributes = array_fill_keys(['id', 'tenantId', 'entityType', 'funnelId', 'valueKey'], ['type' => 'varchar']);
        $attributes['appliesWhen'] = ['type' => 'jsonObject'];
        $attributes['requiredWhen'] = ['type' => 'jsonObject'];
        $attributes['isRequired'] = ['type' => 'bool'];
        $entity = new BaseEntity($type, ['attributes' => $attributes]);
        $entity->set($values);
        return $entity;
    }
}
