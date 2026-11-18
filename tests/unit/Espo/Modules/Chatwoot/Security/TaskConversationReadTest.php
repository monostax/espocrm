<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Security;

use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Classes\Acl\ChatwootConversation\AccessChecker;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Tools\Acl\InboxAccessResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

final class TaskConversationReadTest extends TestCase
{
    public function testPolicyLookupUsesTheLinkedAccountAndActorAndChecksRevocation(): void
    {
        $record = function (array $fields) {
            $entity = $this->createStub(CoreEntity::class);
            $entity->method('get')->willReturnCallback(fn ($field) => $fields[$field] ?? null);
            return $entity;
        };
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('crm-ai');
        $membership = $record(['chatwootAccountId' => 'acct', 'chatwootUserId' => 'actor', 'isAI' => true, 'tasksEnabled' => true]);
        $builder = $this->createStub(RDBSelectBuilder::class);
        $builder->method('where')->willReturnSelf();
        $builder->method('find')->willReturn(new EntityCollection([$membership]));
        $repo = $this->createStub(RDBRepository::class);
        $repo->method('leftJoin')->willReturn($builder);
        $em = $this->createStub(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repo);
        $em->method('getEntityById')->willReturnMap([
            ['ChatwootUser', 'actor', $record(['platformId' => 'p', 'assignedUserId' => 'crm-ai', 'chatwootUserId' => 28])],
            ['ChatwootAccount', 'acct', $record(['platformId' => 'p', 'chatwootAccountId' => 6])],
            ['ChatwootPlatform', 'p', $record(['backendUrl' => 'https://chat.test', 'accessToken' => 'test'])],
        ]);
        $api = $this->createMock(ChatwootApiClient::class);
        $call = 0;
        $api->expects(self::exactly(3))->method('getAccessibleConversationIds')
            ->with('https://chat.test', 'test', 6, 28, [123])
            ->willReturnCallback(function () use (&$call) {
                return match (++$call) { 1 => [123], 2 => [], default => throw new \RuntimeException('unavailable') };
            });
        $resolver = new InboxAccessResolver($em, $api);
        $conversation = $record(['chatwootAccountId' => 'acct', 'chatwootConversationId' => 123]);
        self::assertFalse($resolver->canReadTaskConversation($user, $record(['chatwootAccountId' => 'other', 'chatwootConversationId' => 123])));
        self::assertTrue($resolver->canReadTaskConversation($user, $conversation));
        self::assertFalse($resolver->canReadTaskConversation($user, $conversation));
        self::assertFalse($resolver->canReadTaskConversation($user, $conversation));
    }

    public function testChatGrantOpensOnlyTheAuthorizedRecordForReadWhileCrmRoleAllowsIt(): void
    {
        $user = $this->createStub(User::class);
        $mentioned = $this->createStub(Entity::class);
        $neighbor = $this->createStub(Entity::class);
        $scope = ScopeData::fromRaw((object) array_fill_keys(['read', 'edit', 'delete', 'stream'], 'team'));
        $role = $this->createStub(DefaultAccessChecker::class);
        $roleAllowsRead = true;
        $role->method('checkEntityRead')->willReturnCallback(function () use (&$roleAllowsRead) { return $roleAllowsRead; });
        $role->method('checkEntityEdit')->willReturn(true);
        $role->method('checkEntityDelete')->willReturn(true);
        $inboxes = $this->createStub(InboxAccessResolver::class);
        $inboxes->method('canAccessInboxId')->willReturn(false);
        $inboxes->method('canReadTaskConversation')->willReturnCallback(fn ($actor, $entity) => $actor === $user && $entity === $mentioned);
        $acl = new AccessChecker($role, $inboxes);

        self::assertTrue($acl->checkEntityRead($user, $mentioned, $scope));
        self::assertFalse($acl->checkEntityRead($user, $neighbor, $scope));
        self::assertFalse($acl->checkEntityEdit($user, $mentioned, $scope));
        self::assertFalse($acl->checkEntityDelete($user, $mentioned, $scope));
        self::assertFalse($acl->checkEntityStream($user, $mentioned, $scope));
        $roleAllowsRead = false;
        self::assertFalse($acl->checkEntityRead($user, $mentioned, $scope));
    }
}
