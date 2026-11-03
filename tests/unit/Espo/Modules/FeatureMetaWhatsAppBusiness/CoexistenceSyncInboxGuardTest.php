<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureMetaWhatsAppBusiness;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\MetaGraphApiClient;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppCoexistenceSyncService;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\Tools\OAuth\Tokens;
use Espo\Tools\OAuth\TokensProvider;
use PHPUnit\Framework\TestCase;

/**
 * Meta sends coexistence history once. Requested before the Chatwoot inbox
 * exists, every event is dropped and the stamp stops a later request.
 */
class CoexistenceSyncInboxGuardTest extends TestCase
{
    private const ACCOUNT_ID = 'oauth1';
    private const PHONE_ID = '1234567890';

    /** @var list<array<string, mixed>> */
    private array $where = [];

    public function testDefersWithoutAnInboxIntegration(): void
    {
        [$em, $account] = $this->entityManager([], null);
        $meta = $this->createMock(MetaGraphApiClient::class);
        $meta->expects($this->never())->method('getPhoneNumber');
        $meta->expects($this->never())->method('postSmbAppData');
        $tokens = $this->createMock(TokensProvider::class);
        $tokens->expects($this->never())->method('get');
        $em->expects($this->never())->method('saveEntity');

        $result = $this->service($em, $tokens, $meta)->sync(self::ACCOUNT_ID);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('awaiting_inbox', $result['reason']);
        $this->assertNull($account->get('whatsappCoexistenceSyncedAt'));
        $this->assertSame(
            ['oAuthAccountId' => self::ACCOUNT_ID, 'phoneNumberId' => self::PHONE_ID],
            $this->where[0],
        );
    }

    public function testDefersWhenTheIntegrationHasNoChatwootInboxYet(): void
    {
        [$em] = $this->entityManager([$this->entity('ChatwootInboxIntegration', ['id' => 'int1'])], null);
        $meta = $this->createMock(MetaGraphApiClient::class);
        $meta->expects($this->never())->method('postSmbAppData');
        $em->expects($this->never())->method('saveEntity');

        $result = $this->service($em, $this->createMock(TokensProvider::class), $meta)->sync(self::ACCOUNT_ID);

        $this->assertSame('awaiting_inbox', $result['reason']);
        $this->assertSame(
            ['chatwootInboxIntegrationId' => ['int1'], 'chatwootInboxId!=' => null],
            $this->where[1],
        );
    }

    public function testRequestsContactsAndHistoryOnceTheInboxExists(): void
    {
        [$em, $account] = $this->entityManager(
            [$this->entity('ChatwootInboxIntegration', ['id' => 'int1'])],
            $this->entity('ChatwootInbox', ['id' => 'inbox1']),
        );
        $tokens = $this->createMock(TokensProvider::class);
        $tokens->method('get')->willReturn(new Tokens('token', null, null));
        $meta = $this->createMock(MetaGraphApiClient::class);
        $meta->method('getPhoneNumber')->willReturn(['platform_type' => 'CLOUD_API', 'is_on_biz_app' => true]);
        $meta->method('getSubscribedApps')->willReturn([['whatsapp_business_api_data' => ['id' => 'app1']]]);
        $requested = [];
        $meta->expects($this->exactly(2))->method('postSmbAppData')
            ->willReturnCallback(function (string $token, string $phone, string $type) use (&$requested): array {
                $requested[] = $type;

                return ['success' => true];
            });
        $em->expects($this->once())->method('saveEntity')->with($account);

        $result = $this->service($em, $tokens, $meta)->sync(self::ACCOUNT_ID);

        $this->assertSame('synced', $result['status']);
        $this->assertSame(['smb_app_state_sync', 'history'], $requested);
        $this->assertNotNull($account->get('whatsappCoexistenceSyncedAt'));
    }

    private function service(EntityManager $em, TokensProvider $tokens, MetaGraphApiClient $meta): WhatsAppCoexistenceSyncService
    {
        return new WhatsAppCoexistenceSyncService($em, $tokens, $meta, $this->createMock(Log::class));
    }

    /**
     * @param list<Entity> $integrations
     * @return array{0: EntityManager&\PHPUnit\Framework\MockObject\MockObject, 1: Entity}
     */
    private function entityManager(array $integrations, ?Entity $inbox): array
    {
        $account = $this->entity('OAuthAccount', [
            'id' => self::ACCOUNT_ID,
            'providerId' => 'provider1',
            'whatsappPhoneNumberId' => self::PHONE_ID,
            'whatsappBusinessAccountId' => 'waba1',
            'whatsappCoexistenceSyncedAt' => null,
        ]);
        $provider = $this->entity('OAuthProvider', ['id' => 'provider1', 'clientId' => 'app1']);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn (string $type) => ['OAuthAccount' => $account, 'OAuthProvider' => $provider][$type] ?? null
        );
        $em->method('getRDBRepository')->willReturnCallback(
            fn (string $type) => $this->repository($type === 'ChatwootInboxIntegration' ? $integrations : array_filter([$inbox]))
        );

        return [$em, $account];
    }

    /**
     * @param array<Entity> $entities
     */
    private function repository(array $entities): RDBRepository
    {
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('where')->willReturnCallback(function (array $where) use ($builder) {
            $this->where[] = $where;

            return $builder;
        });
        $builder->method('find')->willReturn(new EntityCollection(array_values($entities)));
        $builder->method('findOne')->willReturn(array_values($entities)[0] ?? null);
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('select')->willReturn($builder);

        return $repository;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function entity(string $type, array $values): Entity
    {
        $entity = new BaseEntity($type, ['attributes' => array_fill_keys(array_keys($values), ['type' => 'varchar'])]);
        $entity->set($values);

        return $entity;
    }
}
