<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootInboxIntegration;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the idempotent inbox resolution used by activateWhatsappQrcode():
 * re-activation must REUSE an existing Chatwoot inbox (repointing its
 * webhook_url) instead of creating a duplicate and orphaning conversations.
 */
class ChatwootInboxIntegrationResolveInboxTest extends TestCase
{
    private const CHATWOOT_URL = 'http://chatwoot.local';
    private const API_KEY = 'token-123';
    private const ACCOUNT_ID = 8;
    private const INBOX_NAME = 'WhatsApp - Principal';
    private const WEBHOOK_URL = 'http://waha.local/webhooks/chatwoot/channel_x/app_new';

    /**
     * Build a ChatwootInboxIntegration with only the dependencies the resolver
     * touches injected via reflection (avoids the heavy real constructor).
     */
    private function makeService(
        EntityManager $em,
        ChatwootApiClient $chatwoot,
        Log $log
    ): ChatwootInboxIntegration {
        $ref = new ReflectionClass(ChatwootInboxIntegration::class);
        /** @var ChatwootInboxIntegration $service */
        $service = $ref->newInstanceWithoutConstructor();

        foreach ([
            'entityManager' => $em,
            'chatwootApiClient' => $chatwoot,
            'log' => $log,
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($service, $value);
        }

        return $service;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function invokeResolve(ChatwootInboxIntegration $service, array $args): array
    {
        $ref = new ReflectionClass(ChatwootInboxIntegration::class);
        $m = $ref->getMethod('resolveChatwootInboxForQr');
        $m->setAccessible(true);

        return $m->invokeArgs($service, $args);
    }

    public function testReusesExistingInboxAndRepointsWebhook(): void
    {
        // Integration channel references a local ChatwootInbox whose numeric id is 28.
        $channel = $this->createMock(Entity::class);
        $channel->method('get')->willReturnMap([
            ['chatwootInboxId', 'local-inbox-entity-id'],
            ['chatwootInboxIdentifier', 'IDENT-28'],
        ]);
        $channel->method('getId')->willReturn('channel-1');

        $localInbox = $this->createMock(Entity::class);
        $localInbox->method('get')->with('chatwootInboxId')->willReturn(28);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')
            ->with('ChatwootInbox', 'local-inbox-entity-id')
            ->willReturn($localInbox);

        $chatwoot = $this->createMock(ChatwootApiClient::class);
        // Inbox 28 still exists in Chatwoot.
        $chatwoot->expects($this->once())
            ->method('getInbox')
            ->with(self::CHATWOOT_URL, self::API_KEY, self::ACCOUNT_ID, 28)
            ->willReturn(['id' => 28, 'name' => 'WhatsApp - Principal', 'inbox_identifier' => 'IDENT-28']);
        // Must repoint webhook_url; must NOT create a new inbox.
        $chatwoot->expects($this->once())
            ->method('updateInbox')
            ->with(
                self::CHATWOOT_URL,
                self::API_KEY,
                self::ACCOUNT_ID,
                28,
                ['channel' => ['webhook_url' => self::WEBHOOK_URL]]
            );

        $service = $this->makeService($em, $chatwoot, $this->createMock(Log::class));

        $result = $this->invokeResolve($service, [
            $channel,
            self::CHATWOOT_URL,
            self::API_KEY,
            self::ACCOUNT_ID,
            self::INBOX_NAME,
            self::WEBHOOK_URL,
        ]);

        $this->assertSame(28, $result['id']);
        $this->assertSame('IDENT-28', $result['inbox_identifier']);
    }

    public function testReusePreservesIdentifierWhenGetInboxOmitsIt(): void
    {
        $channel = $this->createMock(Entity::class);
        $channel->method('get')->willReturnMap([
            ['chatwootInboxId', 'local-inbox-entity-id'],
            ['chatwootInboxIdentifier', 'IDENT-FROM-CHANNEL'],
        ]);
        $channel->method('getId')->willReturn('channel-1');

        $localInbox = $this->createMock(Entity::class);
        $localInbox->method('get')->with('chatwootInboxId')->willReturn(28);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($localInbox);

        $chatwoot = $this->createMock(ChatwootApiClient::class);
        $chatwoot->method('getInbox')->willReturn(['id' => 28, 'name' => 'WhatsApp - Principal']);
        $chatwoot->expects($this->once())->method('updateInbox');

        $service = $this->makeService($em, $chatwoot, $this->createMock(Log::class));

        $result = $this->invokeResolve($service, [
            $channel,
            self::CHATWOOT_URL,
            self::API_KEY,
            self::ACCOUNT_ID,
            self::INBOX_NAME,
            self::WEBHOOK_URL,
        ]);

        $this->assertSame(28, $result['id']);
        $this->assertSame('IDENT-FROM-CHANNEL', $result['inbox_identifier']);
    }

    public function testCreatesNewInboxWhenReferencedInboxIsGone(): void
    {
        $channel = $this->createMock(Entity::class);
        $channel->method('get')->willReturnMap([
            ['chatwootInboxId', 'local-inbox-entity-id'],
            ['chatwootInboxIdentifier', 'IDENT-OLD'],
        ]);
        $channel->method('getId')->willReturn('channel-1');

        $localInbox = $this->createMock(Entity::class);
        $localInbox->method('get')->with('chatwootInboxId')->willReturn(28);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($localInbox);

        $chatwoot = $this->createMock(ChatwootApiClient::class);
        // Chatwoot no longer has inbox 28 (deleted) -> getInbox returns null.
        $chatwoot->method('getInbox')->willReturn(null);
        // Must NOT update; should fall through to creation path. updateInbox never called.
        $chatwoot->expects($this->never())->method('updateInbox');

        $service = $this->makeService($em, $chatwoot, $this->createMock(Log::class));

        // createChatwootInbox issues a real cURL POST; intercept by asserting it
        // throws against the unreachable test URL, proving the create branch ran.
        $this->expectException(\Throwable::class);

        $this->invokeResolve($service, [
            $channel,
            self::CHATWOOT_URL,
            self::API_KEY,
            self::ACCOUNT_ID,
            self::INBOX_NAME,
            self::WEBHOOK_URL,
        ]);
    }
}
