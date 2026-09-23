<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Modules\Chatwoot\Services\ConversationReportSnapshot;
use PHPUnit\Framework\TestCase;

class ConversationReportSnapshotTest extends TestCase
{
    public function testCurrentLabelsAndChatwootTeamComeFromSourceSnapshot(): void
    {
        self::assertSame(['currentTags' => 'consultas, agendados', 'currentTeamName' => 'Recepção'],
            ConversationReportSnapshot::fromPayload([
                'labels' => ['consultas', 'agendados', 'consultas'],
                'meta' => ['team' => ['id' => 8, 'name' => 'Recepção']],
            ]));
    }

    public function testExplicitRemovalsClearSnapshot(): void
    {
        self::assertSame(['currentTags' => '', 'currentTeamName' => null],
            ConversationReportSnapshot::fromPayload(['labels' => [], 'meta' => ['team' => null]]));
        self::assertSame(['currentTags' => '', 'currentTeamName' => null],
            ConversationReportSnapshot::fromPayload(['labels' => [], 'meta' => ['sender' => ['id' => 10]]]));
    }

    public function testAbsentLabelsDoNotInventAKnownEmptyTagSet(): void
    {
        self::assertSame(['currentTeamName' => 'Sales'],
            ConversationReportSnapshot::fromPayload(['meta' => ['team' => ['name' => 'Sales']]]));
    }
}
