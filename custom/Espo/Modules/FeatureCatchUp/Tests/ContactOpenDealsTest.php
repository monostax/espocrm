<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Acl;
use Espo\Core\Acl\AssignmentChecker\AssignmentCheckerManager;
use Espo\Core\Api\Request;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\Acts;
use Espo\Modules\FeatureCatchUp\Services\DealStates;
use Espo\Modules\FeatureCatchUp\Services\DealTargets;
use Espo\Modules\FeatureCatchUp\Services\Parties;
use Espo\Modules\FeatureCatchUp\Services\ProposedDeals;
use Espo\Modules\FeatureCatchUp\Services\Records;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * One contact, several conversations (2026-11-27): Nowle's WhatsApp group sits in two staff inboxes at Monostax, so it is
 * two Chatwoot conversations. Em dia checked deals per conversation, and each one auto-created its own deal ("Nowle -
 * Assinatura", then "Nowle - Contratação (2 usuários)"). While the contact has an open deal in any of its conversations, Em
 * dia now proposes and creates none; lane routing stays per conversation.
 */
class ContactOpenDealsTest extends TestCase
{
    private function entity(string $id, array $values = []): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        $entity->method('getId')->willReturn($id);
        return $entity;
    }

    /** @return array{Summary, Verdicts, ProposedDeals} */
    private function summary(?array $stored, bool $covered): array
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn($stored);
        $targets = $this->createMock(DealTargets::class);
        $targets->method('sanitize')->willReturnArgument(1);
        $proposedDeals = $this->createMock(ProposedDeals::class);
        $proposedDeals->method('covered')->with('c1', 'tenant')->willReturn($covered);
        $summary = $this->getMockBuilder(Summary::class)
            ->setConstructorArgs([$this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $this->createMock(Records::class), $targets, $proposedDeals])
            ->onlyMethods(['request'])->getMock();
        return [$summary, $verdicts, $proposedDeals];
    }

    private function snapshot(): array
    {
        return ['id' => 'c1', 'type' => 'conversation', 'tenantId' => 'tenant', 'revision' => 'rev', 'evidenceScope' => 'scope',
            'evidence' => [], 'facts' => [], 'catchUpPolicy' => ['instance' => 'https://chat.test'], 'participants' => [],
            'record' => ['agreed' => [], 'promises' => []], 'closedDeals' => []];
    }

    public function testAFoundDealIsNothingNewWhileTheContactHasAnOpenDealElsewhere(): void
    {
        [$summary, $verdicts, $proposedDeals] = $this->summary(null, true);
        $summary->method('request')->willReturn(['status' => 'completed', 'card' => ['verdict' => 'new_deal',
            'reason' => 'Jow enviou a contratação ao financeiro', 'headline' => 'Registrar contratação da Nowle', 'whyNow' => 'w',
            'proposedDeal' => ['name' => 'Nowle - Contratação (2 usuários)', 'amount' => null, 'funnelId' => 'f', 'stageId' => 's']]]);
        $proposedDeals->expects(self::never())->method('autoCreate');
        $stored = null;
        $verdicts->expects(self::once())->method('store')->willReturnCallback(function (...$args) use (&$stored): void { $stored = $args; });

        $answer = $summary->analyse($this->snapshot(), 'pt-BR');

        self::assertSame(['none', null], [$answer['verdict'], $answer['card']]);
        self::assertSame(['none', null], [$stored[6], $stored[8]], 'stored quiet until the conversation moves');
    }

    public function testAReplyCardKeepsItsReplyButOffersNoDealWhileTheContactHasAnOpenDealElsewhere(): void
    {
        [$summary, $verdicts, $proposedDeals] = $this->summary(null, true);
        $summary->method('request')->willReturn(['status' => 'completed', 'card' => ['verdict' => 'loops', 'reason' => 'Pediu o link',
            'headline' => 'Enviar o link', 'whyNow' => 'w', 'draft' => 'Segue o link',
            'record' => ['agreed' => [], 'promises' => [['id' => 'p1', 'side' => 'ours', 'text' => 'Enviar o link', 'status' => 'open']]],
            'proposedDeal' => ['name' => 'Nowle - Assinatura', 'amount' => null, 'funnelId' => 'f', 'stageId' => 's']]]);
        $proposedDeals->expects(self::never())->method('autoCreate');
        $stored = null;
        $verdicts->expects(self::once())->method('store')->willReturnCallback(function (...$args) use (&$stored): void { $stored = $args; });

        $answer = $summary->analyse($this->snapshot(), 'pt-BR');

        self::assertSame('customer_waiting', $answer['verdict']);
        self::assertSame('Segue o link', $stored[8]['draft']);
        self::assertArrayNotHasKey('proposedDeal', $stored[8]);
        self::assertArrayNotHasKey('closedDeals', $stored[8]);
    }

    public function testTheContactsDealsAreOnlyLookedUpWhenTheAnswerProposesOne(): void
    {
        [$summary, $verdicts, $proposedDeals] = $this->summary(null, false);
        $summary->method('request')->willReturn(['status' => 'completed', 'card' => ['verdict' => 'loops', 'reason' => 'Pediu o link',
            'headline' => 'Enviar o link', 'whyNow' => 'w', 'draft' => 'Segue o link',
            'record' => ['agreed' => [], 'promises' => [['id' => 'p1', 'side' => 'ours', 'text' => 'Enviar o link', 'status' => 'open']]]]]);
        $proposedDeals->expects(self::never())->method('covered');

        self::assertSame('customer_waiting', $summary->analyse($this->snapshot(), 'pt-BR')['verdict']);
    }

    public function testAFoundDealWithoutAnOpenDealElsewhereIsStillCreated(): void
    {
        [$summary, $verdicts, $proposedDeals] = $this->summary(null, false);
        $summary->method('request')->willReturn(['status' => 'completed', 'card' => ['verdict' => 'new_deal', 'reason' => 'r',
            'headline' => 'h', 'whyNow' => 'w', 'proposedDeal' => ['name' => 'Ana - Consulta', 'amount' => null, 'funnelId' => 'f', 'stageId' => 's']]]);
        $proposedDeals->expects(self::once())->method('autoCreate')->willReturn(null);

        self::assertSame('new_deal', $summary->analyse($this->snapshot(), 'pt-BR')['verdict']);
    }

    public function testAStoredOfferLosesItWhenTheContactsDealOpenedElsewhereWithoutAnotherAnalysis(): void
    {
        $stored = ['verdict' => 'new_deal', 'reason' => 'r', 'resolved' => false,
            'card' => ['headline' => 'h', 'proposedDeal' => ['name' => 'Nowle - Contratação', 'funnelId' => 'f', 'stageId' => 's'], 'closedDeals' => []]];
        [$summary, $verdicts, $proposedDeals] = $this->summary($stored, true);
        $summary->expects(self::never())->method('request');
        $proposedDeals->expects(self::never())->method('autoCreate');
        $verdicts->expects(self::once())->method('withoutDeal')->with('c1', 'rev', 'pt-BR', 'scope', $stored)
            ->willReturn(['verdict' => 'none', 'reason' => 'r', 'card' => null]);

        $answer = $summary->analyse($this->snapshot(), 'pt-BR');

        self::assertSame(['none', null, true], [$answer['verdict'], $answer['card'], $answer['cached']]);
    }

    public function testACardWithoutTheOfferIsAQuietFoundDealOrTheSameReplyCard(): void
    {
        self::assertSame(['verdict' => 'none', 'card' => null], Verdicts::dropDeal('new_deal', ['proposedDeal' => ['name' => 'X']]));
        self::assertSame(['verdict' => 'customer_waiting', 'card' => ['draft' => 'Oi']],
            Verdicts::dropDeal('customer_waiting', ['draft' => 'Oi', 'proposedDeal' => ['name' => 'X'], 'closedDeals' => ['k']]));
    }

    public function testTheStoredCardIsUpdatedInPlace(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(['verdict' => 'promise_due', 'card' => '{"draft":"Olá"}',
            'id' => 'c1', 'revision' => 'rev', 'locale' => 'pt-BR', 'scope' => 'scope'])->willReturn(true);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')
            ->with(self::callback(fn (string $sql) => str_starts_with($sql, 'UPDATE catch_up_verdict SET verdict = :verdict, card = :card ')
                && str_contains($sql, 'conversation_id = :id AND revision = :revision AND locale = :locale AND evidence_scope = :scope')))
            ->willReturn($statement);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);

        $answer = (new Verdicts($em))->withoutDeal('c1', 'rev', 'pt-BR', 'scope',
            ['verdict' => 'promise_due', 'reason' => 'r', 'card' => ['draft' => 'Olá', 'proposedDeal' => ['name' => 'X']]]);

        self::assertSame(['verdict' => 'promise_due', 'reason' => 'r', 'card' => ['draft' => 'Olá']], $answer);
    }

    /** @return array{ProposedDeals, ServiceFeed, ServiceContainer} */
    private function deals(EntityManager $em, Acl $acl, ?Workspace $workspace = null): array
    {
        $service = $this->createMock(ServiceFeed::class);
        $records = $this->createMock(ServiceContainer::class);
        $deals = new ProposedDeals($em, $acl, $this->createMock(User::class), $workspace ?? $this->createMock(Workspace::class),
            $this->createMock(Verdicts::class), $service, $this->createMock(DealTargets::class), $records,
            $this->createMock(AssignmentCheckerManager::class), $this->createMock(Config::class), $this->createMock(TeamTenantAccess::class),
            $this->createMock(Acts::class), $this->createMock(DealStates::class), $this->createMock(Log::class));
        return [$deals, $service, $records];
    }

    /** An EntityManager whose transaction runs at once and whose locked conversation is a current one. */
    private function em(array $entities = []): EntityManager
    {
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->method('run')->willReturnCallback(fn (\Closure $run) => $run());
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('forUpdate')->willReturnSelf();
        $select->method('findOne')->willReturn($this->entity('conv'));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($select);
        $em = $this->createMock(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transactions);
        $em->method('getRDBRepository')->willReturn($repository);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $entities["$type:$id"] ?? null);
        return $em;
    }

    public function testEmDiaCreatesNoDealWhileTheContactHasAnOpenOneInAnotherConversation(): void
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        [$deals, $service, $records] = $this->deals($this->em(['Tenant:tenant' => $this->entity('tenant')]), $acl);
        $service->expects(self::once())->method('contactOpenDeals')->with('conv', 'tenant')->willReturn(['assinatura']);
        $records->expects(self::never())->method('get');

        self::assertNull($deals->autoCreate('conv', 'tenant', ['proposedDeal' => ['name' => 'Nowle - Contratação', 'funnelId' => 'f', 'stageId' => 's'],
            'closedDeals' => []], []));
    }

    public function testCriarOportunidadeOpensTheContactsOpenDealInsteadOfASecondOne(): void
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkEntityRead')->willReturnCallback(fn (Entity $deal) => $deal->getId() !== 'hidden');
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('resolve')->willReturn(['account' => $this->entity('acct'), 'tenant' => $this->entity('tenant')]);
        [$deals, $service, $records] = $this->deals($this->em([
            'Opportunity:hidden' => $this->entity('hidden'), 'Opportunity:assinatura' => $this->entity('assinatura'),
        ]), $acl, $workspace);
        $service->method('conversation')->willReturn($this->entity('conv', ['chatwootAccountId' => 'acct']));
        $service->method('contactOpenDeals')->with('conv', 'tenant')->willReturn(['hidden', 'assinatura']);
        $records->expects(self::never())->method('get');

        self::assertSame(['existing' => true, 'id' => 'assinatura'], $deals->create($this->createMock(Request::class), 'conv'));
    }

    public function testAnOpenDealTheReaderMayNotReadIsNeverNamed(): void
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkEntityRead')->willReturn(false);
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('resolve')->willReturn(['account' => $this->entity('acct'), 'tenant' => $this->entity('tenant')]);
        [$deals, $service] = $this->deals($this->em(['Opportunity:hidden' => $this->entity('hidden')]), $acl, $workspace);
        $service->method('conversation')->willReturn($this->entity('conv', ['chatwootAccountId' => 'acct']));
        $service->method('contactOpenDeals')->willReturn(['hidden']);

        self::assertSame(['existing' => true], $deals->create($this->createMock(Request::class), 'conv'));
    }
}
