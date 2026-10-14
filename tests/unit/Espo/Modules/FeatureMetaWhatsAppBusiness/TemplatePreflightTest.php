<?php

namespace tests\unit\Espo\Modules\FeatureMetaWhatsAppBusiness;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\MetaGraphApiClient;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\TemplateSendPreflight;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\Tokens;
use Espo\Tools\OAuth\TokensProvider;
use PHPUnit\Framework\TestCase;

class TemplatePreflightTest extends TestCase
{
    private function entity(array $values): Entity
    {
        $entity = $this->createStub(Entity::class);
        $entity->method('getId')->willReturn('inbox');
        $entity->method('get')->willReturnCallback(fn ($key) => $values[$key] ?? null);
        return $entity;
    }

    private function preflight(ChatwootApiClient $chatwoot, ?array $live = null): TemplateSendPreflight
    {
        $meta = $this->createMock(MetaGraphApiClient::class);
        $meta->method('getExactTemplate')->with('secret', '123', 'order_ready', 'pt_BR',
            $this->isInstanceOf(TemplateDeadline::class))->willReturn($live ?? TemplateDefinitionTest::definition() + ['status' => 'APPROVED']);
        $em = $this->createStub(EntityManager::class);
        $em->method('getEntityById')->willReturn($this->entity([
            'status' => 'ACTIVE', 'businessAccountId' => '123', 'oAuthAccountId' => 'oauth',
        ]));
        $tokens = $this->createStub(TokensProvider::class);
        $tokens->method('get')->willReturn(new Tokens(accessToken: 'secret', refreshToken: null, expiresAt: null));
        return new class($em, $tokens, $this->createStub(CredentialResolver::class), $meta, $chatwoot,
            $this->createStub(Log::class)) extends TemplateSendPreflight {
            public int $waits = 0;
            protected function waitBetweenChecks(): void { $this->waits++; }
        };
    }

    private function check(TemplateSendPreflight $service, mixed $header = ['1' => '{{firstName}}']): array
    {
        return $service->check($this->entity(['chatwootInboxIntegrationId' => 'integration', 'chatwootInboxId' => 8]),
            'https://chat.test', 'key', 9, 'order_ready', 'pt_BR', ['1' => '{{order}}'], $header, null, null);
    }

    /** @dataProvider cacheDelays */
    public function testMatchingAndDelayedCachesRefreshOnlyOnce(int $delay): void
    {
        $live = TemplateDefinitionTest::definition() + ['status' => 'APPROVED'];
        $api = $this->createMock(ChatwootApiClient::class);
        $refreshed = false;
        $api->expects($this->once())->method('refreshTemplateCatalog')->willReturnCallback(function () use (&$refreshed) { $refreshed = true; });
        $reads = 0;
        $api->expects($this->exactly($delay + 1))->method('readTemplateCatalog')
            ->willReturnCallback(function () use ($live, $delay, &$reads, &$refreshed) {
                $this->assertTrue($refreshed);
                return ++$reads <= $delay ? [array_replace($live, ['language' => 'en_US'])] : [$live];
            });
        $service = $this->preflight($api);
        $this->assertSame($live, $this->check($service));
        $this->assertSame($delay, $service->waits);
    }

    public static function cacheDelays(): array { return [[0], [2]]; }

    public function testRefreshFailureNeverChecksOrSends(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects($this->once())->method('refreshTemplateCatalog')->willThrowException(new Error('Refresh failed'));
        $api->expects($this->never())->method('readTemplateCatalog');
        $api->expects($this->never())->method('sendTemplateMessage');
        $this->expectException(Error::class);
        $this->check($this->preflight($api));
    }

    public function testMissingHeaderMappingBlocksBeforeRefresh(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects($this->never())->method('refreshTemplateCatalog');
        $this->expectException(Error::class);
        $this->check($this->preflight($api), null);
    }

    public function testNonApprovedLiveTemplateBlocksBeforeRefresh(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects($this->never())->method('refreshTemplateCatalog');
        $this->expectException(Error::class);
        $this->check($this->preflight($api, TemplateDefinitionTest::definition() + ['status' => 'PAUSED']));
    }

    public function testStaleCacheAndExpiredDeadlineFailClosed(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects($this->once())->method('readTemplateCatalog')->willReturn([]);
        $service = $this->preflight($api);
        $this->expectException(Error::class);
        // Less than one interval left: do not poll again or extend the budget.
        $service->awaitReady(TemplateDefinitionTest::definition(), 'https://chat.test', 'key', 1, 2, new TemplateDeadline(1));
    }
}
