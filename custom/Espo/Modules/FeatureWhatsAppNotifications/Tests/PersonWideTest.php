<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Rollout;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Avisos;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Cards;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Copy;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Deck;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\PhoneCodes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Pushes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Subscriptions;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Taps;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Tokens;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Workspaces;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** The verified number is the person's, not the workspace's (2026-11-13). */
class PersonWideTest extends TestCase
{
    private const PHONE = '+5511963561157';

    /** @var array<string, list<Entity>> rows per entity type */
    private array $store = [];
    /** @var list<array<string, mixed>> */
    private array $whereSeen = [];
    private int $ids = 0;

    /** An in-memory entity: get/set/has like the ORM's (BaseEntity, whose set() takes one array like the code does). */
    private function row(string $type, array $values = []): Entity
    {
        $data = ['__type' => $type, ...$values];
        $entity = $this->createMock(BaseEntity::class);
        $entity->method('getId')->willReturnCallback(function () use (&$data) { return (string) ($data['id'] ?? ''); });
        $entity->method('get')->willReturnCallback(function (string $name) use (&$data) {
            return $data[$name] ?? null;
        });
        $entity->method('has')->willReturnCallback(function (string $name) use (&$data) {
            return array_key_exists($name, $data);
        });
        $entity->method('set')->willReturnCallback(function ($name, $value = null) use (&$data, $entity) {
            foreach (is_array($name) ? $name : [$name => $value] as $key => $each) $data[$key] = $each;
            return $entity;
        });
        return $entity;
    }

    private function fits(Entity $row, array $where): bool
    {
        foreach ($where as $key => $value) {
            if (str_ends_with($key, '>=')) {
                if ((string) $row->get(substr($key, 0, -2)) < (string) $value) return false;
                continue;
            }
            $not = str_ends_with($key, '!=');
            $field = $not ? substr($key, 0, -2) : $key;
            $actual = $row->get($field);
            $hit = is_array($value) ? in_array($actual, $value, true) : $actual === $value;
            if ($hit === $not) return false;
        }
        return true;
    }

