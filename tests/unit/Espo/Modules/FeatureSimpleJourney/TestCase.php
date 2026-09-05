<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSimpleJourney;

use Espo\ORM\BaseEntity;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function entity(string $type, array $values = [], bool $existing = false): BaseEntity
    {
        $attributes = array_fill_keys([
            'id', 'name', 'tenantId', 'tenantName', 'journeyId', 'stageId', 'status', 'recordId', 'parentType', 'parentId',
            'assignedUserId', 'baseUserTeamId', 'chatwootAccountId',
        ], ['type' => 'varchar']);
        $attributes['isActive'] = ['type' => 'bool'];
        $attributes['teamsIds'] = ['type' => 'jsonArray'];
        $attributes['simpleJourneyAccess'] = ['type' => 'jsonObject', 'notStorable' => true];

        $entity = new BaseEntity($type, ['attributes' => $attributes]);
        $entity->set($values);

        if ($existing) {
            $entity->setAsNotNew();
            $entity->updateFetchedValues();
        }

        return $entity;
    }
}
