<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tests;

use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\Rollout;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\Modules\FeatureSelfService\Tools\Offboarding\TenantDataMap;
use Espo\Modules\FeatureWhatsAppNotifications\Hooks\WhatsAppNotificationSubscription\RemoveCards;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Avisos;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\PhoneCodes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Subscriptions;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Workspaces;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Update;
use Espo\ORM\QueryBuilder;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\Executor\QueryExecutor;
use PHPUnit\Framework\TestCase;

/** What happens to Avisos when a workspace goes (2026-11-13: a subscription outlived its offboarded workspace). */
class CascadeTest extends TestCase
{
    private function entity(string $id, array $values = []): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn (string $name) => $values[$name] ?? null);
        return $entity;
    }

    public function testOffboardingSweepsSubscriptionsByTheirTenantLink(): void
    {
        $defs = json_decode((string) file_get_contents(__DIR__ . '/../Resources/metadata/entityDefs/WhatsAppNotificationSubscription.json'), true);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(fn ($path) => match ($path) {
            ['scopes'] => ['WhatsAppNotificationSubscription' => ['entity' => true]],
            ['entityDefs', 'WhatsAppNotificationSubscription'] => $defs,
            ['entityDefs', 'WhatsAppNotificationSubscription', 'links'] => $defs['links'],
            default => null,
        });
        $this->assertContains(['entityType' => 'WhatsAppNotificationSubscription', 'column' => 'tenantId'],
            (new TenantDataMap($metadata))->tenantScopedTypes());
    }

    public function testARemovedSubscriptionTakesItsCards(): void
    {
        $executed = null;
        $executor = $this->createMock(QueryExecutor::class);
        $executor->expects($this->once())->method('execute')->willReturnCallback(function ($query) use (&$executed) {
            $executed = $query;
            return $this->createMock(\PDOStatement::class);
        });
        $em = $this->createMock(EntityManager::class);
        $em->method('getQueryBuilder')->willReturn(new QueryBuilder());
        $em->method('getQueryExecutor')->willReturn($executor);
        (new RemoveCards($em))->afterRemove($this->entity('sub1'), RemoveOptions::fromAssoc([]));
        $this->assertInstanceOf(Update::class, $executed);
        $this->assertSame('WhatsAppDeckCard', $executed->getIn());
        $this->assertSame(['deleted' => true], $executed->getSet());
        $this->assertSame(['subscriptionId' => 'sub1', 'deleted' => false], $executed->getWhere()?->getRaw());
    }

    public function testAWorkspaceIsGoneWithoutItsAccountOrTenant(): void
    {
        $subscription = $this->entity('sub1', ['chatwootAccountId' => 'acct1']);
        $noAccount = $this->createMock(EntityManager::class);
        $noAccount->method('getEntityById')->willReturn(null);
        $this->assertFalse((new Workspaces($noAccount, $this->createMock(UserTenantResolver::class)))->exists($subscription));

        $noTenant = $this->createMock(EntityManager::class);
        $noTenant->method('getEntityById')->willReturnCallback(fn (string $type) =>
            $type === 'ChatwootAccount' ? $this->entity('acct1', ['tenantId' => 'gone']) : null);
        $this->assertFalse((new Workspaces($noTenant, $this->createMock(UserTenantResolver::class)))->exists($subscription));
    }

    public function testANumberIgnoresSubscriptionsLeftByARemovedWorkspace(): void
    {
        $live = $this->entity('live', ['chatwootAccountId' => 'a1']);
        $orphan = $this->entity('orphan', ['chatwootAccountId' => 'a2']);
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('order')->willReturnSelf();
        $select->method('find')->willReturn(new \Espo\ORM\EntityCollection([$orphan, $live]));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($select);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repository);
        $workspaces = $this->createMock(Workspaces::class);
        $workspaces->method('live')->willReturnCallback(fn (Entity $subscription) => $subscription === $live);
        $subscriptions = new Subscriptions($em, $this->createMock(User::class), $this->createMock(Settings::class),
            $this->createMock(Workspace::class), $this->createMock(Rollout::class), $this->createMock(PhoneCodes::class),
            $this->createMock(Avisos::class), $workspaces);
        $this->assertSame([$live], $subscriptions->activeForPhone('+5511900000000'));
    }
}