    /** A database whose one query answers these rows (Deck's waits). */
    private function pdo(array $rows): PDO
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchAll')->willReturn($rows);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        return $pdo;
    }

    private function em(?User $user = null): EntityManager
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(function (string $type) {
            $repository = $this->createMock(RDBRepository::class);
            $build = function (array $where) use ($type) {
                $this->whereSeen[] = [$type => $where];
                $rows = array_values(array_filter($this->store[$type] ?? [], fn (Entity $row) => $this->fits($row, $where)));
                $select = $this->createMock(RDBSelectBuilder::class);
                $select->method('order')->willReturnSelf();
                $select->method('find')->willReturn(new EntityCollection($rows));
                $select->method('findOne')->willReturn($rows[0] ?? null);
                $select->method('count')->willReturn(count($rows));
                return $select;
            };
            $repository->method('where')->willReturnCallback($build);
            $selecting = $this->createMock(RDBSelectBuilder::class);
            $selecting->method('where')->willReturnCallback($build);
            $repository->method('select')->willReturn($selecting);
            return $repository;
        });
        $em->method('getEntityById')->willReturnCallback(function (string $type, string $id) use ($user) {
            if ($type === 'User') return $user;
            foreach ($this->store[$type] ?? [] as $row) if ($row->getId() === $id) return $row;
            return null;
        });
        $em->method('getNewEntity')->willReturnCallback(fn (string $type) => $this->row($type));
        $em->method('saveEntity')->willReturnCallback(function (Entity $entity) {
            $type = (string) $entity->get('__type');
            if (!$entity->get('id')) {
                $entity->set('id', 'new' . (++$this->ids));
                $this->store[$type][] = $entity;
            }
        });
        return $em;
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('u1');
        $user->method('get')->willReturnCallback(fn (string $name) => $name === 'name' ? 'Antonio' : null);
        $user->method('isActive')->willReturn(true);
        return $user;
    }

    /** Workspaces a1 (verified), a2 (Em dia on), a3 (Em dia off), a4 (another person holds the number). */
    private function subscriptions(EntityManager $em, ?Workspaces $workspaces = null): Subscriptions
    {
        foreach (['a1', 'a2', 'a3', 'a4'] as $index => $id) {
            $this->store['ChatwootAccount'][] = $this->row('ChatwootAccount', ['id' => $id, 'tenantId' => "t{$id}", 'chatwootAccountId' => $index + 1, 'name' => "W{$id}"]);
        }
        $workspaces ??= $this->createMock(Workspaces::class);
        $workspaces->method('accountsOf')->willReturn($this->store['ChatwootAccount']);
        $workspaces->method('live')->willReturn(true);
        $settings = $this->createMock(Settings::class);
        $settings->method('enabled')->willReturn(true);
        $rollout = $this->createMock(Rollout::class);
        $rollout->method('forAccount')->willReturnCallback(function (Entity $account) {
            if ($account->getId() === 'a3') throw new Forbidden();
            return [];
        });
        return new Subscriptions($em, $this->user(), $settings, $this->createMock(Workspace::class), $rollout,
            $this->createMock(PhoneCodes::class), $this->createMock(Avisos::class), $workspaces);
    }

    private function otherPersonHoldsTheNumberInA4(): void
    {
        $this->store['WhatsAppNotificationSubscription'][] = $this->row('WhatsAppNotificationSubscription', [
            'id' => 'other', 'userId' => 'u2', 'chatwootAccountId' => 'a4', 'phone' => self::PHONE, 'status' => 'active',
        ]);
    }

    private function subscriptionIn(string $accountId): ?Entity
    {
        foreach ($this->store['WhatsAppNotificationSubscription'] ?? [] as $row) {
            if ($row->get('userId') === 'u1' && $row->get('chatwootAccountId') === $accountId) return $row;
        }
        return null;
    }

    public function testOneCodeTurnsItOnInEveryWorkspaceOfThePersonWithEmDia(): void
    {
        $em = $this->em($this->user());
        $this->otherPersonHoldsTheNumberInA4();
        $subscriptions = $this->subscriptions($em);
        $verified = $subscriptions->activate('u1', 'a1', self::PHONE, 'settings');
        $verified->set(['morningTime' => '07:30', 'hotLeadsEnabled' => false]);

        $spread = $this->subscriptionIn('a2');
        $this->assertNotNull($spread);
        $this->assertSame(['active', self::PHONE, 'person', null, null, 'ta2', 2],
            [$spread->get('status'), $spread->get('phone'), $spread->get('source'), $spread->get('avisosContactId'),
                $spread->get('avisosConversationId'), $spread->get('tenantId'), $spread->get('accountNumber')]);
        $this->assertNull($verified->get('lastInboundAt'), 'a code we sent opens no 24h window');
        $this->assertNull($spread->get('lastInboundAt'));
        $this->assertNull($this->subscriptionIn('a3'), 'Em dia is off there');
        $this->assertNull($this->subscriptionIn('a4'), 'another person holds the number there');
        $this->assertSame('active', $this->store['WhatsAppNotificationSubscription'][0]->get('status'));
    }

    public function testANewCodeTurnsBackOnTheirOtherWorkspacesWithTheirOwnSwitches(): void
    {
        $em = $this->em($this->user());
        $old = $this->row('WhatsAppNotificationSubscription', [
            'id' => 'old', 'userId' => 'u1', 'chatwootAccountId' => 'a2', 'phone' => '+5511900000000', 'status' => 'stopped',
            'stopReason' => 'user', 'morningEnabled' => false, 'morningTime' => '10:00', 'source' => 'settings',
        ]);
        $this->store['WhatsAppNotificationSubscription'][] = $old;
        $this->subscriptions($em)->activate('u1', 'a1', self::PHONE, 'settings');
        $this->assertSame(['active', self::PHONE, null, false, '10:00', 'settings'],
            [$old->get('status'), $old->get('phone'), $old->get('stopReason'), $old->get('morningEnabled'), $old->get('morningTime'), $old->get('source')]);
    }

    public function testVerifyingTheSameNumberKeepsItsWindowAndThreadAndANewNumberStartsWithout(): void
    {
        $em = $this->em($this->user());
        $mine = $this->row('WhatsAppNotificationSubscription', [
            'id' => 'mine', 'userId' => 'u1', 'chatwootAccountId' => 'a1', 'phone' => self::PHONE, 'status' => 'stopped',
            'lastInboundAt' => '2026-11-22 10:00:00', 'avisosContactId' => 5, 'avisosConversationId' => 6,
        ]);
        $this->store['WhatsAppNotificationSubscription'][] = $mine;
        $this->subscriptions($em)->activate('u1', 'a1', self::PHONE, 'settings');
        $this->assertSame(['active', '2026-11-22 10:00:00', 5, 6],
            [$mine->get('status'), $mine->get('lastInboundAt'), $mine->get('avisosContactId'), $mine->get('avisosConversationId')]);

        $this->subscriptions($em)->activate('u1', 'a1', '+5511988887777', 'settings');
        $this->assertSame(['+5511988887777', null, null, null],
            [$mine->get('phone'), $mine->get('lastInboundAt'), $mine->get('avisosContactId'), $mine->get('avisosConversationId')]);
    }

    public function testAWorkspaceJoinedLaterIsAddedButAStoppedOneStaysStopped(): void
    {
        $em = $this->em($this->user());
        $this->otherPersonHoldsTheNumberInA4();
        $from = $this->row('WhatsAppNotificationSubscription', [
            'id' => 's1', 'userId' => 'u1', 'chatwootAccountId' => 'a1', 'phone' => self::PHONE, 'status' => 'active',
            'morningEnabled' => true, 'morningTime' => '07:15', 'hotLeadsEnabled' => true, 'alertStart' => '09:00', 'alertEnd' => '18:00',
            'lastInboundAt' => '2026-11-13 20:00:00',
        ]);
        $this->store['WhatsAppNotificationSubscription'][] = $from;
        $subscriptions = $this->subscriptions($em);
        $this->assertSame(1, $subscriptions->spread($from));
        $joined = $this->subscriptionIn('a2');
        $this->assertSame(['07:15', '09:00', '18:00', '2026-11-13 20:00:00'],
            [$joined->get('morningTime'), $joined->get('alertStart'), $joined->get('alertEnd'), $joined->get('lastInboundAt')]);

        $joined->set(['status' => 'stopped', 'stopReason' => 'moved']);
        $this->assertSame(0, $subscriptions->spread($from));
        $this->assertSame('stopped', $joined->get('status'));
    }

    public function testTheNumberListsWorkspacesJoinedSinceAndDropsOnesThePersonLeft(): void
    {
        $em = $this->em($this->user());
        $this->otherPersonHoldsTheNumberInA4();
        $this->store['WhatsAppNotificationSubscription'][] = $this->row('WhatsAppNotificationSubscription', [
            'id' => 's1', 'userId' => 'u1', 'chatwootAccountId' => 'a1', 'phone' => self::PHONE, 'status' => 'active',
        ]);
        $left = $this->row('WhatsAppNotificationSubscription', [
            'id' => 'left', 'userId' => 'u1', 'chatwootAccountId' => 'gone', 'phone' => self::PHONE, 'status' => 'active',
        ]);
        $this->store['WhatsAppNotificationSubscription'][] = $left;
        $workspaces = $this->createMock(Workspaces::class);
        $workspaces->method('live')->willReturnCallback(fn (Entity $subscription) => $subscription !== $left);
        $active = $this->subscriptions($em, $workspaces)->activeForPhone(self::PHONE);
        $this->assertSame(['a4:u2', 'a1:u1', 'a2:u1'], array_map(fn (Entity $row) => $row->get('chatwootAccountId') . ':' . $row->get('userId'), $active));
    }

    public function testAPersonOutOfTheWorkspaceIsNotLive(): void
    {
        $user = $this->user();
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn (string $type, string $id) => match ($type) {
            'User' => $user,
            'ChatwootAccount' => $this->row('ChatwootAccount', ['id' => $id, 'tenantId' => "t-{$id}"]),
            'Tenant' => $this->row('Tenant', ['id' => $id]),
            default => null,
        });
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('resolveTenantIdSet')->willReturn(['t-a1' => true]);
        $workspaces = new Workspaces($em, $tenants);
        $this->assertTrue($workspaces->live($this->row('WhatsAppNotificationSubscription', ['userId' => 'u1', 'chatwootAccountId' => 'a1'])));
        $this->assertFalse($workspaces->live($this->row('WhatsAppNotificationSubscription', ['userId' => 'u1', 'chatwootAccountId' => 'a2'])));
    }

    public function testTheHotLeadCapCountsThePersonsAlertsFromEveryWorkspace(): void
    {
        $em = $this->em($this->user());
        $here = $this->row('WhatsAppNotificationSubscription', [
            'id' => 's1', 'userId' => 'u1', 'chatwootAccountId' => 'a1', 'phone' => self::PHONE, 'status' => 'active',
            'hotLeadsEnabled' => true, 'alertStart' => '00:00', 'alertEnd' => '23:59',
        ]);
        $there = $this->row('WhatsAppNotificationSubscription', ['id' => 's2', 'userId' => 'u1', 'chatwootAccountId' => 'a2', 'phone' => self::PHONE]);
        $this->store['WhatsAppNotificationSubscription'] = [$here, $there];
        foreach ([1, 2] as $n) {
            $this->store['WhatsAppNotification'][] = $this->row('WhatsAppNotification', [
                'id' => "n{$n}", 'subscriptionId' => 's2', 'kind' => 'hot_lead', 'createdAt' => '2999-01-01 00:00:00', 'status' => 'delivered',
            ]);
        }
        $workspaces = $this->createMock(Workspaces::class);
        $workspaces->method('live')->willReturn(true);
        $workspaces->method('of')->willReturn(['frozen' => false]);
        $workspaces->method('now')->willReturn(new DateTimeImmutable('2026-11-13 12:00', new DateTimeZone('America/Sao_Paulo')));
        $settings = $this->createMock(Settings::class);
        $settings->method('hotLeadDailyCap')->willReturn(2);
        $deck = $this->createMock(Deck::class);
        $deck->expects($this->never())->method('next');
        $pushes = new Pushes($em, $settings, $workspaces, $this->createMock(Taps::class), $deck, $this->createMock(Cards::class),
            $this->createMock(Avisos::class), $this->createMock(Tokens::class), $this->createMock(ActorScope::class),
            $this->createMock(ChatwootApiClient::class), $this->createMock(Log::class), $this->personSubscriptions($em, $workspaces));
        $pushes->hotLeads($here);
    }

    private function pushes(EntityManager $em, Workspaces $workspaces, ?Taps $taps = null, array $times = Settings::DEFAULT_PUSH_TIMES): Pushes
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('pushTimes')->willReturn($times);
        $settings->method('hotLeadDailyCap')->willReturn(2);
        return new Pushes($em, $settings, $workspaces, $taps ?? $this->createMock(Taps::class), $this->createMock(Deck::class),
            $this->createMock(Cards::class), $this->createMock(Avisos::class), $this->createMock(Tokens::class),
            $this->createMock(ActorScope::class), $this->createMock(ChatwootApiClient::class), $this->createMock(Log::class),
            $this->personSubscriptions($em, $workspaces));
    }

    private function personSubscriptions(EntityManager $em, Workspaces $workspaces): Subscriptions
    {
        return new Subscriptions($em, $this->user(), $this->createMock(Settings::class), $this->createMock(Workspace::class),
            $this->createMock(Rollout::class), $this->createMock(PhoneCodes::class), $this->createMock(Avisos::class), $workspaces);
    }

    private function workspacesAt(string $time, ?callable $live = null): Workspaces
    {
        $workspaces = $this->createMock(Workspaces::class);
        $workspaces->method('live')->willReturnCallback($live ?? fn () => true);
        $workspaces->method('exists')->willReturn(true);
        $workspaces->method('of')->willReturn(['frozen' => false]);
        $workspaces->method('now')->willReturn(new DateTimeImmutable($time, new DateTimeZone('America/Sao_Paulo')));
        return $workspaces;
    }

    /** The user, 2026-11-19: with several workspaces, the cards come from the first one the person turned Avisos on in. */
    public function testPushesComeFromTheFirstWorkspaceThePersonTurnedOnThatTheyStillBelongTo(): void
    {
        $em = $this->em($this->user());
        $newer = $this->row('WhatsAppNotificationSubscription', ['id' => 's1', 'userId' => 'u1', 'chatwootAccountId' => 'a1',
            'phone' => self::PHONE, 'status' => 'active', 'createdAt' => '2026-11-15 19:00:00']);
        $older = $this->row('WhatsAppNotificationSubscription', ['id' => 's2', 'userId' => 'u1', 'chatwootAccountId' => 'a2',
            'phone' => self::PHONE, 'status' => 'active', 'createdAt' => '2026-11-13 21:30:00']);
        $stopped = $this->row('WhatsAppNotificationSubscription', ['id' => 's0', 'userId' => 'u1', 'chatwootAccountId' => 'a3',
            'phone' => self::PHONE, 'status' => 'stopped', 'createdAt' => '2026-11-01 10:00:00']);
        $this->store['WhatsAppNotificationSubscription'] = [$newer, $older, $stopped];
        $subscriptions = $this->personSubscriptions($em, $this->workspacesAt('2026-11-19 08:00'));
        $this->assertSame('s2', $subscriptions->primary($newer)?->getId());
        $this->assertSame('s2', $subscriptions->primary($older)?->getId());
        $left = $this->personSubscriptions($em, $this->workspacesAt('2026-11-19 08:00', fn (Entity $row) => $row->getId() !== 's2'));
        $this->assertSame('s1', $left->primary($newer)?->getId());
        // Only the first workspace pushes: the other one's push time passes without a card.
        $taps = $this->createMock(Taps::class);
        $taps->expects($this->never())->method('next');
        $newer->set('morningEnabled', true);
        $this->pushes($em, $this->workspacesAt('2026-11-19 08:00'), $taps)->morning($newer);
    }

    /** One card at each push time, never one the person already had in the last 4 days, and only once per push time. */
    public function testEachPushTimeSendsOneCardThePersonHasNotHadInFourDays(): void
    {
        $em = $this->em($this->user());
        $here = $this->row('WhatsAppNotificationSubscription', ['id' => 's1', 'userId' => 'u1', 'chatwootAccountId' => 'a1',
            'phone' => self::PHONE, 'status' => 'active', 'morningEnabled' => true, 'createdAt' => '2026-11-13 21:30:00']);
        $other = $this->row('WhatsAppNotificationSubscription', ['id' => 's2', 'userId' => 'u1', 'chatwootAccountId' => 'a2',
            'phone' => self::PHONE, 'status' => 'active', 'createdAt' => '2026-11-15 10:00:00']);
        $this->store['WhatsAppNotificationSubscription'] = [$here, $other];
        $this->store['WhatsAppDeckCard'] = [
            $this->row('WhatsAppDeckCard', ['id' => 'c1', 'lane' => 'service', 'subjectId' => 'conv1']),
            $this->row('WhatsAppDeckCard', ['id' => 'c2', 'lane' => 'deals', 'subjectId' => 'deal1']),
            $this->row('WhatsAppDeckCard', ['id' => 'c3', 'lane' => 'service', 'subjectId' => 'conv3']),
            $this->row('WhatsAppDeckCard', ['id' => 'c4', 'lane' => 'deals', 'subjectId' => 'deal4']),
            $this->row('WhatsAppDeckCard', ['id' => 'c5', 'lane' => 'service', 'subjectId' => 'conv5']),
        ];
        // Now is 2026-11-19 11:05 America/Sao_Paulo = 2026-11-19 14:05:00 UTC; 4-day cutoff is 2026-11-15 14:05:00 UTC.
        $this->store['WhatsAppNotification'] = [
            // 08:00 local today: inside 4-day window.
            $this->row('WhatsAppNotification', ['id' => 'n1', 'subscriptionId' => 's1', 'direction' => 'out', 'cardId' => 'c1',
                'createdAt' => '2026-11-19 11:00:05', 'status' => 'read']),
            // 3 days ago: still inside 4-day window.
            $this->row('WhatsAppNotification', ['id' => 'n2', 'subscriptionId' => 's1', 'direction' => 'out', 'cardId' => 'c2',
                'createdAt' => '2026-11-16 18:00:00', 'status' => 'read']),
            // Failed: may go again.
            $this->row('WhatsAppNotification', ['id' => 'n3', 'subscriptionId' => 's1', 'direction' => 'out', 'cardId' => 'c3',
                'createdAt' => '2026-11-19 12:00:00', 'status' => 'failed']),
            // Just over 4 days ago: outside the window, may come again.
            $this->row('WhatsAppNotification', ['id' => 'n4', 'subscriptionId' => 's1', 'direction' => 'out', 'cardId' => 'c4',
                'createdAt' => '2026-11-15 14:04:59', 'status' => 'read']),
            // Another workspace of the same person, 2 days ago: inside 4-day window.
            $this->row('WhatsAppNotification', ['id' => 'n5', 'subscriptionId' => 's2', 'direction' => 'out', 'cardId' => 'c5',
                'createdAt' => '2026-11-17 12:00:00', 'status' => 'delivered']),
        ];
        $taps = $this->createMock(Taps::class);
        $taps->expects($this->once())->method('next')
            ->with($here, 'card', 'push:s1:2026-11-19 11:00', false, null, ['service:conv1', 'deals:deal1', 'service:conv5'])->willReturn(true);
        // Configured push times still work; the default is one a day at 08:00 (the user, 2026-11-26).
        $pushes = $this->pushes($em, $this->workspacesAt('2026-11-19 11:05'), $taps, ['08:00', '11:00', '15:00']);
        $pushes->morning($here);
        $pushes->morning($here);
        $this->assertSame('2026-11-19 11:00', $here->get('lastPushSlot'));
    }

    /** Even without an explicit exclude list (e.g. "em dia" or "Enviar agora"), Deck::next enforces the 4-day window. */
    public function testDeckNextSkipsCardsSentWithinFourDaysByDefault(): void
    {
        $em = $this->em($this->user());
        $em->method('getPDO')->willReturn($this->pdo([]));
        $here = $this->row('WhatsAppNotificationSubscription', ['id' => 's1', 'userId' => 'u1', 'chatwootAccountId' => 'a1',
            'phone' => self::PHONE, 'status' => 'active']);
        $this->store['WhatsAppNotificationSubscription'] = [$here];
        $this->store['WhatsAppDeckCard'] = [
            $this->row('WhatsAppDeckCard', ['id' => 'c1', 'lane' => 'service', 'subjectId' => 'conv1']),
            $this->row('WhatsAppDeckCard', ['id' => 'c2', 'lane' => 'service', 'subjectId' => 'conv2']),
        ];
        $this->store['WhatsAppNotification'] = [
            // Sent 3 days ago: skipped.
            $this->row('WhatsAppNotification', ['id' => 'n1', 'subscriptionId' => 's1', 'direction' => 'out', 'cardId' => 'c1',
                'createdAt' => '2026-11-16 14:05:00', 'status' => 'delivered']),
            // Sent 5 days ago: allowed.
            $this->row('WhatsAppNotification', ['id' => 'n2', 'subscriptionId' => 's1', 'direction' => 'out', 'cardId' => 'c2',
                'createdAt' => '2026-11-14 14:05:00', 'status' => 'delivered']),
        ];
        $workspaces = $this->createMock(Workspaces::class);
        $workspaces->method('of')->willReturn(['accountNumber' => 6, 'timeZone' => 'America/Sao_Paulo', 'language' => 'pt_BR', 'frozen' => false]);
        $workspaces->method('now')->willReturn(new DateTimeImmutable('2026-11-19 11:05', new DateTimeZone('America/Sao_Paulo')));
        $deals = $this->createMock(Feed::class);
        $deals->method('list')->willReturn(['items' => [], 'cursor' => null]);
        $service = $this->createMock(ServiceFeed::class);
        $service->method('list')->willReturn([
            'items' => [
                ['id' => 'conv1', 'verdict' => 'customer_waiting', 'sort' => [0, 1]],
                ['id' => 'conv2', 'verdict' => 'customer_waiting', 'sort' => [0, 2]],
            ],
            'cursor' => null,
        ]);
        $seen = [];
        $service->method('snapshot')->willReturnCallback(function (string $id) use (&$seen): array {
            $seen[] = $id;
            return ['revision' => 'r1', 'snapshot' => 'tok', 'contactName' => 'Sara', 'conversation' => ['accountId' => 6, 'conversationId' => 42]];
        });
        $summary = $this->createMock(Summary::class);
        $summary->method('analyse')->willReturn(['status' => 'pending']);
        $actors = $this->createMock(ActorScope::class);
        $actors->method('run')->willReturnCallback(fn (string $userId, callable $fn) => $fn(fn (string $class) => match ($class) {
            Feed::class => $deals,
            ServiceFeed::class => $service,
            Summary::class => $summary,
        }));
        $deck = new Deck($em, $actors, $workspaces, $this->createMock(Log::class));
        $deck->next($here);
        $this->assertSame(['conv2'], $seen);
    }

    /**
     * Cards waiting 24 hours or more go first, in Em dia's order (waiting on us, then follow-ups); the newer ones only
     * after them, even though Em dia puts a customer waiting for a few hours ahead of one waiting for days.
     */
    public function testDeckNextPrefersCardsWaitingMoreThanADay(): void
    {
        $em = $this->em($this->user());
        $here = $this->row('WhatsAppNotificationSubscription', ['id' => 's1', 'userId' => 'u1', 'chatwootAccountId' => 'a1',
            'phone' => self::PHONE, 'status' => 'active']);
        $this->store['WhatsAppNotificationSubscription'] = [$here];
        $ago = fn (int $hours) => gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $em->method('getPDO')->willReturn($this->pdo([
            ['id' => 'recent', 'customer_at' => $ago(2), 'last_at' => $ago(2)],
            ['id' => 'nine_days', 'customer_at' => $ago(9 * 24), 'last_at' => $ago(9 * 24)],
            // A follow-up counts from the last message, not the customer's.
            ['id' => 'follow_up', 'customer_at' => $ago(1), 'last_at' => $ago(3 * 24)],
            ['id' => 'also_recent', 'customer_at' => $ago(20), 'last_at' => $ago(20)],
        ]));
        $workspaces = $this->createMock(Workspaces::class);
        $workspaces->method('of')->willReturn(['accountNumber' => 6, 'timeZone' => 'America/Sao_Paulo', 'language' => 'pt_BR', 'frozen' => false]);
        $workspaces->method('now')->willReturn(new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')));
        $deals = $this->createMock(Feed::class);
        $deals->method('list')->willReturn(['items' => [], 'cursor' => null]);
        $service = $this->createMock(ServiceFeed::class);
        $service->method('list')->willReturn([
            'items' => [
                ['id' => 'also_recent', 'verdict' => 'customer_waiting', 'sort' => [0, 1]],
                ['id' => 'recent', 'verdict' => 'customer_waiting', 'sort' => [0, 2]],
                ['id' => 'nine_days', 'verdict' => 'customer_waiting', 'sort' => [1, -1]],
                ['id' => 'follow_up', 'verdict' => 'follow_up_due', 'sort' => [2, -1]],
            ],
            'cursor' => null,
        ]);
        $seen = [];
        $service->method('snapshot')->willReturnCallback(function (string $id) use (&$seen): array {
            $seen[] = $id;
            return ['revision' => 'r1', 'snapshot' => 'tok', 'contactName' => 'Sara', 'conversation' => ['accountId' => 6, 'conversationId' => 42]];
        });
        $summary = $this->createMock(Summary::class);
        $summary->method('analyse')->willReturn(['status' => 'pending']);
        $actors = $this->createMock(ActorScope::class);
        $actors->method('run')->willReturnCallback(fn (string $userId, callable $fn) => $fn(fn (string $class) => match ($class) {
            Feed::class => $deals,
            ServiceFeed::class => $service,
            Summary::class => $summary,
        }));
        (new Deck($em, $actors, $workspaces, $this->createMock(Log::class)))->next($here);
        $this->assertSame(['nine_days', 'follow_up', 'also_recent', 'recent'], $seen);
    }

    public function testThePushTimeIsTheLatestReachedWithinTheHour(): void
    {
        $at = fn (string $time, array $times = Settings::DEFAULT_PUSH_TIMES) => Pushes::slot(new DateTimeImmutable("2026-11-19 {$time}", new DateTimeZone('America/Sao_Paulo')), $times);
        // The default: one card a day at 08:00 (the user, 2026-11-26).
        $this->assertSame(['08:00'], Settings::DEFAULT_PUSH_TIMES);
        $this->assertNull($at('07:59'));
        $this->assertSame('08:00', $at('08:00'));
        $this->assertSame('08:00', $at('09:00'));
        $this->assertNull($at('09:01'));
        $this->assertNull($at('11:30'));
        $this->assertNull($at('15:59'));
        // Several configured times: the latest one reached within the hour.
        $three = ['08:00', '11:00', '15:00'];
        $this->assertSame('11:00', $at('11:30', $three));
        $this->assertSame('15:00', $at('15:59', $three));
        $this->assertNull($at('16:01', $three));
        $this->assertSame('8h', Copy::times('pt_BR', Settings::DEFAULT_PUSH_TIMES));
        $this->assertSame('8:00', Copy::times('en_US', Settings::DEFAULT_PUSH_TIMES));
        $this->assertSame('8h, 11h e 15h', Copy::times('pt_BR', $three));
        $this->assertSame('8h30', Copy::times('pt_BR', ['08:30']));
        $this->assertSame('8:00, 11:00 and 15:00', Copy::times('en_US', $three));
    }

    public function testNamesReadAsAList(): void
    {
        $this->assertSame('*A*', Copy::list('pt_BR', ['*A*']));
        $this->assertSame('*A* e *B*', Copy::list('pt_BR', ['*A*', '*B*']));
        $this->assertSame('*A*, *B* e mais 3', Copy::list('pt_BR', ['*A*', '*B*'], 3));
        $this->assertSame('*A*, *B* and *C*', Copy::list('en_US', ['*A*', '*B*', '*C*']));
    }
}
