<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Tools;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Tools\ConversationGone;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class ConversationGoneTest extends TestCase
{
    private function entity(array $values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        $entity->method('getId')->willReturn('conv');
        return $entity;
    }

    /** @return array{Entity, Entity, Entity} conversation, account, platform */
    private function records(): array
    {
        return [
            $this->entity(['chatwootConversationId' => 2775]),
            $this->entity(['apiKey' => 'key', 'chatwootAccountId' => 12]),
            $this->entity(['backendUrl' => 'http://chatwoot']),
        ];
    }

    public function testAConversationChatwootNoLongerHasIsRemovedWithoutDeletingItInChatwoot(): void
    {
        [$conversation, $account, $platform] = $this->records();
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::once())->method('getConversation')->with('http://chatwoot', 'key', 12, 2775)->willReturn(null);
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::once())->method('removeEntity')
            ->with($conversation, ['cascadeParent' => true, 'skipChatwootDelete' => true]);

        self::assertTrue((new ConversationGone($em, $api, $this->createMock(Log::class)))->check($conversation, $account, $platform));
    }

    public function testAConversationChatwootStillHasIsKept(): void
    {
        [$conversation, $account, $platform] = $this->records();
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getConversation')->willReturn(['id' => 2775]);
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('removeEntity');

        self::assertFalse((new ConversationGone($em, $api, $this->createMock(Log::class)))->check($conversation, $account, $platform));
    }

    public function testAnErrorIsNotTakenForADeletion(): void
    {
        [$conversation, $account, $platform] = $this->records();
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getConversation')->willThrowException(new Error('Failed to get conversation from Chatwoot: HTTP 502'));
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('removeEntity');

        self::assertFalse((new ConversationGone($em, $api, $this->createMock(Log::class)))->check($conversation, $account, $platform));
    }
}
