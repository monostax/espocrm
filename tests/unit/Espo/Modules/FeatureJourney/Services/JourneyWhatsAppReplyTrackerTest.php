<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\JourneySignalDispatcher;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppReplyTracker;
use Espo\Modules\FeatureJourney\Services\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\EntityCollection;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class JourneyWhatsAppReplyTrackerTest extends TestCase
{
    public function testConfirmedKnowledgeMembershipWorksInBothDirectionsWithoutDuplicateOrForeignTargets(): void
    {
        $entity = function (string $id, array $values = []): Entity {
            $entity = $this->createStub(Entity::class);
            $entity->method('getId')->willReturn($id);
            $entity->method('get')->willReturnCallback(static fn ($key) => $values[$key] ?? null);
            return $entity;
        };
        $conversation = $entity('conversation-1');
        $native = $entity('native', ['tenantId' => 'tenant-1']);
        $forward = $entity('forward', ['tenantId' => 'tenant-1']);
        $reverse = $entity('reverse', ['tenantId' => 'tenant-1']);
        $foreign = $entity('foreign', ['tenantId' => 'tenant-2']);
        $em = $this->createMock(EntityManager::class);
        $em->method('hasRepository')->with('RecordRelation')->willReturn(true);
        $em->method('getEntityById')->willReturnCallback(static fn ($type, $id) =>
            ['forward' => $forward, 'reverse' => $reverse, 'foreign' => $foreign][$id] ?? null);
        $relation = $this->createMock(RDBRelation::class);
        $relation->method('find')->willReturn(new EntityCollection([$native]));
        $conversationRepo = $this->createMock(RDBRepository::class);
        $conversationRepo->method('getRelation')->with($conversation, 'opportunities')->willReturn($relation);
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('find')->willReturn(new EntityCollection([
            $entity('r1', ['subjectType' => 'Opportunity', 'subjectId' => 'forward']),
            $entity('r2', ['subjectType' => 'ChatwootConversation', 'objectId' => 'reverse']),
            $entity('r3', ['subjectType' => 'Opportunity', 'subjectId' => 'native']),
            $entity('r4', ['subjectType' => 'Opportunity', 'subjectId' => 'foreign']),
            $entity('r5', ['subjectType' => 'Opportunity', 'subjectId' => 'deleted-opportunity']),
        ]));
        $knowledgeRepo = $this->createMock(RDBRepository::class);
        $knowledgeRepo->expects($this->once())->method('where')->with([
            'tenantId' => 'tenant-1', 'status' => 'confirmed', 'predicate' => 'builtin:part_of',
            'createdAt<=' => '2026-10-08 14:27:22', 'decidedAt<=' => '2026-10-08 14:27:22',
            'OR' => [
                ['subjectType' => 'Opportunity', 'objectType' => 'ChatwootConversation', 'objectId' => 'conversation-1'],
                ['objectType' => 'Opportunity', 'subjectType' => 'ChatwootConversation', 'subjectId' => 'conversation-1'],
            ],
        ])->willReturn($builder);
        $em->method('getRDBRepository')->willReturnCallback(static fn ($type) =>
            $type === 'RecordRelation' ? $knowledgeRepo : $conversationRepo);
        $tenants = $this->createMock(TenantResolver::class);
        $tenants->method('resolveTenantIdForEntity')->willReturnCallback(static fn ($e) => $e->get('tenantId'));
        $tracker = new JourneyWhatsAppReplyTracker($em, $this->createMock(JourneySignalDispatcher::class),
            $tenants, $this->createMock(InjectableFactory::class), $this->createMock(Log::class));

        $this->assertSame([$native, $forward, $reverse],
            $tracker->getLinkedOpportunities($conversation, 'tenant-1', '2026-10-08 14:27:22'));
    }

    /** @dataProvider rejectedMessages */
    public function testIgnoresPrivateAndNonIncomingMessages(string $type, bool $private): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('getEntityById');
        $dispatcher = $this->createMock(JourneySignalDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');
        $tracker = new JourneyWhatsAppReplyTracker($em, $dispatcher,
            $this->createMock(TenantResolver::class), $this->createMock(InjectableFactory::class),
            $this->createMock(Log::class));
        $message = $this->createStub(Entity::class);
        $message->method('getEntityType')->willReturn('ChatwootMessage');
        $message->method('get')->willReturnCallback(static fn ($key) =>
            ['messageType' => $type, 'isPrivate' => $private][$key] ?? null);
        $tracker->handleChatwootMessage($message);
    }

    public static function rejectedMessages(): array
    {
        return [['outgoing', false], ['activity', false], ['incoming', true]];
    }

    /** @dataProvider rejectedConversations */
    public function testDoesNotCorrelateForeignAccountOrNonWhatsApp(string $account, string $channel): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('getRDBRepository');
        $dispatcher = $this->createMock(JourneySignalDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');
        $tracker = new JourneyWhatsAppReplyTracker($em, $dispatcher,
            $this->createMock(TenantResolver::class), $this->createMock(InjectableFactory::class),
            $this->createMock(Log::class));
        $conversation = $this->createStub(Entity::class);
        $conversation->method('get')->willReturnCallback(static fn ($key) =>
            ['chatwootAccountId' => $account, 'inboxChannelType' => $channel][$key] ?? null);
        (new ReflectionMethod($tracker, 'dispatchLinkedOpportunities'))->invoke(
            $tracker, $conversation, 'account-1', 42, time(),
        );
    }

    public static function rejectedConversations(): array
    {
        return [['other-account', 'whatsappCloudApi'], ['account-1', 'instagram'],
            ['account-1', 'email'], ['account-1', '']];
    }
}
