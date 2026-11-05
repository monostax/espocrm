<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\DealStates;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Ownership;
use Espo\Modules\FeatureCatchUp\Services\Revision;
use Espo\Modules\FeatureCatchUp\Services\Rollout;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\Transcript;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class FeedTest extends TestCase
{
    private function deal(array $values = []): Entity
    {
        $values += ['tenantId' => 'tenant', 'opportunityStageId' => 'stage', 'amount' => 4800, 'assignedUserId' => 'ai',
            'followupStatus' => null, 'nextActionType' => null, 'nextActionId' => null];
        $deal = $this->createMock(Entity::class);
        $deal->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        $deal->method('getId')->willReturn('deal');
        return $deal;
    }

    private function state(array $values = []): array
    {
        return $values + ['last_message_at' => '2026-11-01 10:00:00', 'message_count' => 12, 'staff_user_id' => 'erivania'];
    }

    private function pdo(array $rows = []): \PDO
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn($rows);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('query')->willReturn($statement);
        return $pdo;
    }

    /** @return array{Feed, DataCache, Verdicts, Entity} */
    private function feed(bool $regular = true, bool $readable = true, string $dealTenant = 'tenant', ?\PDO $pdo = null): array
    {
        $user = $this->createMock(User::class);
        $user->method('isRegular')->willReturn($regular);
        $user->method('getId')->willReturn('erivania');
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkField')->willReturn(true);
        $acl->method('checkEntityRead')->willReturn($readable);
        $deal = $this->deal(['tenantId' => $dealTenant]);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($deal);
        $em->method('getPDO')->willReturn($pdo ?? $this->pdo());
        $tenant = $this->createMock(Entity::class);
        $tenant->method('getId')->willReturn('tenant');
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('resolve')->willReturn(['tenant' => $tenant, 'policy' => ['enabled' => true]]);
        $states = $this->createMock(DealStates::class);
        $states->method('load')->willReturn(['deal' => $this->state()]);
        $cache = $this->createMock(DataCache::class);
        $verdicts = $this->createMock(Verdicts::class);
        $owners = $this->createMock(Ownership::class);
        $owners->method('clause')->willReturn(['', []]);
        $feed = new Feed($em, $this->createMock(SelectBuilderFactory::class), $acl, $user, $workspace,
            $this->createMock(Rollout::class), $owners, $states, $verdicts, $cache, $this->createMock(Metadata::class),
            $this->createMock(Transcript::class));
        return [$feed, $cache, $verdicts, $deal];
    }

    private function request(): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) []);
        return $request;
    }

    public function testPortalUsersGetNoDeck(): void
    {
        [$feed] = $this->feed(false);
        $this->expectException(Forbidden::class);
        $feed->list($this->request());
    }

    public function testAnotherTenantsOrUnreadableDealIsForbidden(): void
    {
        foreach ([[true, 'other-tenant'], [false, 'tenant']] as [$readable, $tenant]) {
            [$feed] = $this->feed(true, $readable, $tenant);
            $refused = false;
            try {
                $feed->deal('deal', 'tenant');
            } catch (Forbidden) {
                $refused = true;
            }
            self::assertTrue($refused, "Returned a deal the reader may not see (readable: $readable, tenant: $tenant)");
        }
    }

    public function testDeckWaitsUntilTheConversationSettles(): void
    {
        $queries = [];
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$queries, $statement) {
            $queries[] = $sql;
            return $statement;
        });
        [$feed] = $this->feed(pdo: $pdo);
        self::assertSame([], $feed->list($this->request())['items']);
        self::assertStringContainsString('s.last_message_at <= UTC_TIMESTAMP() - INTERVAL 30 MINUTE', $queries[0],
            'a deal whose conversation had a message in the last 30 minutes is not a candidate yet');
    }

    public function testReviewWithoutTheShownCardConflictsAndResolvesNothing(): void
    {
        [$feed, $cache, $verdicts] = $this->feed();
        $cache->method('tryGet')->willReturn(null);
        $verdicts->expects(self::never())->method('resolve');
        $this->expectException(Conflict::class);
        $feed->review('deal', 'token', $this->request());
    }

    public function testReviewOfADealThatMovedSinceTheCardConflicts(): void
    {
        [$feed, $cache, $verdicts] = $this->feed();
        $cache->method('tryGet')->willReturn(['token' => 'token', 'revision' => 'older', 'expires' => time() + 60]);
        $verdicts->expects(self::never())->method('resolve');
        $this->expectException(Conflict::class);
        $feed->review('deal', 'token', $this->request());
    }

    public function testRevisionMovesWithTheDealAndWithSilenceInSteps(): void
    {
        $now = new DateTimeImmutable('2026-11-04 12:00:00', new DateTimeZone('UTC'));
        $base = Revision::of($this->deal(), $this->state(), null, null, $now);
        self::assertSame($base, Revision::of($this->deal(), $this->state(), null, null, $now), 'same state, same revision');
        self::assertNotSame($base, Revision::of($this->deal(), $this->state(['message_count' => 13]), null, null, $now), 'a new message');
        self::assertNotSame($base, Revision::of($this->deal(['opportunityStageId' => 'quote']), $this->state(), null, null, $now), 'a stage change');
        self::assertNotSame($base, Revision::of($this->deal(), $this->state(), null, '2026-11-04 09:00:00', $now), 'a note on the deal');
        self::assertNotSame($base, Revision::of($this->deal(), $this->state(['staff_user_id' => 'ana']), null, null, $now), 'a new deck owner');
        $task = ['id' => 'task', 'status' => 'Not Started', 'dateEndDate' => '2026-11-05'];
        self::assertNotSame(Revision::of($this->deal(), $this->state(), $task, null, $now),
            Revision::of($this->deal(), $this->state(), [...$task, 'status' => 'Completed'], null, $now), 'the next step was done');

        $quiet = fn (string $last, string $at) => Revision::of($this->deal(), $this->state(['last_message_at' => $last]), null, null,
            new DateTimeImmutable($at, new DateTimeZone('UTC')));
        self::assertSame($quiet('2026-10-20 10:00:00', '2026-10-28 11:00:00'), $quiet('2026-10-20 10:00:00', '2026-11-02 11:00:00'),
            'day 8 and day 13 of silence are the same step');
        self::assertNotSame($quiet('2026-10-20 10:00:00', '2026-10-21 09:00:00'), $quiet('2026-10-20 10:00:00', '2026-10-21 11:00:00'),
            'the first full day of silence is a new step');
        self::assertSame(1, Revision::quietDays('2026-11-03 11:00:00', $now));
        self::assertNull(Revision::quietDays(null, $now));
    }

    public function testDeckOwnerIsThePersonOnTheDealOrTheLastStaffWriter(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo(['ai']));
        $owners = new Ownership($em, $this->createMock(User::class));
        self::assertSame('manuella', $owners->deckOwnerId('manuella', 'erivania'), 'a deal a person owns stays theirs');
        self::assertSame('erivania', $owners->deckOwnerId('ai', 'erivania'), 'an AI-owned deal goes to the last staff writer');
        self::assertSame('erivania', $owners->deckOwnerId(null, 'erivania'));
        self::assertNull($owners->deckOwnerId('ai', null), 'nobody wrote: admins only');
    }

    public function testStoredVerdictIsReusedWithoutAnotherAnalysis(): void
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn(['verdict' => 'none', 'reason' => 'Automated code', 'card' => null]);
        $verdicts->expects(self::never())->method('store');
        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([$this->createMock(User::class), $verdicts])
            ->onlyMethods(['request'])->getMock();
        $summary->expects(self::never())->method('request');
        $result = $summary->analyse($this->snapshot(), 'pt-BR');
        self::assertSame(['completed', true, 'none'], [$result['status'], $result['cached'], $result['verdict']]);
    }

    public function testCompletedAnalysisIsStoredAndUnreadableAnswersCountAsNone(): void
    {
        foreach ([
            [['verdict' => 'customer_waiting', 'reason' => 'Asked for the price', 'headline' => 'H', 'draft' => 'Oi!'], 'customer_waiting'],
            [['headline' => 'No verdict'], 'none'],
            [null, 'none'],
        ] as [$card, $expected]) {
            $verdicts = $this->createMock(Verdicts::class);
            $verdicts->method('find')->willReturn(null);
            $stored = null;
            $verdicts->expects(self::once())->method('store')->willReturnCallback(function (...$args) use (&$stored): void {
                $stored = $args;
            });
            $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([$this->createMock(User::class), $verdicts])
                ->onlyMethods(['request'])->getMock();
            $summary->method('request')->willReturn(['status' => 'completed', 'requestId' => str_repeat('a', 64), 'card' => $card]);
            $result = $summary->analyse($this->snapshot(), 'pt-BR');
            self::assertSame($expected, $result['verdict']);
            self::assertSame(['tenant', 'deal', 'rev', 'pt-BR', 'scope', $expected], array_slice($stored, 0, 6));
            if ($expected === 'none') {
                self::assertNull($result['card']);
            } else {
                self::assertSame(['headline' => 'H', 'draft' => 'Oi!'], $result['card'], 'the card carries copy, not the verdict');
            }
        }
    }

    public function testPendingAnalysisStoresNothing(): void
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn(null);
        $verdicts->expects(self::never())->method('store');
        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([$this->createMock(User::class), $verdicts])
            ->onlyMethods(['request'])->getMock();
        $summary->method('request')->willReturn(['status' => 'limited', 'requestId' => str_repeat('b', 64)]);
        $result = $summary->analyse($this->snapshot(), 'en');
        self::assertSame(['limited', false, null], [$result['status'], $result['cached'], $result['verdict']]);
    }

    private function snapshot(): array
    {
        return ['id' => 'deal', 'type' => 'opportunity', 'tenantId' => 'tenant', 'revision' => 'rev', 'evidenceScope' => 'scope',
            'evidence' => [], 'facts' => [], 'catchUpPolicy' => ['instance' => 'https://chat.test']];
    }
}
