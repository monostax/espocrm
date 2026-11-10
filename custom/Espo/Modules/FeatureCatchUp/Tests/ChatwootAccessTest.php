<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Acl;
use Espo\Core\ORM\Entity;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCatchUp\Services\ChatwootAccess;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class ChatwootAccessTest extends TestCase
{
    private function entity(string $id, array $values = []): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        return $entity;
    }

    private function conversation(string $id, int $display, string $account = 'acct'): Entity
    {
        return $this->entity($id, ['chatwootAccountId' => $account, 'chatwootConversationId' => $display]);
    }

    /** @param ?int $chatwootUser the reader's Chatwoot user id, or null when they have none on the platform */
    private function access(ChatwootApiClient $api, ?int $chatwootUser = 58): ChatwootAccess
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('ed');
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($chatwootUser === null ? false : (string) $chatwootUser);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => match ($type) {
            'ChatwootAccount' => $this->entity($id, ['platformId' => 'platform', 'chatwootAccountId' => 9]),
            'ChatwootPlatform' => $this->entity($id, ['backendUrl' => 'http://chatwoot', 'accessToken' => 'platform-token']),
            default => null,
        });
        return new ChatwootAccess($em, $this->createMock(Acl::class), $user, $api, $this->createMock(Log::class));
    }

    public function testChatwootDecidesWhichConversationsTheReaderMayOpenAndIsAskedOnce(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::once())->method('getAccessibleConversationIds')
            ->with('http://chatwoot', 'platform-token', 9, 58, [2775, 2776, 2777])->willReturn([2775, 2777]);
        $access = $this->access($api);
        $conversations = ['a' => $this->conversation('a', 2775), 'b' => $this->conversation('b', 2776), 'c' => $this->conversation('c', 2777)];

        self::assertSame(['a', 'c'], array_keys($access->filter($conversations)), 'an inbox the admin does not belong to stays closed');
        self::assertFalse($access->allows($conversations['b']), 'the answer is kept for the request');
        self::assertTrue($access->allows($conversations['a']));
    }

    public function testNothingIsAllowedWhenChatwootCantAnswerOrTheReaderHasNoChatwootUser(): void
    {
        $failing = $this->createMock(ChatwootApiClient::class);
        $failing->method('getAccessibleConversationIds')->willThrowException(new \RuntimeException('HTTP 503'));
        self::assertSame([], $this->access($failing)->filter(['a' => $this->conversation('a', 2775)]), 'it fails closed');

        $unasked = $this->createMock(ChatwootApiClient::class);
        $unasked->expects(self::never())->method('getAccessibleConversationIds');
        self::assertSame([], $this->access($unasked, null)->filter(['a' => $this->conversation('a', 2775)]),
            'a CRM user with no Chatwoot user, an instance admin included, opens nothing there');
    }

    public function testADealCountsAsOpenWhenOneOfItsConversationsIsOpenInTheCrmAndInChatwoot(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('ed');
        $links = $this->createMock(\PDOStatement::class);
        $links->method('execute')->willReturn(true);
        $links->method('fetchAll')->willReturn([['deal-1', 'a'], ['deal-1', 'b'], ['deal-2', 'c'], ['deal-3', 'd']]);
        $chatwootUser = $this->createMock(\PDOStatement::class);
        $chatwootUser->method('execute')->willReturn(true);
        $chatwootUser->method('fetchColumn')->willReturn('58');
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(fn (string $sql) => str_contains($sql, 'chatwoot_conversation_opportunity') ? $links : $chatwootUser);
        $conversations = ['a' => $this->conversation('a', 1), 'b' => $this->conversation('b', 2), 'c' => $this->conversation('c', 3),
            'd' => $this->conversation('d', 4)];
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('find')->willReturn(new EntityCollection(array_values($conversations)));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($builder);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $em->method('getRDBRepository')->willReturn($repository);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => match ($type) {
            'ChatwootAccount' => $this->entity($id, ['platformId' => 'platform', 'chatwootAccountId' => 9]),
            'ChatwootPlatform' => $this->entity($id, ['backendUrl' => 'http://chatwoot', 'accessToken' => 'platform-token']),
            default => null,
        });
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntityRead')->willReturnCallback(fn ($conversation) => $conversation->getId() !== 'd');
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::once())->method('getAccessibleConversationIds')->with('http://chatwoot', 'platform-token', 9, 58, [1, 2, 3])
            ->willReturn([2]);
        $access = new ChatwootAccess($em, $acl, $user, $api, $this->createMock(Log::class));

        self::assertSame(['deal-1' => true], $access->dealsWithOpenConversation(['deal-1', 'deal-2', 'deal-3']),
            'deal-2: Chatwoot closes its only conversation; deal-3: the CRM ACL does, so Chatwoot is not even asked about it');
    }

    public function testEachAccountIsAskedSeparately(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::exactly(2))->method('getAccessibleConversationIds')
            ->willReturnCallback(fn ($url, $token, $account, $user, array $ids) => $ids === [10] ? [10] : []);
        $access = $this->access($api);
        $open = $access->filter(['x' => $this->conversation('x', 10, 'one'), 'y' => $this->conversation('y', 11, 'two')]);
        self::assertSame(['x'], array_keys($open));
    }
}
