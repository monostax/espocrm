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
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class JourneyWhatsAppReplyTrackerTest extends TestCase
{
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
