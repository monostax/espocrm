<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Classes\CardGone;
use Espo\Modules\FeatureCatchUp\Services\Acts;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Linked;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Transcript;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class LinkedTest extends TestCase
{
    private const CUSTOMER_AT = 1_763_550_000;

    private function entity(array $values, string $id = 'id'): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        $entity->method('getId')->willReturn($id);
        return $entity;
    }

    /**
     * @param array{deal?: ?array<string, mixed>, readable?: bool, conversation?: ?array<string, mixed>, conversationError?: ?\Throwable,
     *   evidence?: list<array<string, mixed>>,
     *   act?: ?array<string, string>, snoozedUntil?: ?string, closedBy?: ?array<string, string>} $options
     */
    private function linked(array $options = []): Linked
    {
        $options += ['deal' => ['tenantId' => 'tenant', 'status' => 'Open', 'name' => 'Implante', 'contactName' => 'Marta'],
            'readable' => true, 'conversation' => ['contactDisplayName' => 'Joe', 'chatwootConversationId' => 773],
            'conversationError' => null, 'evidence' => [], 'act' => null, 'snoozedUntil' => null, 'closedBy' => null];
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('reader');
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntityRead')->willReturn($options['readable']);
        $acl->method('checkField')->willReturn(true);
        $names = ['ana' => 'Ana Souza', 'bruno' => 'Bruno Lima'];
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn (string $type, string $id) => match ($type) {
            'Opportunity' => $options['deal'] !== null ? $this->entity($options['deal'], $id) : null,
            'ChatwootConversation' => $options['conversation'] !== null ? $this->entity($options['conversation'], $id) : null,
            'User' => isset($names[$id]) ? $this->entity(['name' => $names[$id]], $id) : null,
            default => null,
        });
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn($options['snoozedUntil'] ?? false);
        $statement->method('fetch')->willReturn($options['closedBy'] ?? false);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $em->method('getPDO')->willReturn($pdo);
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('resolve')->willReturn([
            'tenant' => $this->entity([], 'tenant'), 'account' => $this->entity(['chatwootAccountId' => 12], 'crm-account'),
        ]);
        $feed = $this->createMock(Feed::class);
        $feed->method('conversations')->willReturn(['c1' => $this->entity([], 'c1')]);
        $feed->method('target')->willReturn(['conversationId' => 773, 'accountId' => 12, 'inboxName' => 'WhatsApp']);
        $service = $this->createMock(ServiceFeed::class);
        $service->method('conversation')->willReturnCallback(function (string $id) use ($options) {
            if ($options['conversationError']) throw $options['conversationError'];
            return $this->entity($options['conversation'], $id);
        });
        $transcript = $this->createMock(Transcript::class);
        $transcript->method('read')->willReturn(['evidence' => $options['evidence'], 'participants' => []]);
        $acts = $this->createMock(Acts::class);
        $acts->method('last')->willReturn($options['act']);
        return new Linked($em, $acl, $user, $workspace, $feed, $service, $transcript, $acts);
    }

    private function request(string $lane, string $id = 'subject'): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(fn ($name) => ['lane' => $lane, 'id' => $id, 'timeZone' => 'America/Sao_Paulo'][$name] ?? null);
        return $request;
    }

    private static function message(string $sentBy, int $ts, ?string $author = null): array
    {
        return ['id' => (string) $ts, 'sentBy' => $sentBy, 'ts' => $ts, 'author' => $author, 'kind' => $sentBy === 'internal' ? 'note' : 'outgoing'];
    }

    private static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    public function testAnActInEmDiaAfterTheCustomersLastMessageSaysWhoAndHow(): void
    {
        $result = $this->linked([
            'evidence' => [self::message('customer', self::CUSTOMER_AT), self::message('staff', self::CUSTOMER_AT + 70, 'Ana Souza')],
            'act' => ['userId' => 'ana', 'action' => 'reply', 'at' => self::utc(self::CUSTOMER_AT + 60)],
        ])->read($this->request('deals'));

        self::assertSame('resolved', $result['state']);
        self::assertSame('reply', $result['action']);
        self::assertSame('Ana Souza', $result['by']);
        self::assertSame(gmdate(DATE_ATOM, self::CUSTOMER_AT + 60), $result['at']);
        self::assertSame('Marta', $result['name']);
        self::assertSame('Implante', $result['deal']);
        self::assertSame(773, $result['conversation']['conversationId']);
    }

    public function testAnActFromBeforeTheCustomerWroteAgainGivesWayToWhoAnsweredOutsideEmDia(): void
    {
        $result = $this->linked([
            'evidence' => [
                self::message('customer', self::CUSTOMER_AT),
                self::message('ai', self::CUSTOMER_AT + 30, 'IA - Kibu'),
                self::message('internal', self::CUSTOMER_AT + 90, 'Bruno Lima'),
            ],
            'act' => ['userId' => 'ana', 'action' => 'done', 'at' => self::utc(self::CUSTOMER_AT - 3600)],
        ])->read($this->request('service'));

        self::assertSame('replied', $result['state']);
        self::assertSame('IA - Kibu', $result['by']);
        self::assertSame('ai', $result['sentBy']);
        self::assertSame(gmdate(DATE_ATOM, self::CUSTOMER_AT + 30), $result['at']);
        self::assertSame('Joe', $result['name']);
        self::assertSame(['conversationId' => 773, 'accountId' => 12], $result['conversation']);
    }

    public function testWithNobodyActingTheReadersOwnSnoozeOrNothingToDoExplainsIt(): void
    {
        $evidence = [self::message('staff', self::CUSTOMER_AT - 60, 'Ana Souza'), self::message('customer', self::CUSTOMER_AT)];

        $snoozed = $this->linked(['evidence' => $evidence, 'snoozedUntil' => '2026-11-20 12:00:00'])->read($this->request('service'));
        $quiet = $this->linked(['evidence' => $evidence])->read($this->request('service'));

        self::assertSame(['state' => 'snoozed', 'at' => '2026-11-20T12:00:00+00:00'], array_intersect_key($snoozed, ['state' => 1, 'at' => 1]));
        self::assertSame('quiet', $quiet['state']);
    }

    public function testAClosedDealSaysWhoMovedItFromItsStream(): void
    {
        $won = $this->linked([
            'deal' => ['tenantId' => 'tenant', 'status' => 'Won', 'name' => 'Implante', 'contactName' => 'Marta'],
            'closedBy' => ['created_by_id' => 'bruno', 'created_at' => '2026-11-19 14:05:00'],
        ])->read($this->request('deals'));

        self::assertSame('won', $won['state']);
        self::assertSame('Bruno Lima', $won['by']);
        self::assertSame('2026-11-19T14:05:00+00:00', $won['at']);
    }

    public function testAMissingSubjectIsGoneAndOneTheReaderCantOpenIsRefused(): void
    {
        self::assertSame(['state' => 'gone'], $this->linked(['deal' => null])->read($this->request('deals')));
        self::assertSame(['state' => 'gone'], $this->linked(['conversationError' => new NotFound()])->read($this->request('service')));

        $this->expectException(Forbidden::class);
        $this->linked(['readable' => false])->read($this->request('deals'));
    }

    public function testAConversationWhoseCycleClosedStillSaysWhoActed(): void
    {
        $result = $this->linked([
            'conversationError' => new CardGone(),
            'act' => ['userId' => 'ana', 'action' => 'deal', 'at' => self::utc(self::CUSTOMER_AT)],
        ])->read($this->request('service'));

        self::assertSame('resolved', $result['state']);
        self::assertSame('deal', $result['action']);
        self::assertSame('Joe', $result['name']);
    }
}
