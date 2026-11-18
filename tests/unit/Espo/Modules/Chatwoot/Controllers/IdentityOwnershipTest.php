<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Controllers\IdentityOwnership;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Support/EntityDouble.php';

final class IdentityOwnershipTest extends TestCase
{
    public static function previews(): array
    {
        return [
            'synced WhatsApp conversation' => [[], null],
            'same number on both SMS and WhatsApp' => [['duplicate' => true], Conflict::class],
            'no identity for the destination' => [['source' => '+12025550199'], Conflict::class],
            'identity belongs to a different tenant' => [['tenant' => 'other'], Conflict::class],
            'explicit stale link takes precedence' => [['link' => 'missing'], Conflict::class],
            'opaque inbox source is not a phone' => [['kind' => 'inbox:70'], Conflict::class],
            'remote contact changed' => [['remoteContact' => 2], Conflict::class],
            'viewer cannot edit resolved identity' => [['editable' => false], Forbidden::class],
        ];
    }

    #[DataProvider('previews')]
    public function testPreviewWithoutSelectedIdentity(array $options, ?string $error): void
    {
        $account = new EntityDouble(['id' => 'account', 'tenantId' => 'tenant', 'platformId' => 'platform']);
        $conversation = new EntityDouble(['contactId' => 'contact', 'chatwootContactId' => 'bridge',
            'channelIdentityId' => $options['link'] ?? null]);
        $viewer = new EntityDouble(['userAccessToken' => 'viewer-token']);
        $contact = new EntityDouble(['id' => 'contact', 'tenantId' => 'tenant']);
        $phone = '+12025550123';
        $identityData = ['contactId' => 'contact', 'tenantId' => $options['tenant'] ?? 'tenant', 'sourceId' => $phone];
        $identity = new EntityDouble(['id' => 'phone', 'channelType' => 'whatsapp'] + $identityData);
        $identities = [$identity, new EntityDouble(['id' => 'email', 'channelType' => 'email'] + $identityData)];
        if ($options['duplicate'] ?? false) {
            $identities[] = new EntityDouble(['id' => 'sms', 'channelType' => 'sms'] + $identityData);
        }
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('saveEntity');
        $em->method('getEntityById')->willReturnMap([
            ['Contact', 'contact', $contact],
            ['ChatwootPlatform', 'platform', new EntityDouble(['backendUrl' => 'https://cw.test'])],
            ['ChatwootContact', 'bridge', new EntityDouble(['contactId' => 'contact', 'chatwootAccountId' => 'account', 'chatwootContactId' => 1])],
        ]);
        $em->method('getRDBRepository')->willReturnCallback(function ($type) use ($account, $conversation, $viewer, $identities) {
            $repo = $this->createStub(RDBRepository::class);
            $repo->method('where')->willReturnCallback(function ($where) use ($type, $account, $conversation, $viewer, $identities) {
                $query = $this->createStub(RDBSelectBuilder::class);
                $query->method('limit')->willReturnSelf();
                if ($type === 'ContactChannelIdentity') {
                    $rows = array_filter($identities, static function ($entity) use ($where) {
                        foreach ($where as $field => $values) {
                            if (!in_array($entity->get($field), (array) $values, true)) {
                                return false;
                            }
                        }
                        return true;
                    });
                    $query->method('find')->willReturn(new EntityCollection(array_values($rows)));
                } elseif ($type === 'ChatwootAccount') {
                    $query->method('find')->willReturn(new EntityCollection([$account]));
                } else {
                    $query->method('findOne')->willReturn(match ($type) {
                        'ChatwootConversation' => $conversation, 'ChatwootUser' => $viewer,
                    });
                }
                return $query;
            });
            return $repo;
        });
        $acl = $this->createStub(Acl::class);
        foreach (['checkScope', 'checkField', 'checkLink'] as $method) {
            $acl->method($method)->willReturn(true);
        }
        $acl->method('check')->willReturnCallback(fn ($record, $action) =>
            $record !== $identity || $action !== 'edit' || ($options['editable'] ?? true));
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('user');
        $tenants = $this->createStub(UserTenantResolver::class);
        $tenants->method('canActForTenant')->willReturn(true);
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::once())->method('identityOwnership')->with('https://cw.test', 'viewer-token', 6, 2733, null)
            ->willReturn(['contact_id' => $options['remoteContact'] ?? 1, 'identity_kind' => $options['kind'] ?? 'phone',
                'source_id' => $options['source'] ?? $phone, 'messages' => []]);
        $request = $this->createStub(Request::class);
        $request->method('getRouteParam')->willReturnMap([['accountId', '6'], ['conversationId', '2733']]);
        if ($error) {
            $this->expectException($error);
        }
        $preview = (new IdentityOwnership($em, $acl, $user, $tenants, $api))->getActionPreview($request);
        self::assertSame('phone', $preview->identityId);
        self::assertSame($phone, $preview->sourceId);
    }
}
