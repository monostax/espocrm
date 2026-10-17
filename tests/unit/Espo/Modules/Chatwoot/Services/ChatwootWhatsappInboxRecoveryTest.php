<?php

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootInboxIntegration;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class ChatwootWhatsappInboxRecoveryTest extends TestCase
{
    private function inbox(): array
    {
        return [
            'id' => 27458,
            'channel_type' => 'Channel::Whatsapp',
            'provider' => 'whatsapp_cloud',
            'phone_number' => '+559391456400',
            'provider_config' => [
                'phone_number_id' => 'phone-id',
                'business_account_id' => 'waba-id',
                'api_key' => 'meta-token',
                'is_coexistence' => false,
                'verification_pin' => '123456',
                'webhook_verify_token' => 'verify-token',
            ],
        ];
    }

    private function service(ChatwootApiClient $api, ?string $linkedIntegration = null): ChatwootInboxIntegration
    {
        $local = null;
        if ($linkedIntegration) {
            $local = $this->createMock(Entity::class);
            $local->method('get')->with('chatwootInboxIntegrationId')->willReturn($linkedIntegration);
        }
        $query = $this->createMock(RDBSelectBuilder::class);
        $query->method('findOne')->willReturn($local);
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->with([
            'chatwootAccountId' => 'local-account',
            'chatwootInboxId' => 27458,
        ])->willReturn($query);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->with('ChatwootInbox')->willReturn($repository);

        $reflection = new ReflectionClass(ChatwootInboxIntegration::class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach (['entityManager' => $em, 'chatwootApiClient' => $api, 'log' => $this->createMock(Log::class)] as $name => $value) {
            $reflection->getProperty($name)->setValue($service, $value);
        }
        return $service;
    }

    private function recover(ChatwootInboxIntegration $service, bool $coexistence = false): ?array
    {
        $channel = $this->createMock(Entity::class);
        $channel->method('get')->with('chatwootAccountId')->willReturn('local-account');
        $channel->method('getId')->willReturn('integration');
        return (new ReflectionMethod($service, 'recoverChatwootWhatsappCloudInbox'))->invoke(
            $service, $channel, 'https://chatwoot.test', 'account-token', 9,
            '+559391456400', 'meta-token', 'phone-id', 'waba-id', $coexistence
        );
    }

    public function testRecoversCommittedOrphanWithinRequestedAccountWithoutChangingConfig(): void
    {
        $inbox = $this->inbox();
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects($this->once())->method('listInboxes')
            ->with('https://chatwoot.test', 'account-token', 9)->willReturn(['payload' => [$inbox]]);
        $api->expects($this->never())->method('updateInbox');
        $api->expects($this->once())->method('registerWhatsappInboxWebhook')
            ->with('https://chatwoot.test', 'account-token', 9, 27458);
        $this->assertSame($inbox + ['_recovered' => true], $this->recover($this->service($api)));
    }

    public function testDoesNotReuseInboxFromAnotherPhone(): void
    {
        $inbox = $this->inbox();
        $inbox['phone_number'] = '+5511999999999';
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('listInboxes')->willReturn(['payload' => [$inbox]]);
        $api->expects($this->never())->method('registerWhatsappInboxWebhook');
        $this->assertNull($this->recover($this->service($api)));
    }

    public function testReconcilesAnAmbiguousCreateTransportFailure(): void
    {
        $inbox = $this->inbox();
        $api = $this->createMock(ChatwootApiClient::class);
        // The inbox was absent before POST but is visible when reconciling its
        // lost response. Port 1 fails immediately without an external service.
        $api->expects($this->exactly(2))->method('listInboxes')
            ->with('http://127.0.0.1:1', 'account-token', 9)
            ->willReturnOnConsecutiveCalls(['payload' => []], ['payload' => [$inbox]]);
        $api->expects($this->once())->method('registerWhatsappInboxWebhook')
            ->with('http://127.0.0.1:1', 'account-token', 9, 27458);
        $api->expects($this->never())->method('updateInbox');

        $channel = $this->createMock(Entity::class);
        $channel->method('get')->with('chatwootAccountId')->willReturn('local-account');
        $channel->method('getId')->willReturn('integration');
        $result = (new ReflectionMethod(ChatwootInboxIntegration::class, 'createChatwootWhatsappCloudInbox'))->invoke(
            $this->service($api), $channel, 'http://127.0.0.1:1', 'account-token', 9,
            'WhatsApp - Curua-Una', '+559391456400', 'meta-token', 'phone-id', 'waba-id'
        );
        $this->assertSame($inbox + ['_recovered' => true], $result);
    }

    public function testRejectsDifferentBusinessAccountBeforeAnyWrite(): void
    {
        $inbox = $this->inbox();
        $inbox['provider_config']['business_account_id'] = 'other-business';
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('listInboxes')->willReturn(['payload' => [$inbox]]);
        $api->expects($this->never())->method('registerWhatsappInboxWebhook');
        $api->expects($this->never())->method('updateInbox');
        $this->expectException(Error::class);
        $this->expectExceptionMessage('different WhatsApp configuration');
        $this->recover($this->service($api));
    }

    public function testDoesNotStealInboxFromAnotherIntegration(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('listInboxes')->willReturn(['payload' => [$this->inbox()]]);
        $api->expects($this->never())->method('registerWhatsappInboxWebhook');
        $api->expects($this->never())->method('updateInbox');
        $this->expectException(Error::class);
        $this->expectExceptionMessage('already linked');
        $this->recover($this->service($api, 'another-integration'));
    }

    public function testDoesNotOverwriteCredentialsOnAnExistingInbox(): void
    {
        $inbox = $this->inbox();
        $inbox['provider_config']['api_key'] = 'other-token';
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('listInboxes')->willReturn(['payload' => [$inbox]]);
        $api->expects($this->never())->method('updateInbox');
        $api->expects($this->never())->method('registerWhatsappInboxWebhook');
        $this->expectException(Error::class);
        $this->expectExceptionMessage('different credentials');
        $this->recover($this->service($api));
    }

    public function testCoexistenceRecoveryPreservesItsCrmManagedWebhook(): void
    {
        $inbox = $this->inbox();
        $inbox['provider_config']['is_coexistence'] = true;
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('listInboxes')->willReturn(['payload' => [$inbox]]);
        $api->expects($this->never())->method('registerWhatsappInboxWebhook');
        $this->assertSame($inbox + ['_recovered' => true], $this->recover($this->service($api), true));
    }

    public function testAccountAccessFailureIsNotTreatedAsMissingInbox(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects($this->once())->method('listInboxes')
            ->with('https://chatwoot.test', 'account-token', 9)
            ->willThrowException(new Error('HTTP 403'));
        $api->expects($this->never())->method('registerWhatsappInboxWebhook');
        $this->expectException(Error::class);
        $this->expectExceptionMessage('HTTP 403');
        $this->recover($this->service($api));
    }
}
