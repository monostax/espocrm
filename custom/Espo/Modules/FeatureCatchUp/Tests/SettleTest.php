<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Entity;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCatchUp\Controllers\CatchUpSettle;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Settle;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class SettleTest extends TestCase
{
    /** @var list<string> readers the work ran as */
    private array $readers = [];
    /** @var list<array{string, string, ?string, ?array}> snapshot "lane:id", locale, request id and notify of each Summary call */
    private array $analyses = [];

    private function entity(string $id, array $values = []): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        return $entity;
    }

    /**
     * @param list<string> $deals open deals linked to the conversation
     * @param list<string> $admins Chatwoot administrators who are people
     * @param list<string> $refusing readers who may not open the subject
     */
    private function settle(array $deals = [], ?string $owner = null, array $admins = ['boss'], array $refusing = [], bool $synced = true): Settle
    {
        $accounts = $this->createMock(RDBSelectBuilder::class);
        $accounts->method('limit')->willReturnSelf();
        $accounts->method('find')->willReturn(new EntityCollection([$this->entity('crm-account', ['tenantId' => 'tenant'])]));
        $conversations = $this->createMock(RDBSelectBuilder::class);
        $conversations->method('findOne')->willReturn($synced ? $this->entity('conv') : null);
        $repositories = [];
        foreach (['ChatwootAccount' => $accounts, 'ChatwootConversation' => $conversations] as $type => $builder) {
            $repositories[$type] = $this->createMock(RDBRepository::class);
            $repositories[$type]->method('where')->willReturn($builder);
        }
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($deals, $owner, $admins) {
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            [$column, $rows] = match (true) {
                str_contains($sql, "m.role = 'administrator'") => [null, $admins],
                str_contains($sql, 'FROM chatwoot_conversation_opportunity') => [null, $deals],
                default => [$owner ?? false, []],
            };
            $statement->method('fetchColumn')->willReturn($column);
            $statement->method('fetchAll')->willReturn($rows);
            return $statement;
        });
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(fn ($type) => $repositories[$type]);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $type === 'Tenant'
            ? $this->entity($id, ['language' => 'pt_BR', 'timeZone' => 'America/Manaus']) : null);
        $em->method('getPDO')->willReturn($pdo);

        $actors = $this->createMock(ActorScope::class);
        $actors->method('run')->willReturnCallback(function (string $reader, callable $work) use ($refusing) {
            $this->readers[] = $reader;
            if (in_array($reader, $refusing, true)) throw new Forbidden();
            return $work(function (string $class) {
                return match ($class) {
                    Feed::class => $this->snapshots('deals'),
                    ServiceFeed::class => $this->snapshots('service'),
                    Summary::class => $this->summary(),
                };
            });
        });
        return new Settle($em, $actors, $this->createMock(Log::class));
    }

    private function snapshots(string $lane): Feed|ServiceFeed
    {
        $feed = $this->createMock($lane === 'deals' ? Feed::class : ServiceFeed::class);
        $feed->method('snapshot')->willReturnCallback(fn (string $id) => ['id' => $id, 'lane' => $lane]);
        return $feed;
    }

    private function summary(): Summary
    {
        $summary = $this->createMock(Summary::class);
        $summary->method('analyse')->willReturnCallback(function (array $snapshot, string $locale, ?string $requestId, ?array $notify) {
            $this->analyses[] = ["{$snapshot['lane']}:{$snapshot['id']}", $locale, $requestId, $notify];
            return $requestId ? ['status' => 'completed', 'verdict' => 'customer_waiting'] : ['status' => 'pending', 'cached' => false];
        });
        return $summary;
    }

    public function testAConversationWithoutAnOpenDealIsAnalysedAsItsAssigneeWithACallback(): void
    {
        $result = $this->settle([], 'ana')->start(12, 2775);

        self::assertSame(['ana'], $this->readers);
        self::assertSame([['service:conv', 'pt-BR', null, ['lane' => 'service', 'id' => 'conv', 'accountId' => '12', 'timeZone' => 'America/Manaus']]],
            $this->analyses);
        self::assertSame(['subjects' => [['lane' => 'service', 'id' => 'conv', 'status' => 'pending', 'cached' => false, 'reader' => 'ana']]], $result);
    }

    public function testItsOpenDealsAreAnalysedInsteadByAnAdministratorWhoMayOpenThem(): void
    {
        $result = $this->settle(['d2', 'd1'], null, ['first-admin', 'second-admin'], ['first-admin'])->start(12, 2775);

        self::assertSame(['first-admin', 'second-admin', 'first-admin', 'second-admin'], $this->readers,
            'An AI-owned deal has no person; an administrator who may not open it is skipped.');
        self::assertSame(['deals:d2', 'deals:d1'], array_column($this->analyses, 0));
        self::assertSame(['second-admin', 'second-admin'], array_column($result['subjects'], 'reader'));
    }

    public function testNoReaderMeansNothingIsQueued(): void
    {
        $result = $this->settle([], null, ['boss'], ['boss'])->start(12, 2775);

        self::assertSame([], $this->analyses);
        self::assertSame('unreadable', $result['subjects'][0]['status']);
    }

    public function testAConversationTheCrmHasNotCopiedYetIsLeftToTheDeck(): void
    {
        $result = $this->settle([], 'ana', ['boss'], [], false)->start(12, 2775);

        self::assertSame(['subjects' => [], 'skipped' => 'conversation'], $result);
        self::assertSame([], $this->readers);
    }

    public function testCompletionStoresTheVerdictAsTheSameReader(): void
    {
        $requestId = str_repeat('c', 64);
        $result = $this->settle()->complete('service', 'conv', 'ana', 12, 'America/Manaus', 'pt-BR', $requestId);

        self::assertSame(['ana'], $this->readers);
        self::assertSame([['service:conv', 'pt-BR', $requestId, null]], $this->analyses);
        self::assertSame(['status' => 'completed', 'verdict' => 'customer_waiting'], $result);
    }

    public function testCompletionForAReaderWhoLostAccessStoresNothing(): void
    {
        $result = $this->settle([], null, [], ['ana'])->complete('deals', 'd1', 'ana', 12, 'UTC', 'en', str_repeat('c', 64));

        self::assertSame(['status' => 'unreadable'], $result);
        self::assertSame([], $this->analyses);
    }

    public function testCompletionRejectsWhatTheBackendNeverSends(): void
    {
        $settle = $this->settle();
        foreach ([['other', 'conv', 'pt-BR', 'UTC', str_repeat('c', 64)], ['service', 'conv', 'fr', 'UTC', str_repeat('c', 64)],
            ['service', 'conv', 'pt-BR', 'Mars/Olympus', str_repeat('c', 64)], ['service', 'conv', 'pt-BR', 'UTC', 'short']] as [$lane, $id, $locale, $zone, $request]) {
            try {
                $settle->complete($lane, $id, 'ana', 12, $zone, $locale, $request);
                self::fail('Expected a bad request.');
            } catch (BadRequest) {
                self::assertSame([], $this->readers);
            }
        }
    }

    private function signed(string $body, string $prefix, int $time): Request
    {
        $signature = 'sha256=' . hash_hmac('sha256', "$prefix$time.$body", 'test-secret');
        $request = $this->createMock(Request::class);
        $request->method('getBodyContents')->willReturn($body);
        $request->method('getHeader')->willReturnCallback(fn ($name) => match ($name) {
            'X-Catch-Up-Timestamp' => (string) $time,
            'X-Catch-Up-Signature' => $signature,
            default => null,
        });
        return $request;
    }

    public function testOnlyTheBackendsSettleSignatureIsAccepted(): void
    {
        putenv('CATCH_UP_BACKEND_SECRET=test-secret');
        $settle = $this->createMock(Settle::class);
        $settle->expects(self::once())->method('start')->with(12, 2775)->willReturn(['subjects' => []]);
        $controller = new CatchUpSettle($settle);
        $body = '{"accountId":12,"conversationId":2775}';

        $controller->postActionStart($this->signed($body, 'catch-up-settle-v1.', time()));

        foreach ([$this->signed($body, '', time()), $this->signed($body, 'catch-up-settle-v1.', time() - 600)] as $request) {
            try {
                $controller->postActionStart($request);
                self::fail('A CRM-to-backend signature or an old one must not pass.');
            } catch (Forbidden) {
                self::assertTrue(true);
            }
        }
        putenv('CATCH_UP_BACKEND_SECRET');
    }
}
