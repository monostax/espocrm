<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCatchUp\Services\Rollout;
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
        $verified = $subscriptions->activate('u1', 'a1', self::PHONE, 'settings', 77, 88);
        $verified->set(['morningTime' => '07:30', 'hotLeadsEnabled' => false]);

        $spread = $this->subscriptionIn('a2');
        $this->assertNotNull($spread);
        $this->assertSame(['active', self::PHONE, 'person', 77, 88, 'ta2', 2],
            [$spread->get('status'), $spread->get('phone'), $spread->get('source'), $spread->get('avisosContactId'),
                $spread->get('avisosConversationId'), $spread->get('tenantId'), $spread->get('accountNumber')]);
        $this->assertSame($verified->get('lastInboundAt'), $spread->get('lastInboundAt'), 'the 24h window is the number\'s');
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
        $this->subscriptions($em)->activate('u1', 'a1', self::PHONE, 'settings', null, null);
        $this->assertSame(['active', self::PHONE, null, false, '10:00', 'settings'],
            [$old->get('status'), $old->get('phone'), $old->get('stopReason'), $old->get('morningEnabled'), $old->get('morningTime'), $old->get('source')]);
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
            $this->createMock(ChatwootApiClient::class), $this->createMock(Log::class));
        $pushes->hotLeads($here);
    }

    public function testTheWelcomeNamesEveryWorkspace(): void
    {
        $this->assertSame('*A*', Copy::list('pt_BR', ['*A*']));
        $this->assertSame('*A* e *B*', Copy::list('pt_BR', ['*A*', '*B*']));
        $this->assertSame('*A*, *B* e mais 3', Copy::list('pt_BR', ['*A*', '*B*'], 3));
        $this->assertSame('*A*, *B* and *C*', Copy::list('en_US', ['*A*', '*B*', '*C*']));
        $this->assertStringContainsString('Seu Em dia de *A* e *B* agora chega aqui', Copy::t('pt_BR', 'welcome', ['name' => 'X', 'workspace' => '*A* e *B*']));
    }
}
