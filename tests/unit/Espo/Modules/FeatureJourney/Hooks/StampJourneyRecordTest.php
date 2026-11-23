<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Hooks;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Hooks\ChatwootConversation\StampJourneyRecord;
use Espo\Modules\FeatureJourney\Services\JourneyConversations;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StampJourneyRecordTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $set = [];

    private function conversation(bool $new, array $values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('isNew')->willReturn($new);
        $entity->method('get')->willReturnCallback(fn (string $field) => $values[$field] ?? null);
        $entity->method('set')->willReturnCallback(function (string $field, $value) use (&$entity) {
            $this->set[$field] = $value;

            return $entity;
        });

        return $entity;
    }

    private function record(): Entity
    {
        $record = $this->createMock(Entity::class);
        $record->method('getId')->willReturn('run1');
        $record->method('get')->willReturnCallback(fn (string $field) => $field === 'journeyId' ? 'journey1' : null);

        return $record;
    }

    public function testAConversationCopiedInAfterAJourneySentToItGetsTheEnrollment(): void
    {
        $conversations = $this->createMock(JourneyConversations::class);
        $conversations->expects($this->once())->method('liveRecord')->with('crm-account', 2811)
            ->willReturn($this->record());

        (new StampJourneyRecord($conversations, $this->createMock(Log::class)))->beforeSave(
            $this->conversation(true, ['chatwootAccountId' => 'crm-account', 'chatwootConversationId' => 2811]),
            SaveOptions::fromAssoc(['silent' => true]),
        );

        self::assertSame(['journeyId' => 'journey1', 'journeyRecordId' => 'run1'], $this->set);
    }

    public function testNoLiveEnrollmentLeavesItUnstamped(): void
    {
        $conversations = $this->createMock(JourneyConversations::class);
        $conversations->method('liveRecord')->willReturn(null);

        (new StampJourneyRecord($conversations, $this->createMock(Log::class)))->beforeSave(
            $this->conversation(true, ['chatwootAccountId' => 'crm-account', 'chatwootConversationId' => 2811]),
            SaveOptions::fromAssoc([]),
        );

        self::assertSame([], $this->set);
    }

    public function testExistingRowsAndRowsStampedAtSendAreLeftAlone(): void
    {
        $conversations = $this->createMock(JourneyConversations::class);
        $conversations->expects($this->never())->method('liveRecord');
        $hook = new StampJourneyRecord($conversations, $this->createMock(Log::class));

        $hook->beforeSave($this->conversation(false, ['chatwootAccountId' => 'a', 'chatwootConversationId' => 1]), SaveOptions::fromAssoc([]));
        $hook->beforeSave($this->conversation(true, ['journeyRecordId' => 'run0', 'chatwootAccountId' => 'a', 'chatwootConversationId' => 1]),
            SaveOptions::fromAssoc([]));

        self::assertSame([], $this->set);
    }

    public function testALookupFailureNeverBlocksTheSync(): void
    {
        $conversations = $this->createMock(JourneyConversations::class);
        $conversations->method('liveRecord')->willThrowException(new RuntimeException('db'));
        $log = $this->createMock(Log::class);
        $log->expects($this->once())->method('warning');

        (new StampJourneyRecord($conversations, $log))->beforeSave(
            $this->conversation(true, ['chatwootAccountId' => 'crm-account', 'chatwootConversationId' => 2811]),
            SaveOptions::fromAssoc([]),
        );

        self::assertSame([], $this->set);
    }
}
