<?php

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\FileStorage\Manager;
use Espo\Core\Utils\Log;
use Espo\Entities\Attachment;
use Espo\Modules\Chatwoot\Services\AgentAvatarSyncService;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AgentAvatarSyncServiceTest extends TestCase
{
    private function fixture(array $overrides = [], bool $clear = false): array
    {
        $data = [
            'User' => ['id' => 'crm-user', 'avatarId' => $clear ? null : 'avatar'],
            'ChatwootUser' => ['id' => 'cw-user', 'assignedUserId' => 'crm-user', 'platformId' => 'platform',
                'chatwootUserId' => 108, 'emailAddress' => 'agent@example.test'],
            'ChatwootMachineIdentity' => ['id' => 'identity', 'status' => 'active', 'kind' => 'agent',
                'crmUserId' => 'crm-user', 'chatwootUserId' => 'cw-user', 'accountId' => 'account',
                'membershipId' => 'membership', 'platformId' => 'platform', 'tenantId' => 'tenant',
                'remoteAccountId' => 13, 'remoteUserId' => 108, 'email' => 'agent@example.test',
                'operationId' => 'operation', 'machineIdentityId' => 'machine'],
            'ChatwootAccount' => ['id' => 'account', 'status' => 'active', 'tenantId' => 'tenant',
                'platformId' => 'platform', 'chatwootAccountId' => 13],
            'ChatwootAccountUserMembership' => ['id' => 'membership', 'chatwootUserId' => 'cw-user', 'chatwootAccountId' => 'account'],
            'ChatwootPlatform' => ['id' => 'platform', 'backendUrl' => 'http://chatwoot.test', 'accessToken' => 'platform-token'],
        ];
        $entities = [];
        foreach ($data as $type => $attributes) {
            $attributes = array_replace($attributes, $overrides[$type] ?? []);
            $entity = $this->createMock(Entity::class);
            $entity->method('getId')->willReturn($attributes['id']);
            $entity->method('get')->willReturnCallback(fn ($key) => $attributes[$key] ?? null);
            $entity->method('set')->willReturnSelf();
            $entities[$type] = $entity;
        }
        $attachment = $this->createMock(Attachment::class);
        $attachment->method('getType')->willReturn('image/png');
        $attachment->method('getName')->willReturn('avatar.png');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn ($type, $id) => $type === 'Attachment' ? $attachment : $entities[$type] ?? null
        );
        $repositories = [];
        foreach (['ChatwootUser', 'ChatwootMachineIdentity'] as $type) {
            $builder = $this->createMock(RDBSelectBuilder::class);
            $builder->method('find')->willReturn(new EntityCollection([$entities[$type]]));
            $builder->method('findOne')->willReturn($entities[$type]);
            $repository = $this->createMock(RDBRepository::class);
            $repository->method('where')->willReturn($builder);
            $repositories[$type] = $repository;
        }
        $em->method('getRDBRepository')->willReturnCallback(fn ($type) => $repositories[$type]);
        $files = $this->createMock(Manager::class);
        $api = $this->createMock(ChatwootApiClient::class);
        // A managed binding must never fall back to personal-token/profile access.
        $api->expects($this->never())->method('fetchUserAccessToken');
        $api->expects($this->never())->method('updateUserAvatarFromBytes');
        $api->expects($this->never())->method('deleteUserAvatar');
        $service = new AgentAvatarSyncService($em, $files, $api, $this->createMock(Log::class));
        return [$service, $api, $em, $files, $entities['ChatwootUser']];
    }

    public function testUploadsThroughProtectedBindingAndAdvancesHash(): void
    {
        [$service, $api, $em, $files, $user] = $this->fixture();
        $files->method('getContents')->willReturn('image-bytes');
        $api->expects($this->once())->method('syncMachineIdentityAvatar')->with(
            'http://chatwoot.test', 'platform-token', 13, 'machine', [
                'operation_id' => 'operation', 'tenant_id' => 'tenant', 'crm_account_id' => 'account',
                'crm_agent_id' => 'membership', 'email' => 'agent@example.test', 'user_id' => '108',
            ], ['bytes' => 'image-bytes', 'mime' => 'image/png', 'name' => 'avatar.png']
        );
        $user->expects($this->once())->method('set')->with('crmAvatarSyncHash', hash('sha256', 'image-bytes'));
        $em->expects($this->once())->method('saveEntity')->with($user, ['silent' => true, 'skipHooks' => true]);
        $service->pushCrmUserAvatarToChatwoot('crm-user');
    }

    public function testExplicitRemovalUsesManagedEndpoint(): void
    {
        [$service, $api, $em, $files] = $this->fixture(clear: true);
        $files->expects($this->never())->method('getContents');
        $api->expects($this->once())->method('syncMachineIdentityAvatar')->with(
            'http://chatwoot.test', 'platform-token', 13, 'machine', $this->isType('array'), null
        );
        $em->expects($this->once())->method('saveEntity');
        $service->pushCrmUserAvatarToChatwoot('crm-user');
    }

    #[DataProvider('invalidBindings')]
    public function testRejectsBrokenBindingWithoutFallbackOrHashUpdate(array $override): void
    {
        [$service, $api, $em, $files] = $this->fixture($override);
        $files->method('getContents')->willReturn('image-bytes');
        $api->expects($this->never())->method('syncMachineIdentityAvatar');
        $em->expects($this->never())->method('saveEntity');
        $service->pushCrmUserAvatarToChatwoot('crm-user');
    }

    public static function invalidBindings(): array
    {
        return [
            'retired' => [['ChatwootMachineIdentity' => ['status' => 'retired']]],
            'different CRM user' => [['ChatwootMachineIdentity' => ['crmUserId' => 'other-user']]],
            'missing CRM user' => [['ChatwootMachineIdentity' => ['crmUserId' => null]]],
            'other kind' => [['ChatwootMachineIdentity' => ['kind' => 'concierge']]],
            'other tenant' => [['ChatwootAccount' => ['tenantId' => 'other-tenant']]],
            'inactive account' => [['ChatwootAccount' => ['status' => 'inactive']]],
            'other account platform' => [['ChatwootAccount' => ['platformId' => 'other-platform']]],
            'other user platform' => [['ChatwootUser' => ['platformId' => 'other-platform']]],
            'other remote account' => [['ChatwootAccount' => ['chatwootAccountId' => 14]]],
            'other remote user' => [['ChatwootUser' => ['chatwootUserId' => 109]]],
            'other email' => [['ChatwootUser' => ['emailAddress' => 'other@example.test']]],
            'other membership user' => [['ChatwootAccountUserMembership' => ['chatwootUserId' => 'other-user']]],
            'other membership account' => [['ChatwootAccountUserMembership' => ['chatwootAccountId' => 'other-account']]],
            'missing platform token' => [['ChatwootPlatform' => ['accessToken' => null]]],
        ];
    }

    public function testFailedUploadDoesNotAdvanceHash(): void
    {
        [$service, $api, $em, $files] = $this->fixture();
        $files->method('getContents')->willReturn('image-bytes');
        $api->method('syncMachineIdentityAvatar')->willThrowException(new \RuntimeException('HTTP 403'));
        $em->expects($this->never())->method('saveEntity');
        $service->pushCrmUserAvatarToChatwoot('crm-user');
    }

    public function testUnreadableAttachmentDoesNotDeleteRemoteAvatar(): void
    {
        [$service, $api, $em, $files] = $this->fixture();
        $files->method('getContents')->willThrowException(new \RuntimeException('Storage unavailable'));
        $api->expects($this->never())->method('syncMachineIdentityAvatar');
        $em->expects($this->never())->method('saveEntity');
        $service->pushCrmUserAvatarToChatwoot('crm-user');
    }
}
