<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\FeatureCatchUp\Services\Snoozes;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class SnoozesTest extends TestCase
{
    private function service(bool $readable = true, string $recordTenant = 'tenant'): array
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntityRead')->willReturn($readable);
        $record = $this->createMock(Entity::class);
        $record->method('get')->willReturnCallback(fn ($field) => $field === 'tenantId' ? $recordTenant : null);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($record);
        $access = $this->createMock(Access::class);
        $access->method('record')->willReturn($record);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user-a');
        return [new Snoozes($em, $acl, $user, $access), $em];
    }

    private function tenant(): Entity
    {
        $tenant = $this->createMock(Entity::class);
        $tenant->method('getId')->willReturn('tenant');
        return $tenant;
    }

    public function testPastOrFarFutureTimesAreRejected(): void
    {
        foreach (['-1 hour', '+31 days'] as $offset) {
            [$service, $em] = $this->service();
            $em->expects(self::never())->method('getTransactionManager');
            try {
                $service->snooze($this->tenant(), 'Opportunity', 'deal', gmdate(DATE_ATOM, strtotime($offset)));
                self::fail("Accepted $offset");
            } catch (BadRequest) {
            }
        }
    }

    public function testOnlySupportedReadableCardsCanBeSnoozed(): void
    {
        [$service] = $this->service();
        try {
            $service->snooze($this->tenant(), 'User', 'someone', gmdate(DATE_ATOM, strtotime('+1 day')));
            self::fail('Accepted an unsupported type');
        } catch (BadRequest) {
        }
        [$service, $em] = $this->service(false);
        $em->expects(self::never())->method('getTransactionManager');
        $this->expectException(Forbidden::class);
        $service->snooze($this->tenant(), 'Task', 'task', gmdate(DATE_ATOM, strtotime('+1 day')));
    }

    public function testAnotherWorkspacesDealCannotBeSnoozed(): void
    {
        [$service, $em] = $this->service(true, 'other-tenant');
        $em->expects(self::never())->method('getTransactionManager');
        $this->expectException(Forbidden::class);
        $service->snooze($this->tenant(), 'Opportunity', 'deal', gmdate(DATE_ATOM, strtotime('+1 day')));
    }
}
