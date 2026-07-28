<?php

declare(strict_types=1);

namespace tests\unit\Espo\Core\Utils\Database\Orm\LinkConverters;

use Espo\Core\Utils\Database\Orm\LinkConverters\EntityTeam;
use Espo\ORM\Defs\Params\EntityParam;
use Espo\ORM\Defs\Params\IndexParam;
use Espo\ORM\Defs\Params\RelationParam;
use Espo\ORM\Defs\RelationDefs;
use Espo\ORM\Type\RelationType;
use PHPUnit\Framework\TestCase;

class EntityTeamTest extends TestCase
{
    public function testIndexBelongsToJunctionRelationNotOwnerEntity(): void
    {
        $linkDefs = RelationDefs::fromRaw([
            RelationParam::TYPE => RelationType::HAS_MANY,
            RelationParam::RELATION_NAME => 'entityTeam',
        ], 'teams');

        $raw = (new EntityTeam())->convert($linkDefs, 'Email')->toAssoc();
        $index = $raw[EntityParam::RELATIONS]['teams'][RelationParam::INDEXES]
            ['teamEntityTypeDeletedEntityId'];

        $this->assertArrayNotHasKey(EntityParam::INDEXES, $raw);
        $this->assertSame(
            ['teamId', 'entityType', 'deleted', 'entityId'],
            $index[IndexParam::COLUMNS],
        );
    }
}
