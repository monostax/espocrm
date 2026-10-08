<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppReplyReconciler;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppReplyTracker;
use Espo\Modules\FeatureJourney\Services\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class JourneyWhatsAppReplyReconcilerTest extends TestCase
{
    public function testRecoversReplyOnOlderPageBehindOutgoingMessagesWithOriginalTimestamp(): void
    {
        [$service, $conversation, $api, $tracker] = $this->service(true);
        $start = strtotime('2026-10-08 13:38:32 UTC');
        $message = static fn ($id, $type, $at, $private = false) => [
            'id' => $id, 'message_type' => $type, 'created_at' => $at, 'private' => $private,
        ];
        $api->expects($this->exactly(2))->method('getConversationMessages')
            ->willReturnCallback(function ($url, $key, $account, $conversation, $before) use ($message, $start) {
                $this->assertSame(['http://chatwoot', 'token', 6, 1616], [$url, $key, $account, $conversation]);
                return $before === null
                    ? [$message(31, 1, $start + 300), $message(30, 'outgoing', $start + 200)]
                    : (function () use ($before, $message, $start) {
                        $this->assertSame(30, $before);
                        return [$message(29, 0, $start + 100), $message(28, 0, $start + 90, true),
                            $message(27, 2, $start + 80), $message(26, 0, $start - 1)];
                    })();
            });
        $tracker->expects($this->once())->method('handleWebhookIncoming')->with([
            'chatwootConversationId' => '1616', 'espoAccountId' => 'account',
            'chatwootMessageId' => 29, 'createdAt' => $start + 100, 'content' => null,
        ]);
        $this->assertSame([27, 28, 29, 30, 31], array_column($service->reconcile($conversation), 'id'));
    }

    public function testDoesNotPollWithoutActiveEnrollment(): void
    {
        [$service, $conversation, $api, $tracker] = $this->service(false);
        $api->expects($this->never())->method('getConversationMessages');
        $tracker->expects($this->never())->method('handleWebhookIncoming');
        $this->assertSame([], $service->reconcile($conversation));
    }

    public function testRepeatedPageFailsWithoutEmittingPartialReplay(): void
    {
        [$service, $conversation, $api, $tracker] = $this->service(true);
        $api->expects($this->exactly(2))->method('getConversationMessages')->willReturn([
            ['id' => 29, 'message_type' => 0, 'created_at' => strtotime('2026-10-08 14:27:22 UTC')],
        ]);
        $tracker->expects($this->never())->method('handleWebhookIncoming');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pagination did not advance');
        $service->reconcile($conversation);
    }

    private function service(bool $active): array
    {
        $entity = function (string $id, array $values = []): Entity {
            $entity = $this->createStub(Entity::class);
            $entity->method('getId')->willReturn($id);
            $entity->method('get')->willReturnCallback(static fn ($key) => $values[$key] ?? null);
            return $entity;
        };
        $conversation = $entity('conversation', ['inboxChannelType' => 'whatsappCoexistence',
            'chatwootAccountId' => 'account', 'chatwootConversationId' => 1616, 'tenantId' => 'tenant']);
        $account = $entity('account', ['platformId' => 'platform', 'apiKey' => 'token', 'chatwootAccountId' => 6, 'tenantId' => 'tenant']);
        $platform = $entity('platform', ['backendUrl' => 'http://chatwoot']);
        $journey = $entity('journey', ['status' => 'Active', 'tenantId' => 'tenant']);
        $record = $entity('record', ['journeyId' => 'journey', 'createdAt' => '2026-10-08 13:38:32']);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(static fn ($type, $id) =>
            ['account' => $account, 'platform' => $platform, 'journey' => $journey][$id] ?? null);
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('find')->willReturn(new EntityCollection($active ? [$record] : []));
        $repo = $this->createMock(RDBRepository::class);
        $repo->expects($this->once())->method('where')->with([
            'tenantId' => 'tenant', 'status' => 'Active',
            'OR' => [['targetType' => 'Opportunity', 'targetId' => ['opportunity']]],
        ])->willReturn($builder);
        $em->method('getRDBRepository')->with('JourneyRecord')->willReturn($repo);
        $tracker = $this->createMock(JourneyWhatsAppReplyTracker::class);
        $tracker->method('getLinkedOpportunities')->willReturn([$entity('opportunity')]);
        $tenants = $this->createMock(TenantResolver::class);
        $tenants->method('resolveTenantIdForEntity')->willReturnCallback(static fn ($e) => $e->get('tenantId'));
        $api = $this->createMock(ChatwootApiClient::class);
        return [new JourneyWhatsAppReplyReconciler($em, $api, $tracker, $tenants), $conversation, $api, $tracker];
    }
}
