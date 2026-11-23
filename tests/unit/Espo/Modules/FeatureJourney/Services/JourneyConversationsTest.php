<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Modules\FeatureJourney\Services\JourneyConversations;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Query\Update;
use Espo\ORM\QueryBuilder;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class JourneyConversationsTest extends TestCase
{
    /** @var list<array<string, mixed>> where clauses of the ChatwootConversation lookups */
    private array $lookups = [];
    /** @var array<string, array<string, mixed>> conversation id => values set */
    private array $updates = [];
    /** @var list<array<string, mixed>> where clauses of the JourneyRecord queries */
    private array $recordQueries = [];

    private function entity(string $id, array $values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn (string $field) => $values[$field] ?? null);

        return $entity;
    }

    /**
     * @param list<Entity> $records live enrollments, newest first
     * @param list<Entity> $conversations rows the CRM has
     */
    private function service(array $records, array $conversations = [], ?Entity $found = null): JourneyConversations
    {
        $recordBuilder = $this->createMock(RDBSelectBuilder::class);
        $recordBuilder->method('select')->willReturnSelf();
        $recordBuilder->method('order')->willReturnSelf();
        $recordBuilder->method('where')->willReturnCallback(function (array $where) use ($recordBuilder) {
            $this->recordQueries[] = $where;

            return $recordBuilder;
        });
        $recordBuilder->method('find')->willReturn(new EntityCollection($records));
        $recordBuilder->method('findOne')->willReturn($found);
        $recordRepository = $this->createMock(RDBRepository::class);
        $recordRepository->method('select')->willReturn($recordBuilder);
        $recordRepository->method('where')->willReturnCallback(fn (array $where) => $recordBuilder->where($where));

        $rows = [];
        $conversationBuilder = $this->createMock(RDBSelectBuilder::class);
        $conversationBuilder->method('where')->willReturnCallback(function (array $where) use ($conversationBuilder, $conversations, &$rows) {
            $this->lookups[] = $where;
            $rows = array_values(array_filter($conversations, fn (Entity $row) =>
                $row->get('chatwootAccountId') === $where['chatwootAccountId']
                && in_array($row->get('chatwootConversationId'), $where['chatwootConversationId'], true)));

            return $conversationBuilder;
        });
        $conversationBuilder->method('find')->willReturnCallback(function () use (&$rows) {
            return new EntityCollection($rows);
        });
        $conversationRepository = $this->createMock(RDBRepository::class);
        $conversationRepository->method('select')->willReturn($conversationBuilder);

        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('execute')->willReturnCallback(function (Update $query) {
            self::assertSame('ChatwootConversation', $query->getIn());
            $this->updates[$query->getWhere()?->getRaw()['id']] = $query->getSet();

            return $this->createMock(\PDOStatement::class);
        });

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(fn (string $type) => match ($type) {
            'JourneyRecord' => $recordRepository,
            'ChatwootConversation' => $conversationRepository,
        });
        $em->method('getQueryBuilder')->willReturn(new QueryBuilder());
        $em->method('getQueryExecutor')->willReturn($executor);

        return new JourneyConversations($em);
    }

    private function conversation(string $id, string $account, int $displayId, ?string $stamp = null): Entity
    {
        return $this->entity($id, ['chatwootAccountId' => $account, 'chatwootConversationId' => $displayId, 'journeyRecordId' => $stamp]);
    }

    public function testRowsCopiedInAfterTheSendGetTheirLiveEnrollment(): void
    {
        $stamped = $this->service(
            [$this->entity('run1', ['journeyId' => 'j1', 'chatwootConversationTrail' => ['acc:2811', 'acc:2812']])],
            [$this->conversation('c1', 'acc', 2811), $this->conversation('c2', 'acc', 2812, 'ended-run')],
        )->backfill();

        self::assertSame(2, $stamped, 'an empty stamp and one naming an ended enrollment are both filled');
        self::assertSame([
            'c1' => ['journeyRecordId' => 'run1', 'journeyId' => 'j1'],
            'c2' => ['journeyRecordId' => 'run1', 'journeyId' => 'j1'],
        ], $this->updates);
        self::assertSame(['status' => ['Active', 'Processing', 'Paused']], $this->recordQueries[0]);
    }

    public function testTheNewestEnrollmentWinsAndALiveStampIsKept(): void
    {
        $this->service(
            [
                $this->entity('newer', ['journeyId' => 'j2', 'chatwootConversationTrail' => ['acc:10']]),
                $this->entity('older', ['journeyId' => 'j1', 'chatwootConversationTrail' => ['acc:10', 'acc:11'],
                    'whatsAppChatwootAccountId' => 'other', 'whatsAppChatwootConversationId' => '7']),
            ],
            [$this->conversation('c10', 'acc', 10), $this->conversation('c11', 'acc', 11, 'newer'), $this->conversation('c7', 'other', 7)],
        )->backfill();

        self::assertSame([
            'c10' => ['journeyRecordId' => 'newer', 'journeyId' => 'j2'],
            'c7' => ['journeyRecordId' => 'older', 'journeyId' => 'j1'],
        ], $this->updates, 'c11 already names a live enrollment; c7 is the stamp from before the trail');
        self::assertSame([['chatwootAccountId' => 'acc', 'chatwootConversationId' => [10, 11]],
            ['chatwootAccountId' => 'other', 'chatwootConversationId' => [7]]], $this->lookups, 'one lookup per account');
    }

    public function testMalformedTrailEntriesAreIgnored(): void
    {
        $stamped = $this->service(
            [$this->entity('run1', ['journeyId' => 'j1', 'chatwootConversationTrail' => ['no-colon', ':5', 'acc:abc', 'acc:0', 42]])],
        )->backfill();

        self::assertSame(0, $stamped);
        self::assertSame([], $this->lookups);
    }

    public function testTheLiveEnrollmentOfAConversationIsFoundByTrailOrLastSend(): void
    {
        $run = $this->entity('run1', []);
        $service = $this->service([], [], $run);

        self::assertSame($run, $service->liveRecord('acc', 2811));
        self::assertSame([
            'status' => ['Active', 'Processing', 'Paused'],
            'OR' => [
                ['whatsAppChatwootAccountId' => 'acc', 'whatsAppChatwootConversationId' => '2811'],
                ['chatwootConversationTrail*' => '%"acc:2811"%'],
            ],
        ], $this->recordQueries[0]);
        self::assertNull($service->liveRecord('', 2811));
        self::assertNull($service->liveRecord('acc', 0));
        self::assertCount(1, $this->recordQueries, 'no query without a conversation');
    }
}
