<?php

namespace tests\unit\Espo\Modules\FeatureMetaWhatsAppBusiness;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\SendWhatsAppTemplate;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline;
use Espo\Modules\Global\Rebuild\SeedRole;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;

class TemplateSenderIntegrationTest extends TestCase
{
    private function client(): ChatwootApiClient
    {
        return new class($this->createStub(Config::class), $this->createStub(Log::class)) extends ChatwootApiClient {
            public array $calls = [];
            protected function executeRequest(string $url, string $method, ?string $payload = null,
                array $headers = [], bool $followLocation = true, ?TemplateDeadline $templateDeadline = null): array
            {
                $this->calls[] = ['url' => $url, 'body' => json_decode($payload ?? '{}', true), 'deadline' => $templateDeadline];
                return ['code' => 200, 'body' => ['id' => 42]];
            }
        };
    }

    public function testRealChatwootMessagePayloadContainsOrderedIndependentHeaderAndBodyHashes(): void
    {
        $api = $this->client();
        $api->sendTemplateMessage('https://chat.test', 'key', 1, 2, 3, 'order', 'pt_BR',
            ['2' => 'second', '1' => 'body'], 'UTILITY', 'Preview', null, null, ['1' => 'header']);
        $this->assertCount(2, $api->calls);
        $payload = $api->calls[1]['body']['template_params'];
        $this->assertSame(['name' => 'order', 'category' => 'utility', 'language' => 'pt_BR',
            'processed_params' => ['body' => [1 => 'body', 2 => 'second'], 'header' => [1 => 'header']]], $payload);
    }

    public function testLegacyMediaPayloadAndTextFailureBeforeConversationCreation(): void
    {
        $api = $this->client();
        $api->sendTemplateMessage('https://chat.test', 'key', 1, 2, 3, 'order', 'pt_BR', ['1' => 'body'],
            'UTILITY', '', 'https://example.com/a.pdf', 'document');
        $this->assertSame(['body' => [1 => 'body'], 'header' => [
            'media_url' => 'https://example.com/a.pdf', 'media_type' => 'document',
        ]], $api->calls[1]['body']['template_params']['processed_params']);
        $api = $this->client();
        try {
            $api->sendTemplateMessage('https://chat.test', 'key', 1, 2, 3, 'order', 'pt_BR', ['1' => '']);
            $this->fail('Expected unresolved parameter error');
        } catch (Error) { $this->assertSame([], $api->calls); }
    }

    public function testRealChatwootMessagePayloadPreservesNamedBodyParameters(): void
    {
        $api = $this->client();
        $api->sendTemplateMessage('https://chat.test', 'key', 1, 2, 3, 'order_ready', 'pt_BR',
            ['tenant_name' => 'Monostax', 'order_id' => '123'], 'UTILITY', 'Order 123 for Monostax');
        $this->assertCount(2, $api->calls);
        $this->assertSame([
            'name' => 'order_ready', 'category' => 'utility', 'language' => 'pt_BR',
            'processed_params' => ['body' => ['order_id' => '123', 'tenant_name' => 'Monostax']],
        ], $api->calls[1]['body']['template_params']);
    }

    public function testJourneyPreflightsOnceThenSendsIndependentValuesToEachRecipient(): void
    {
        $target = $this->createStub(Entity::class); $target->method('getEntityType')->willReturn('Contact');
        $record = $this->createStub(Entity::class); $record->method('getId')->willReturn('record');
        $journey = $this->createStub(Entity::class); $journey->method('getId')->willReturn('journey');
        $params = ['chatwootInboxId' => 'inbox', 'templateName' => 'order', 'templateLanguage' => 'pt_BR',
            'templateBody' => 'Body {{1}}', 'parameterMapping' => ['1' => '{{order}}'],
            'headerParameterMapping' => ['1' => '{{firstName}}']];
        $outbound = $this->createMock(JourneyWhatsAppOutbound::class);
        $outbound->method('resolvePhones')->willReturn(['+5511999999999', '+5511888888888']);
        $outbound->method('resolveConnection')->willReturn(['connection' => true]);
        $outbound->expects($this->once())->method('preflightTemplate')->with(['connection' => true], 'order', 'pt_BR', $params)
            ->willReturn(['category' => 'UTILITY']);
        $outbound->method('resolveParameterMapping')->willReturnCallback(fn ($mapping) =>
            $mapping === $params['parameterMapping'] ? ['1' => '123'] : ['1' => 'Ana']);
        $outbound->expects($this->exactly(2))->method('sendTemplate')->willReturnCallback(function (...$args) {
            $this->assertSame(['1' => '123'], $args[5]);
            $this->assertSame('Body 123', $args[7]);
            $this->assertSame(['1' => 'Ana'], $args[11]);
            return ['message_id' => 1, 'conversation_id' => 2];
        });
        $context = new ActionContext($target, $record, $record, $journey, 'enter', $params, 'tenant');
        (new SendWhatsAppTemplate($outbound, $this->createStub(TenantGuard::class), $this->createStub(Log::class)))->run($context);
    }

