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
use Espo\Modules\FeatureCatchUp\Services\ChatwootAccess;
use Espo\Modules\FeatureCatchUp\Services\DealStates;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Ownership;
use Espo\Modules\FeatureCatchUp\Services\Parties;
use Espo\Modules\FeatureCatchUp\Services\Records;
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
    private function feed(bool $regular = true, bool $readable = true, string $dealTenant = 'tenant', ?\PDO $pdo = null, bool $opens = true): array
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
            $this->createMock(Transcript::class), $this->createMock(Records::class), $this->chatwoot($opens));
        return [$feed, $cache, $verdicts, $deal];
    }

    /** Chatwoot's answer for every conversation: all open (the default) or none. */
    private function chatwoot(bool $opens = true): ChatwootAccess
    {
        $access = $this->createMock(ChatwootAccess::class);
        $access->method('filter')->willReturnCallback(fn (array $conversations) => $opens ? $conversations : []);
        $access->method('allows')->willReturn($opens);
        $access->method('dealsWithOpenConversation')->willReturnCallback(fn (array $ids) => $opens ? array_fill_keys($ids, true) : []);
        $access->method('openConversations')->willReturnCallback(fn (array $ids) => $opens ? array_fill_keys($ids, true) : []);
        return $access;
    }


    private function request(): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) []);
        return $request;
    }

    public function testEarlierDealsOfTheConversationAreNamedOnlyWhenTheReaderMayReadThem(): void
    {
        $deals = [
            'won' => ['name' => 'Ana - Hemograma', 'status' => 'Won', 'amount' => 350, 'amountCurrency' => 'BRL', 'createdAt' => '2026-10-02 13:00:00', 'closeDate' => '2026-10-05'],
            'hidden' => ['name' => 'Segredo', 'status' => 'Lost', 'createdAt' => '2026-09-20 13:00:00'],
            'lost' => ['name' => 'Ana - Ultrassom', 'status' => 'Lost', 'amount' => null, 'createdAt' => '2026-09-01 02:00:00', 'closeDate' => '2026-09-03'],
            'open' => ['name' => 'Ana - Retorno', 'status' => 'Proposal', 'createdAt' => '2026-08-01 13:00:00'],
            'old' => ['name' => 'Ana - 2025', 'status' => 'Won', 'createdAt' => '2025-08-01 13:00:00'],
        ];
        $entity = function (string $id, array $values) {
            $deal = $this->createMock(Entity::class);
            $deal->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
            $deal->method('getId')->willReturn($id);
            return $deal;
        };
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntityRead')->willReturnCallback(fn (Entity $deal) => $deal->getId() !== 'hidden');
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(['conv', 'other', 'deal', 'tenant', '2026-11-01 12:00:00'])->willReturn(true);
        $statement->method('fetchAll')->willReturn(array_keys($deals));
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $entity($id, $deals[$id]));
        $feed = new Feed($em, $this->createMock(SelectBuilderFactory::class), $acl, $this->createMock(User::class), $this->createMock(Workspace::class),
            $this->createMock(Rollout::class), $this->createMock(Ownership::class), $this->createMock(DealStates::class), $this->createMock(Verdicts::class),
            $this->createMock(DataCache::class), $this->createMock(Metadata::class), $this->createMock(Transcript::class), $this->createMock(Records::class),
            $this->chatwoot());
        $now = new DateTimeImmutable('2026-11-10 10:00:00', new DateTimeZone('America/Sao_Paulo'));
        $deal = $entity('deal', ['tenantId' => 'tenant', 'createdAt' => '2026-11-01 12:00:00']);

        self::assertSame('Ana - Hemograma (Won BRL 350, opened 2026-10-02, closed 2026-10-05); '
            . 'Ana - Ultrassom (Lost, opened 2026-08-31, closed 2026-09-03); Ana - Retorno (Proposal, opened 2026-08-01)',
            $feed->earlierDeals($deal, ['conv', 'other'], $now), 'the reader\'s dates, at most three, and never a deal they may not read');
        self::assertNull($feed->earlierDeals($deal, [], $now), 'no readable conversation, nothing to name');
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
        self::assertStringContainsString('s.last_message_at >= UTC_TIMESTAMP() - INTERVAL 45 DAY', $queries[0],
            'a deal quiet for over 45 days has had its last check and leaves the deck');
        $recent = 's.last_customer_at > s.last_business_at) AND s.last_customer_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY';
        self::assertStringContainsString("WHEN s.last_message_at IS NULL THEN 6 WHEN s.last_customer_at IS NOT NULL AND (s.last_business_at IS NULL OR $recent THEN 0 ",
            $queries[0], 'customers waiting up to 7 days come first');
        self::assertStringContainsString("$recent THEN s.last_customer_at END ASC, ", $queries[0], 'longest recent wait first');
        self::assertStringContainsString('s.last_customer_at > s.last_business_at) THEN s.last_customer_at END DESC, o.amount DESC', $queries[0],
            'older waits next, most recent first');
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
        self::assertNotSame($quiet('2026-10-01 10:00:00', '2026-10-30 11:00:00'), $quiet('2026-10-01 10:00:00', '2026-10-31 11:00:00'),
            'day 30 of silence is the last check');
        self::assertSame($quiet('2026-09-01 10:00:00', '2026-10-01 11:00:00'), $quiet('2026-09-01 10:00:00', '2026-11-01 11:00:00'),
            'after 30 days silence no longer brings a deal back');
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

    public function testDealsOwnedByTheAiOrNobodyGoToTheWholeTeam(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('manuella');
        $owners = $this->getMockBuilder(Ownership::class)->setConstructorArgs([$this->createMock(EntityManager::class), $user])
            ->onlyMethods(['seesAll'])->getMock();
        $owners->method('seesAll')->willReturn(false);
        [$sql, $params] = $owners->clause();
        self::assertTrue(Ownership::WHOLE_TEAM, 'decided 2026-11-10; set false to go back to the last staff writer');
        self::assertStringContainsString('(o.assigned_user_id = :deckOwnerA AND o.assigned_user_id NOT IN (', $sql, 'a person\'s deal stays theirs');
        self::assertStringContainsString(' OR (o.assigned_user_id IS NULL OR o.assigned_user_id IN (', $sql, 'the AI\'s and nobody\'s deals reach everyone');
        self::assertStringNotContainsString('s.staff_user_id', $sql, 'not only the last staff writer');
        self::assertSame(['deckOwnerA' => 'manuella'], $params);

        [$sql, $params] = $owners->conversationClause();
        self::assertStringContainsString("COALESCE((SELECT cu.assigned_user_id FROM chatwoot_user cu WHERE cu.chatwoot_user_id = c.assignee_id", $sql);
        self::assertStringContainsString(", '') IN ('', :deckOwner)", $sql, 'a conversation with no person assigned reaches everyone; a person keeps theirs');
        self::assertStringNotContainsString('catch_up_conversation_state', $sql, 'not only the last staff writer');
        self::assertSame(['deckOwner' => 'manuella'], $params);
    }

    public function testTheBadgeCountsOnlyCardsWhoseConversationChatwootOpensForTheReader(): void
    {
        foreach ([true => 1, false => 0] as $opens => $expected) {
            [$feed, , $verdicts] = $this->feed(opens: (bool) $opens);
            $verdicts->method('cardDeals')->willReturn(['deal']);
            self::assertSame(['count' => $expected], $feed->count($this->request()), 'not even a tenant admin counts what Chatwoot closes');
        }
    }

    public function testStoredVerdictIsReusedWithoutAnotherAnalysis(): void
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn(['verdict' => 'none', 'reason' => 'Automated code', 'card' => null]);
        $verdicts->expects(self::never())->method('store');
        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([$this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $this->createMock(Records::class)])
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
            $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([$this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $this->createMock(Records::class)])
                ->onlyMethods(['request'])->getMock();
            $summary->method('request')->willReturn(['status' => 'completed', 'requestId' => str_repeat('a', 64), 'card' => $card]);
            $result = $summary->analyse($this->snapshot(), 'pt-BR');
            self::assertSame($expected, $result['verdict']);
            self::assertSame(['tenant', 'Opportunity', 'deal', 'rev', 'pt-BR', 'scope', $expected], array_slice($stored, 0, 7));
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
        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([$this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $this->createMock(Records::class)])
            ->onlyMethods(['request'])->getMock();
        $summary->method('request')->willReturn(['status' => 'limited', 'requestId' => str_repeat('b', 64)]);
        $result = $summary->analyse($this->snapshot(), 'en');
        self::assertSame(['limited', false, null], [$result['status'], $result['cached'], $result['verdict']]);
    }

    public function testClassesFromTheAnalysisGoOnTheContactsAndTheVerdictUnderTheScopeTheNextReadComputes(): void
    {
        $evidence = [
            ['id' => '1', 'sentBy' => 'customer', 'participantId' => '20289'],
            ['id' => '2', 'sentBy' => 'customer', 'participantId' => '20094'],
        ];
        $participants = [['id' => '20094', 'classify' => true, 'account' => 'crm-account', 'accountId' => 12, 'name' => 'Drª Michele', 'group' => false, 'label' => null]];
        $answers = [['id' => '20094', 'class' => 'team'], ['id' => '20289', 'class' => 'customer']];
        $parties = $this->createMock(Parties::class);
        $parties->expects(self::once())->method('store')->with($participants, $answers)->willReturn(['20094' => 'team']);
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn(null);
        $stored = null;
        $verdicts->expects(self::once())->method('store')->willReturnCallback(function (...$args) use (&$stored): void { $stored = $args; });
        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([$this->createMock(User::class), $verdicts, $parties, $this->createMock(Records::class)])
            ->onlyMethods(['request'])->getMock();
        $summary->method('request')->willReturn(['status' => 'completed', 'card' => [
            'verdict' => 'none', 'reason' => 'A doutora respondeu', 'participants' => $answers]]);
        $summary->analyse([...$this->snapshot(), 'evidence' => $evidence, 'participants' => $participants], 'pt-BR');
        $next = Feed::evidenceScope([$evidence[0], ['sentBy' => 'staff'] + $evidence[1]]);
        self::assertSame($next, $stored[5], 'the next read labels the doctor as staff and finds this verdict');
        self::assertNotSame(Feed::evidenceScope($evidence), $stored[5]);
    }

    public function testAConversationKeepsOnlyServiceVerdictsAndDropsNonCustomers(): void
    {
        foreach ([
            [['verdict' => 'new_deal', 'reason' => 'Quer agendar', 'headline' => 'H', 'proposedDeal' => ['name' => 'Ana', 'amount' => null]], null, [], 'new_deal'],
            [['verdict' => 'decided', 'reason' => 'Pagou'], null, [], 'none'],
            [['verdict' => 'customer_waiting', 'reason' => 'Perguntou'], 'personal', [], 'none'],
            [['verdict' => 'customer_waiting', 'reason' => 'Perguntou', 'participants' => [['id' => '7', 'class' => 'automated']]], null, ['7' => 'automated'], 'none'],
            [['verdict' => 'customer_waiting', 'reason' => 'Perguntou'], 'customer', [], 'customer_waiting'],
        ] as [$card, $label, $classes, $expected]) {
            $parties = $this->createMock(Parties::class);
            $parties->method('store')->willReturn($classes);
            $verdicts = $this->createMock(Verdicts::class);
            $verdicts->method('find')->willReturn(null);
            $stored = null;
            $verdicts->method('store')->willReturnCallback(function (...$args) use (&$stored): void { $stored = $args; });
            $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([$this->createMock(User::class), $verdicts, $parties, $this->createMock(Records::class)])
                ->onlyMethods(['request'])->getMock();
            $summary->method('request')->willReturn(['status' => 'completed', 'card' => $card]);
            $snapshot = [...$this->snapshot(), 'type' => 'conversation',
                'participants' => [['id' => '7', 'own' => true, 'label' => $label, 'classify' => $label === null]]];
            $result = $summary->analyse($snapshot, 'pt-BR');
            self::assertSame($expected, $result['verdict'], json_encode($card));
            self::assertSame(['ChatwootConversation', 'deal'], [$stored[1], $stored[2]], 'stored as a conversation verdict');
        }
    }

    private function snapshot(): array
    {
        return ['id' => 'deal', 'type' => 'opportunity', 'tenantId' => 'tenant', 'revision' => 'rev', 'evidenceScope' => 'scope',
            'evidence' => [], 'facts' => [], 'catchUpPolicy' => ['instance' => 'https://chat.test']];
    }
}
