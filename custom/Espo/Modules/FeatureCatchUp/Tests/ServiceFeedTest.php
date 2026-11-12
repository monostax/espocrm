<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\DataCache;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\ChatwootAccess;
use Espo\Modules\FeatureCatchUp\Services\Ownership;
use Espo\Modules\FeatureCatchUp\Services\Parties;
use Espo\Modules\FeatureCatchUp\Services\Records;
use Espo\Modules\FeatureCatchUp\Services\Revision;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Transcript;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class ServiceFeedTest extends TestCase
{
    private function entity(array $values, string $id = 'id'): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        $entity->method('getId')->willReturn($id);
        return $entity;
    }

    /** @return array{ServiceFeed, Verdicts, Parties, DataCache} */
    private function feed(array $options = []): array
    {
        $options += ['readable' => true, 'conversationAccount' => 'crm-account', 'pdo' => null, 'seesAll' => false, 'opens' => true, 'records' => null];
        $user = $this->createMock(User::class);
        $user->method('isRegular')->willReturn(true);
        $user->method('getId')->willReturn('erivania');
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkEntityRead')->willReturn($options['readable']);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $type === 'ChatwootConversation'
            ? $this->entity(['chatwootAccountId' => $options['conversationAccount']], $id) : null);
        if ($options['pdo']) $em->method('getPDO')->willReturn($options['pdo']);
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('resolve')->willReturn([
            'tenant' => $this->entity([], 'tenant'), 'policy' => ['enabled' => true],
            'account' => $this->entity(['chatwootAccountId' => 12], 'crm-account'),
        ]);
        $owners = $this->createMock(Ownership::class);
        $owners->method('conversationClause')->willReturn($options['seesAll'] ? ['', []] : [' AND OWNER = :deckOwner', ['deckOwner' => 'erivania']]);
        $verdicts = $this->createMock(Verdicts::class);
        $parties = $this->createMock(Parties::class);
        $cache = $this->createMock(DataCache::class);
        $feed = new ServiceFeed($em, $this->createMock(SelectBuilderFactory::class), $acl, $user, $workspace, $owners, $verdicts,
            $this->createMock(Transcript::class), $parties, $cache, $options['records'] ?? $this->createMock(Records::class), $this->chatwoot($options['opens']));
        return [$feed, $verdicts, $parties, $cache];
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

    public function testAConversationOfAnotherAccountOrOneTheReaderCannotOpenIsForbidden(): void
    {
        foreach ([['conversationAccount' => 'other-account'], ['readable' => false]] as $options) {
            [$feed, $verdicts] = $this->feed($options);
            $verdicts->expects(self::never())->method('resolve');
            $refused = false;
            try {
                $feed->resolved('conv', $this->request());
            } catch (Forbidden) {
                $refused = true;
            }
            self::assertTrue($refused, json_encode($options));
        }
    }

    /** A follow_up_due card on a loop whose time passed, as Joe Neris's was on 2026-11-10. */
    private const SHOWN = ['tenantId' => 'tenant', 'locale' => 'pt-BR', 'evidenceScope' => 'scope',
        'card' => ['headline' => 'Joe ficou de checar o painel', 'promise' => ['side' => 'theirs', 'text' => 'Verificar o painel', 'dueAt' => '2026-11-05T18:00:00-03:00']]];

    /** @param list<array<string, mixed>> $left the record's loops after the card's is kept */
    private function resolvedWith(array $shown, array $left): array
    {
        $records = $this->createMock(Records::class);
        $records->method('load')->willReturn(['agreed' => [], 'promises' => $left, 'coveredAt' => null]);
        [$feed, $verdicts] = $this->feed(['records' => $records]);
        $verdicts->method('cards')->willReturn($shown);
        $verdicts->expects(self::once())->method('resolve')->with('ChatwootConversation', 'conv');
        return [$feed, $verdicts, $records];
    }

    public function testJaResolviKeepsTheCardsLoopAndTheClockBringsTheNextLoopBack(): void
    {
        $due = time() + 86400;
        [$feed, $verdicts, $records] = $this->resolvedWith([self::SHOWN], [['id' => 'p2', 'side' => 'theirs', 'text' => 'Mandar o RG',
            'dueAt' => gmdate(DATE_ATOM, $due), 'status' => 'open']]);
        $records->expects(self::once())->method('keep')->with('ChatwootConversation', 'conv', self::SHOWN['card']['promise'], 'user:erivania')
            ->willReturn(true);
        // Nothing else is due now: the verdict at this revision becomes `scheduled`, and comes back when the RG is due.
        $revision = Revision::ofConversation($this->entity(['chatwootAccountId' => 'crm-account'], 'conv'));
        $verdicts->expects(self::once())->method('settleAs')->with('ChatwootConversation', 'conv', $revision, 'scheduled',
            self::callback(fn ($loop) => $loop['id'] === 'p2'), self::callback(fn ($basis) => count($basis['loops']) === 1), $due, self::anything());
        $verdicts->expects(self::never())->method('store');

        $feed->resolved('conv', $this->request());
    }

    public function testJaResolviLeavesAnotherDueLoopToTheNextAnalysis(): void
    {
        [$feed, $verdicts, $records] = $this->resolvedWith([self::SHOWN], [['id' => 'p2', 'side' => 'ours', 'text' => 'Responder o preço', 'status' => 'open']]);
        $records->method('keep')->willReturn(true);
        $verdicts->expects(self::never())->method('settleAs');

        $feed->resolved('conv', $this->request());
    }

    public function testJaResolviOnACardWithoutALoopOnlyResolvesIt(): void
    {
        [$feed, $verdicts, $records] = $this->resolvedWith([[...self::SHOWN, 'card' => ['headline' => 'Novo negócio']]], []);
        $records->expects(self::never())->method('keep');
        $verdicts->expects(self::never())->method('settleAs');

        $feed->resolved('conv', $this->request());
    }

    public function testCandidatesStayInTheAccountAndLeaveOutDealsNonCustomersAndChatsStillMoving(): void
    {
        $queries = [];
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([]);
        $bound = [];
        $statement->method('bindValue')->willReturnCallback(function ($name, $value) use (&$bound) { $bound[$name] = $value; return true; });
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$queries, $statement) {
            $queries[] = $sql;
            return $statement;
        });
        [$feed] = $this->feed(['pdo' => $pdo]);
        $method = new \ReflectionMethod(ServiceFeed::class, 'candidates');
        self::assertSame([], $method->invoke($feed, 'crm-account', 0, 20));
        $sql = $queries[0];
        foreach ([
            'c.chatwoot_account_id = :account', 'INTERVAL 30 MINUTE', 'INTERVAL 30 DAY', "<> 'whatsapp.integration'",
            "p.party_class IN ('customer', 'partner')", 'NOT EXISTS (' . ServiceFeed::OPEN_DEAL_SQL . ')',
            "REGEXP_REPLACE(COALESCE(ct.phone_number, ''), '[^0-9]', '') <> '559140403376'",
            "COALESCE(ct.identifier, '') NOT IN ('559140403376@c.us', '559140403376@s.whatsapp.net')",
            "z.target_type = 'ChatwootConversation'", ' AND OWNER = :deckOwner',
        ] as $part) self::assertStringContainsString($part, $sql);
        self::assertStringContainsString('c.last_message_received_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY THEN 0 WHEN c.last_message_received_at IS NOT NULL',
            $sql, 'conversations waiting up to 7 days come first, then older waits');
        self::assertStringContainsString('INTERVAL 7 DAY THEN c.last_message_received_at END ASC, CASE WHEN', $sql, 'longest recent wait first');
        self::assertSame(['crm-account', 'erivania', 'erivania'], [$bound['account'], $bound['user'], $bound['deckOwner']]);
    }

    public function testOnlyAParticipantOfTheShownCardCanBeLabeled(): void
    {
        [$feed, $verdicts, $parties, $cache] = $this->feed();
        $participants = [['id' => '7', 'own' => true, 'account' => 'crm-account', 'accountId' => 12], ['id' => '20094', 'account' => 'crm-account', 'accountId' => 12]];
        $cache->method('tryGet')->willReturn(['token' => 'token', 'expires' => time() + 60, 'revision' => 'r', 'participants' => $participants]);
        $parties->expects(self::exactly(2))->method('set');
        $verdicts->expects(self::once())->method('resolve')->with('ChatwootConversation', 'conv');

        self::assertSame('Pessoal', $feed->label('conv', '7', 'personal', 'token', $this->request())['label'],
            'marking the chat\'s own contact as not a customer also clears its card');
        $feed->label('conv', '20094', 'team', 'token', $this->request());
        $this->expectException(BadRequest::class);
        $feed->label('conv', '999', 'team', 'token', $this->request());
    }

    public function testAGroupMirroredOnSeveralNumbersIsOneCard(): void
    {
        $rows = [
            ['id' => 'copy-a', 'contact' => 'group-contact', 'owner' => null, 'grp' => 1],
            ['id' => 'copy-b', 'contact' => 'group-contact', 'owner' => null, 'grp' => 1],
            ['id' => 'direct', 'contact' => 'person', 'owner' => null, 'grp' => 0],
        ];
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn($rows);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $conversations = array_map(fn ($row) => $this->entity(['chatwootAccountId' => 'crm-account', 'contactDisplayName' => $row['id']], $row['id']), $rows);

        $builder = $this->getMockBuilder(\Espo\Core\Select\SelectBuilder::class)->disableOriginalConstructor()->getMock();
        $builder->method('from')->willReturnSelf();
        $builder->method('withStrictAccessControl')->willReturnSelf();
        $query = $this->getMockBuilder(\Espo\ORM\Query\SelectBuilder::class)->disableOriginalConstructor()->getMock();
        $query->method('where')->willReturnSelf();
        $query->method('build')->willReturn($this->getMockBuilder(\Espo\ORM\Query\Select::class)->disableOriginalConstructor()->getMock());
        $builder->method('buildQueryBuilder')->willReturn($query);
        $factory = $this->createMock(SelectBuilderFactory::class);
        $factory->method('create')->willReturn($builder);
        $select = $this->getMockBuilder(\Espo\ORM\Repository\RDBSelectBuilder::class)->disableOriginalConstructor()->getMock();
        $select->method('find')->willReturn(new \Espo\ORM\EntityCollection($conversations));
        $repository = $this->getMockBuilder(\Espo\ORM\Repository\RDBRepository::class)->disableOriginalConstructor()->getMock();
        $repository->method('clone')->willReturn($select);
        $users = $this->getMockBuilder(\Espo\ORM\Repository\RDBRepository::class)->disableOriginalConstructor()->getMock();

        $user = $this->createMock(User::class);
        $user->method('isRegular')->willReturn(true);
        $user->method('getId')->willReturn('erivania');
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $em->method('getRDBRepository')->willReturnCallback(fn ($type) => $type === 'User' ? $users : $repository);
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('resolve')->willReturn(['tenant' => $this->entity([], 'tenant'), 'policy' => [], 'account' => $this->entity([], 'crm-account')]);
        $owners = $this->createMock(Ownership::class);
        $owners->method('conversationClause')->willReturn(['', []]);
        $request = $this->createMock(Request::class);
        $feed = new ServiceFeed($em, $factory, $acl, $user, $workspace, $owners, $this->createMock(Verdicts::class),
            $this->createMock(Transcript::class), $this->createMock(Parties::class), $this->createMock(DataCache::class),
            $this->createMock(Records::class), $this->chatwoot());

        self::assertSame(['copy-a', 'direct'], array_column($feed->list($request)['items'], 'id'));
    }
}