    public function testJourneyPreservesNamedMappingsAndRendersTheMessagePreview(): void
    {
        $target = $this->createStub(Entity::class); $target->method('getEntityType')->willReturn('Tenant');
        $record = $this->createStub(Entity::class); $record->method('getId')->willReturn('record');
        $journey = $this->createStub(Entity::class); $journey->method('getId')->willReturn('journey');
        $params = ['chatwootInboxId' => 'inbox', 'templateName' => 'order_ready', 'templateLanguage' => 'pt_BR',
            'templateBody' => 'Hello {{tenant_name}}, order {{order_id}} is ready.',
            'parameterMapping' => ['tenant_name' => '{{name}}', 'order_id' => '123']];
        $outbound = $this->createMock(JourneyWhatsAppOutbound::class);
        $outbound->method('resolvePhones')->willReturn(['+5511999999999']);
        $outbound->method('resolveConnection')->willReturn(['connection' => true]);
        $outbound->expects($this->once())->method('preflightTemplate')->willReturn(TemplateParametersTest::namedTemplate());
        $outbound->method('resolveParameterMapping')->willReturnCallback(fn ($mapping) =>
            $mapping ? ['tenant_name' => 'Monostax', 'order_id' => '123'] : []);
        $outbound->expects($this->once())->method('sendTemplate')->willReturnCallback(function (...$args) {
            $this->assertSame(['tenant_name' => 'Monostax', 'order_id' => '123'], $args[5]);
            $this->assertSame('Hello Monostax, order 123 is ready.', $args[7]);
            $this->assertSame([], $args[11]);
            return ['message_id' => 1, 'conversation_id' => 2];
        });
        $context = new ActionContext($target, $record, $record, $journey, 'enter', $params, 'tenant');
        (new SendWhatsAppTemplate($outbound, $this->createStub(TenantGuard::class), $this->createStub(Log::class)))->run($context);
    }

    public function testAllInheritedTenantRolesGainOnlyTemplateManagementGrants(): void
    {
        $seed = (new \ReflectionClass(SeedRole::class))->newInstanceWithoutConstructor();
        $definitions = (new \ReflectionMethod(SeedRole::class, 'getRoleDefinitions'))->invoke($seed);
        foreach (['tenant', 'tenant-admin', 'tenant-user-api'] as $id) {
            $role = array_values(array_filter($definitions, fn ($role) => $role['staticId'] === $id))[0];
            $this->assertSame(['create' => 'yes', 'read' => 'team', 'edit' => 'team', 'delete' => 'team'],
                $role['data']['WhatsAppBusinessAccountMessageTemplate']);
            foreach (['WhatsAppBusinessAccount', 'WhatsAppBusinessAccountPhoneNumber', 'WhatsAppBusinessAccountWebhook'] as $type) {
                $this->assertSame(['create' => 'no', 'read' => 'team', 'edit' => 'no', 'delete' => 'no'], $role['data'][$type]);
            }
        }
    }

    /** @dataProvider campaignEntryPoints */
    public function testCampaignReadinessFailureBlocksActivationAndJobs(string $entryPoint): void
    {
        $entity = function (array $values): Entity {
            $entity = $this->createMock(Entity::class);
            $entity->method('getId')->willReturn('record');
            $entity->method('get')->willReturnCallback(fn ($key) => $values[$key] ?? null);
            $entity->expects($this->never())->method('set');
            return $entity;
        };
        $campaign = $entity(['status' => 'Draft', 'chatwootInboxId' => 'inbox', 'chatwootAccountId' => 'account',
            'channelType' => 'whatsappCloudApi', 'messageMode' => 'template', 'templateName' => 'order',
            'templateLanguage' => 'pt_BR', 'parameterMapping' => ['1' => '{{order}}'],
            'headerParameterMapping' => ['1' => '{{firstName}}']]);
        $account = $entity(['platformId' => 'platform', 'apiKey' => 'key', 'chatwootAccountId' => 1]);
        $platform = $entity(['backendUrl' => 'https://chat.test']);
        $inbox = $entity(['channelType' => 'whatsappCloudApi']);
        $em = $this->createMock(\Espo\ORM\EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type) => match ($type) {
            'WhatsAppCampaign' => $campaign, 'ChatwootAccount' => $account, 'ChatwootPlatform' => $platform,
        });
        $em->expects($this->never())->method('saveEntity');
        $em->expects($this->never())->method('getNewEntity');
        $jobs = $this->createMock(\Espo\Core\Job\JobSchedulerFactory::class);
        $jobs->expects($this->never())->method('create');
        $preflight = $this->createMock(\Espo\Modules\FeatureMetaWhatsAppBusiness\Services\TemplateSendPreflight::class);
        $preflight->expects($this->once())->method('check')->with($inbox, 'https://chat.test', 'key', 1,
            'order', 'pt_BR', ['1' => '{{order}}'], ['1' => '{{firstName}}'], null, null)
            ->willThrowException(new Error('Catalog not ready.'));
        $service = $this->getMockBuilder(\Espo\Modules\Chatwoot\Services\WhatsAppCampaignService::class)
            ->setConstructorArgs([$em, $this->createStub(\Espo\Modules\FeatureMetaWhatsAppBusiness\Services\MetaGraphApiClient::class),
                $this->createStub(ChatwootApiClient::class), $this->createStub(Log::class), $jobs,
                $this->createStub(\Espo\Modules\Chatwoot\Services\WhatsAppCampaignOpportunityService::class), $preflight])
            ->onlyMethods(['resolveAudience', 'resolveCampaignInbox'])->getMock();
        $service->method('resolveAudience')->willReturn([['contactId' => 'contact', 'phoneNumber' => '+5511999999999']]);
        $service->method('resolveCampaignInbox')->willReturn($inbox);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Catalog not ready.');
        $service->$entryPoint('campaign');
    }

    public static function campaignEntryPoints(): array
    {
        return [['launch'], ['activateForDistribution']];
    }
}
