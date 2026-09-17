<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Opportunity;

use DateTimeImmutable;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\DateTime\Clock;
use Espo\Modules\Global\Classes\Select\Opportunity\BoolFilters\StageOverdue;
use Espo\Modules\Global\Hooks\OpportunityStage\ValidateTargetTime;
use Espo\ORM\BaseEntity;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

class StageTargetTest extends TestCase
{
    public function testOptionalWholeMinuteTargets(): void
    {
        $entity = new BaseEntity('OpportunityStage', ['attributes' => ['targetTimeSeconds' => ['type' => 'int']]]);
        $hook = new ValidateTargetTime();
        foreach ([null, 60, 3600, 2147483640] as $target) {
            $entity->set('targetTimeSeconds', $target);
            $hook->beforeSave($entity, SaveOptions::fromAssoc([]));
            $this->assertSame($target, $entity->get('targetTimeSeconds'));
        }
        foreach ([0, -60, 1, 61, 2147483647] as $target) {
            $entity->set('targetTimeSeconds', $target);
            try {
                $hook->beforeSave($entity, SaveOptions::fromAssoc([]));
                $this->fail('Invalid target accepted: ' . $target);
            } catch (BadRequest) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOverdueFilterUsesCurrentUtcClockAndOnlyActiveOpenVisits(): void
    {
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-17T08:00:00-03:00'));
        $group = new OrGroupBuilder();
        (new StageOverdue($clock))->apply(SelectBuilder::create(), $group);
        $this->assertSame([
            [['status=' => 'Open'], ['currentStageVisitId!=' => null], ['stageDueAt<' => '2026-09-17 11:00:00']],
        ], $group->build()->getRawValue());
    }
}
