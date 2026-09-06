<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Note;
use Espo\Modules\Chatwoot\Hooks\Note\KeepOpportunityEventsInternal;
use Espo\Modules\Chatwoot\Services\OpportunityOverdueActivities;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Tools\Stream\NoteHookProcessor;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use Espo\Tools\Notification\HookProcessor\Params;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Support/EntityDouble.php';

class OpportunityOverdueActivitiesTest extends TestCase
{
    private array $writes = [];
    private array $attributes;
    private bool $stillPending = true;
    private Entity $activity;
    private OpportunityOverdueActivities $service;

    protected function setUp(): void
    {
        $this->attributes = [
            'id' => 'task', 'name' => 'Enviar proposta', 'parentId' => 'opp',
            'dateEnd' => '2026-09-06 14:00:00', 'dateEndDate' => null, 'tenantId' => 'tenant',
        ];
        $em = $this->createMock(EntityManager::class);
        $config = $this->createMock(Config::class);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturn(['Completed', 'Canceled', 'Deferred']);
        $events = $this->createMock(OpportunityStreamEvents::class);
        $events->method('write')->willReturnCallback(function (
            string $type, Entity $opportunity, Entity $related, array $data, string $key, array $teams,
        ): void {
            $this->writes[$key] = compact('type', 'opportunity', 'related', 'data', 'teams');
        });
        $transaction = $this->createMock(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(fn ($fn) => $fn());
        $em->method('getTransactionManager')->willReturn($transaction);

        $activity = new class($this->attributes, 'Task') extends EntityDouble {
            public function getLinkMultipleIdList(string $link): array { return ['team']; }
        };
        $this->activity = $activity;
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('where')->with(['status!=' => ['Completed', 'Canceled', 'Deferred']])->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();
        $select->method('findOne')->willReturnCallback(fn () => $this->stillPending ? $activity : null);
        $repo = $this->createMock(RDBRepository::class);
        $repo->method('where')->with(['id' => 'task', 'parentType' => 'Opportunity'])->willReturn($select);
        $em->method('getRDBRepository')->with('Task')->willReturn($repo);
        $em->method('getEntityById')->willReturnMap([
            ['Opportunity', 'opp', new EntityDouble(['id' => 'opp', 'tenantId' => 'tenant'], 'Opportunity')],
            ['Tenant', 'tenant', new EntityDouble(['timeZone' => 'America/Sao_Paulo'], 'Tenant')],
        ]);
        $this->service = new OpportunityOverdueActivities($em, $config, $metadata, $events, $this->createMock(Log::class));
    }

    private function tick(string $now): void
    {
        $this->activity->set($this->attributes);
        (new ReflectionMethod($this->service, 'record'))->invoke(
            $this->service, $this->activity, new DateTimeImmutable($now), '2026-09-06 00:00:00',
        );
    }

    public function testPostsOncePerDeadlineAndAllowsARescheduledDeadline(): void
    {
        $this->tick('2026-09-06T13:59:59Z');
        self::assertCount(0, $this->writes);
        $this->tick('2026-09-06T14:01:00Z');
        $this->tick('2026-09-06T14:02:00Z');
        self::assertCount(1, $this->writes);
        $event = array_values($this->writes)[0];
        self::assertSame(OpportunityStreamEvents::ACTIVITY_OVERDUE, $event['type']);
        self::assertSame('Enviar proposta', $event['data']['activityName']);
        self::assertSame('2026-09-06 14:00:00', $event['data']['dueAt']);
        self::assertSame('task', $event['related']->getId());
        $this->attributes['dateEnd'] = '2026-09-06 15:00:00';
        $this->tick('2026-09-06T14:59:00Z');
        self::assertCount(1, $this->writes);
        $this->tick('2026-09-06T15:01:00Z');
        self::assertCount(2, $this->writes);
    }

    public function testDateOnlyTaskIsNotOverdueUntilTheTenantDayHasEnded(): void
    {
        $this->attributes['dateEndDate'] = '2026-09-06';
        $this->tick('2026-09-07T02:59:59Z');
        self::assertCount(0, $this->writes);
        $this->tick('2026-09-07T03:01:00Z');
        self::assertCount(1, $this->writes);
        self::assertSame('2026-09-07 03:00:00', array_values($this->writes)[0]['data']['dueAt']);
    }

    public function testCompletionRaceAndHistoricalDeadlinesDoNotPost(): void
    {
        $this->stillPending = false;
        $this->tick('2026-09-06T14:01:00Z');
        $this->stillPending = true;
        $this->attributes['dateEnd'] = '2026-09-05 14:00:00';
        $this->tick('2026-09-06T14:01:00Z');
        self::assertCount(0, $this->writes);
    }

    public function testCrossTenantActivityIsNotPosted(): void
    {
        $this->attributes['tenantId'] = 'foreign';
        $this->tick('2026-09-06T14:01:00Z');
        self::assertCount(0, $this->writes);
    }

    public function testDateOnlyDeadlineHonorsDaylightSavingTime(): void
    {
        $activity = new EntityDouble(['dateEndDate' => '2026-03-08'], 'Task');
        self::assertSame('2026-03-09 04:00:00', $this->service->deadline($activity, 'America/New_York'));
    }

    public static function eventTypes(): array
    {
        return array_map(fn ($type) => [$type], OpportunityStreamEvents::EVENT_TYPES);
    }

    #[DataProvider('eventTypes')]
    public function testEventsRemainInternalAndDoNotFanOutCrmNotifications(string $type): void
    {
        $entity = new EntityDouble(['type' => $type, 'isInternal' => false], 'Note');
        (new KeepOpportunityEventsInternal())->beforeSave($entity, []);
        self::assertTrue($entity->get('isInternal'));
        $note = $this->createMock(Note::class);
        $note->method('getType')->willReturn($type);
        // No parent dependencies are initialized: reaching notification fan-out would fail.
        $processor = (new ReflectionClass(NoteHookProcessor::class))->newInstanceWithoutConstructor();
        $processor->afterSave($note, new Params());
    }
}
