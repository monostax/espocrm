<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureAiUsage\Services\Access as BillingAccess;
use Espo\Modules\FeatureSelfService\Tools\Plans\SeatAccess;
use Espo\Modules\FeatureSelfService\Tools\Plans\SeatRoster;
use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeatAccessTest extends TestCase
{
    #[DataProvider('refusals')]
    public function testRefusesBeforeAnyLocalOrRemoteAccessMutation(bool $authorized, string $target, bool $enabled,
        bool $pending, int $paid, int $used, string $exception, string $message): void
    {
        $billing = $this->createMock(BillingAccess::class);
        if (!$authorized) $billing->method('assertTenant')->willThrowException(new Forbidden('Tenant administrator access is required.'));
        $tenant = $this->createStub(Entity::class);
        $tenant->method('get')->willReturn('monostax');
        $user = $this->createStub(User::class);
        $roster = $this->createStub(SeatRoster::class);
        $roster->method('read')->willReturn(['users' => [['id' => 'customer'], ['id' => 'actor']],
            'enabledUsers' => $used, 'paidSeats' => $paid, 'pending' => $pending]);
        $seat = $this->createMock(Entity::class);
        $seat->method('get')->willReturnCallback(fn ($field) => $field === 'disabled' ? $enabled : null);
        $seat->expects(self::never())->method('set');
        $select = $this->createStub(RDBSelectBuilder::class);
        $select->method('findOne')->willReturn($seat);
        $repo = $this->createStub(RDBRepository::class);
        $repo->method('where')->willReturn($select);
        $transaction = $this->createStub(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(fn ($fn) => $fn());
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('tenant');
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->method('getEntityById')->willReturnCallback(fn ($type) => $type === 'Tenant' ? $tenant : $user);
        $em->method('getRDBRepository')->willReturn($repo);
        $em->expects(self::never())->method('saveEntity');
        $remote = $this->createMock(ChatwootApiClient::class);
        $remote->expects(self::never())->method('setAccountUserBillingAccess');
        $service = new SeatAccess($em, $roster, $remote, $this->createStub(UserTenantResolver::class),
            $this->createStub(TenantAdminTeamProvisioner::class), $billing, $this->createStub(Log::class));
        $this->expectException($exception);
        $this->expectExceptionMessage($message);
        $service->change('tenant', $target, $enabled, 'actor');
    }

    public static function refusals(): array
    {
        return [
            'not billing admin' => [false, 'customer', false, false, 0, 3, Forbidden::class, 'Tenant administrator'],
            'foreign user' => [true, 'foreign', false, false, 0, 3, Forbidden::class, 'not a customer seat'],
            'self disable' => [true, 'actor', false, false, 0, 3, Conflict::class, 'CANNOT_DISABLE_SELF'],
            'trial cannot restore' => [true, 'customer', true, false, 0, 2, Conflict::class, 'PAID_SEAT_REQUIRED'],
            'paid capacity full' => [true, 'customer', true, false, 2, 2, Conflict::class, 'PAID_SEAT_REQUIRED'],
            'partial remote sync cannot reverse' => [true, 'customer', true, true, 3, 2, Conflict::class, 'SEAT_ACCESS_PENDING'],
        ];
    }
}
