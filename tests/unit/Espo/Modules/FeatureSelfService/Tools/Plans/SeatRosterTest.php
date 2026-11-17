<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureSelfService\Tools\Plans\PlanSubscription;
use Espo\Modules\FeatureSelfService\Tools\Plans\SeatRoster;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class SeatRosterTest extends TestCase
{
    public function testTenantWideRosterDeduplicatesHumansAndExcludesInternalAndConciergeUsers(): void
    {
        $roster = $this->roster()->read('tenant', 'one');
        self::assertSame(3, $roster['minimumSeats']);
        self::assertSame(['one', 'three', 'two'], array_column($roster['users'], 'id'));
        self::assertFalse($roster['users'][0]['canDisable']);
        self::assertTrue($roster['users'][0]['isSelf']);
    }

    public function testCheckoutRefusesFewerSeatsThanEnabledUsers(): void
    {
        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('SEATS_BELOW_ENABLED_USERS');
        $this->roster()->assertCheckout('tenant', 2);
    }

    public function testConfirmedDisablingLowersMinimumAndPaidCapacityPermitsRestoration(): void
    {
        $roster = $this->roster(true, true, 3);
        $roster->assertCheckout('tenant', 2);
        $view = $roster->read('tenant');
        self::assertSame(2, $view['minimumSeats']);
        self::assertTrue(array_column($view['users'], null, 'id')['two']['canEnable']);
        self::assertFalse(array_column($this->roster(true, true, 2)->read('tenant')['users'], null, 'id')['two']['canEnable']);
        self::assertFalse(array_column($this->roster(true, true)->read('tenant')['users'], null, 'id')['two']['canEnable']);
    }

    public function testPendingRemoteRevocationBlocksCheckoutEvenWithEnoughSeats(): void
    {
        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('SEAT_ACCESS_PENDING');
        $this->roster(true, false)->assertCheckout('tenant', 3);
    }

    private function roster(bool $disabled = false, bool $synced = false, int $paid = 0): SeatRoster
    {
        $members = [];
        foreach (['one', 'two', 'three', 'one', 'internal', 'concierge'] as $id) {
            $members[] = $this->entity('m-' . $id, ['chatwootAccountId' => 'account', 'chatwootUserId' => $id,
                'name' => $id, 'email' => $id . ($id === 'internal' ? '@app.MONOSTAX.AI' : '@customer.test')]);
        }
        $seatSelect = $this->createStub(RDBSelectBuilder::class);
        $seatSelect->method('find')->willReturn(new EntityCollection($disabled ? [
            $this->entity('seat', ['userId' => 'two', 'disabled' => $disabled, 'syncedDisabled' => $synced]),
        ] : []));
        $seatRepo = $this->createMock(RDBRepository::class);
        $seatRepo->method('where')->with(['tenantId' => 'tenant'])->willReturn($seatSelect);
        $memberSelect = $this->createMock(RDBSelectBuilder::class);
        $memberSelect->method('join')->willReturnSelf();
        $memberSelect->method('where')->with(['chatwootAccount.tenantId' => 'tenant', 'chatwootAccount.deleted' => false,
            'chatwootUser.deleted' => false, 'OR' => [['isAI' => false], ['isAI' => null]]])->willReturnSelf();
        $memberSelect->method('find')->willReturn(new EntityCollection($members));
        $memberRepo = $this->createStub(RDBRepository::class);
        $memberRepo->method('join')->willReturn($memberSelect);
        $em = $this->createStub(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(fn ($type) => $type === 'SelfServiceSeat' ? $seatRepo : $memberRepo);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $this->entity($id,
            $type === 'ChatwootAccount' ? ['conciergeUserId' => 'concierge'] : ['assignedUserId' => $id]));
        $subscription = $this->createStub(PlanSubscription::class);
        $subscription->method('read')->willReturn(['subscription' => $paid ? [
            'state' => 'active', 'seats' => $paid, 'paidThrough' => '2099-01-01 00:00:00',
        ] : null]);
        return new SeatRoster($em, $subscription);
    }

    private function entity(string $id, array $data): Entity
    {
        $entity = $this->createStub(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn ($field) => $data[$field] ?? null);
        return $entity;
    }
}
