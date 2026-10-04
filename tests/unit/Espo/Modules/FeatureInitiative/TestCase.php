<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureInitiative;

use Espo\ORM\BaseEntity;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function entity(string $type, array $values = [], bool $existing = false): BaseEntity
    {
        $attributes = array_fill_keys([
            'id', 'name', 'tenantId', 'tenantName', 'initiativeTypeId', 'stageId', 'status', 'initiativeId', 'parentType', 'parentId',
            'assignedUserId', 'baseUserTeamId', 'chatwootAccountId', 'category',
        ], ['type' => 'varchar']);
        $attributes['isActive'] = ['type' => 'bool'];
        $attributes['teamsIds'] = ['type' => 'jsonArray'];
        $attributes['initiativeAccess'] = ['type' => 'jsonObject', 'notStorable' => true];

        $entity = new BaseEntity($type, ['attributes' => $attributes]);
        $entity->set($values);

        if ($existing) {
            $entity->setAsNotNew();
            $entity->updateFetchedValues();
        }

        return $entity;
    }
}
