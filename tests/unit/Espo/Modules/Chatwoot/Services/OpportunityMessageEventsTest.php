<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\SystemUser;
use Espo\Modules\Chatwoot\Jobs\SyncConversationsFromChatwoot;
use Espo\Modules\Chatwoot\Services\OpportunityMessageEvents;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Select;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use Espo\Tools\Stream\Service as StreamService;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Support/EntityDouble.php';

class OpportunityMessageEventsTest extends TestCase
{
    private array $notes = [];
    private array $resolvedAccounts = [];
    private OpportunityMessageEvents $service;
    private Entity $conversation;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManager::class);
        $config = $this->createMock(Config::class);
        $config->method('get')->with(OpportunityMessageEvents::STARTED_AT)->willReturn('2026-09-06 00:00:00');
        $system = $this->createMock(SystemUser::class);
        $system->method('getId')->willReturn('system');
        $stream = $this->createMock(StreamService::class);
        $transaction = $this->createMock(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(fn ($fn) => $fn());
        $em->method('getTransactionManager')->willReturn($transaction);

        $opportunity = new EntityDouble(['id' => 'opp', 'tenantId' => 'tenant'], 'Opportunity');
        $foreignOpportunity = new EntityDouble(['id' => 'foreign', 'tenantId' => 'other-tenant'], 'Opportunity');
        $this->conversation = new EntityDouble([
            'id' => 'conversation', 'chatwootAccountId' => 'crm-account', 'chatwootConversationId' => 123,
        ], 'ChatwootConversation');
        $account = $this->createMock(CoreEntity::class);
        $account->method('getId')->willReturn('crm-account');
        $account->method('get')->willReturnMap([['tenantId', 'tenant'], ['chatwootAccountId', 1]]);
        $account->method('getLinkMultipleIdList')->with('teams')->willReturn(['team']);
        $em->method('getEntityById')->with('ChatwootAccount', 'crm-account')->willReturn($account);

        $conversations = $this->createMock(RDBRepository::class);
        $conversations->method('where')->willReturnCallback(function (array $where): RDBSelectBuilder {
            $this->resolvedAccounts[] = $where;
            $select = $this->createMock(RDBSelectBuilder::class);
            $select->method('findOne')->willReturn($this->conversation);
            return $select;
        });
        $relation = $this->createMock(RDBRelation::class);
        $relation->method('find')->willReturn(new EntityCollection([$opportunity, $foreignOpportunity]));
        $conversations->method('getRelation')->with($this->conversation, 'opportunities')->willReturn($relation);

        $opportunities = $this->createMock(RDBRepository::class);
        $locked = $this->createMock(RDBSelectBuilder::class);
        $locked->method('forUpdate')->willReturnSelf();
        $locked->method('findOne')->willReturn($opportunity);
        $opportunities->method('where')->with(['id' => 'opp'])->willReturn($locked);

        $notes = $this->createMock(RDBRepository::class);
        $notes->method('where')->willReturnCallback(function (array $where): RDBSelectBuilder {
            self::assertSame(OpportunityMessageEvents::TYPE, $where['type']);
            self::assertSame('ChatwootConversation', $where['relatedType']);
            self::assertSame('conversation', $where['relatedId']);
            $matches = array_intersect_key($this->notes, array_flip($where['opportunityStreamEventKey']));
            $select = $this->createMock(RDBSelectBuilder::class);
            $select->method('find')->willReturn(new EntityCollection(array_values(array_map(
                fn ($data) => new EntityDouble($data, 'Note'), $matches
            ))));
            return $select;
        });
        $em->method('removeEntity')->willReturnCallback(function (Entity $note): void {
            unset($this->notes[$note->get('opportunityStreamEventKey')]);
        });
        $notes->method('clone')->willReturnCallback(function (Select $query): RDBSelectBuilder {
            self::assertTrue($query->getRaw()['withDeleted']);
            $key = $query->getWhere()->getRaw()['opportunityStreamEventKey'];
            $select = $this->createMock(RDBSelectBuilder::class);
            $select->method('findOne')->willReturn(isset($this->notes[$key]) ? new EntityDouble($this->notes[$key]) : null);
            return $select;
        });
        $repos = ['Note' => $notes, 'Opportunity' => $opportunities, 'ChatwootConversation' => $conversations];
        $em->method('getRDBRepository')->willReturnCallback(fn ($type) => $repos[$type]);
        $em->method('createEntity')->willReturnCallback(function (string $type, array $data): Entity {
            self::assertSame('Note', $type);
            self::assertArrayNotHasKey('post', $data);
            $this->notes[$data['opportunityStreamEventKey']] = $data;
            return new EntityDouble($data, 'Note');
        });

        $this->service = new OpportunityMessageEvents($em, $config, new OpportunityStreamEvents($em, $system, $stream));
    }

    private function payload(int $id = 1, array $values = []): object
    {
        return (object) array_replace([
            'id' => $id,
            'message_type' => 'incoming',
            'private' => false,
            'created_at' => '2026-09-06T14:32:00Z',
            'conversation' => (object) ['display_id' => 123, 'id' => 999],
        ], $values);
    }

    public function testTenIncomingMessagesCreateTenImmutableAccountScopedNotes(): void
    {
        for ($id = 1; $id <= 10; $id++) {
            $this->service->recordWebhook($this->payload($id), 'crm-account');
        }
        self::assertCount(10, $this->notes);
        foreach ($this->notes as $note) {
            self::assertSame(OpportunityMessageEvents::TYPE, $note['type']);
            self::assertSame('opp', $note['parentId']);
            self::assertSame('conversation', $note['relatedId']);
            self::assertSame('system', $note['createdById']);
            self::assertTrue($note['isInternal']);
            self::assertSame(['team'], $note['teamsIds']);
            self::assertSame(1, $note['data']->chatwootAccountId);
            self::assertSame(123, $note['data']->chatwootConversationId);
        }
        self::assertSame(['chatwootAccountId' => 'crm-account', 'chatwootConversationId' => 123], $this->resolvedAccounts[0]);
    }

    public function testWebhookRetriesAndSyncDoNotDuplicateAnEvent(): void
    {
        $this->service->recordWebhook($this->payload(), 'crm-account');
        $this->service->recordWebhook($this->payload(), 'crm-account');
        $this->service->recordSyncedMessage(new EntityDouble([
            'chatwootMessageId' => 1, 'messageType' => 'incoming', 'isPrivate' => false,
            'chatwootCreatedAt' => '2026-09-06 14:32:00',
        ]), $this->conversation);
        self::assertCount(1, $this->notes);
    }

    public function testPrivateOutgoingActivityAndHistoricalMessagesAreIgnored(): void
    {
        foreach ([
            ['private' => true], ['message_type' => 1], ['message_type' => 'activity'],
            ['created_at' => '2026-09-05T23:59:59Z'],
        ] as $values) {
            $this->service->recordWebhook($this->payload(1, $values), 'crm-account');
        }
        self::assertSame([], $this->notes);
    }

    public function testNumericIncomingTypeAndUnixTimestampAreSupported(): void
    {
        $this->service->recordWebhook($this->payload(1, [
            'message_type' => 0,
            'created_at' => strtotime('2026-09-06T14:32:00Z'),
        ]), 'crm-account');
        self::assertCount(1, $this->notes);
        self::assertSame('2026-09-06 14:32:00', array_values($this->notes)[0]['data']->occurredAt);
    }

    public function testSyncOnlyProducesReceivedEventsForExplicitIncomingTypes(): void
    {
        $job = (new \ReflectionClass(SyncConversationsFromChatwoot::class))->newInstanceWithoutConstructor();
        $normalize = new \ReflectionMethod($job, 'normalizeMessageType');
        $types = [0, '0', 'incoming', 1, '1', 'outgoing', 2, 'activity', 3, 'template', null, 'unknown'];
        foreach ($types as $index => $type) {
            $messageType = $normalize->invoke($job, $type);
            if ($messageType === null) {
                continue;
            }
            $this->service->recordSyncedMessage(new EntityDouble([
                'chatwootMessageId' => $index + 1, 'messageType' => $messageType, 'isPrivate' => false,
                'chatwootCreatedAt' => '2026-09-06 14:32:00',
            ]), $this->conversation);
        }
        self::assertSame([1, 2, 3], array_map(fn ($note) => $note['data']->chatwootMessageId, array_values($this->notes)));
    }

    public function testResyncRemovesIncorrectOutgoingAndPrivateEventsButKeepsIncomingEvents(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->service->recordWebhook($this->payload($id), 'crm-account');
        }
        foreach ([['outgoing', false], ['incoming', true]] as $index => [$type, $private]) {
            $this->service->recordSyncedMessage(new EntityDouble([
                'chatwootMessageId' => $index + 1, 'messageType' => $type, 'isPrivate' => $private,
            ]), $this->conversation);
        }
        self::assertCount(1, $this->notes);
        self::assertSame(3, array_values($this->notes)[0]['data']->chatwootMessageId);
    }
}
