<?php

namespace tests\unit\Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job\Data;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Jobs\ProvisionUserMemberships;
use Espo\Modules\Chatwoot\Services\ChatwootAccountMembershipOrchestrator;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class ProvisionUserMembershipsTest extends TestCase
{
    private function user(string $type = 'regular', bool $active = true, array $teams = ['current-team']): User
    {
        $user = $this->createMock(User::class);
        $user->method('getType')->willReturn($type);
        $user->method('isActive')->willReturn($active);
        $user->method('getLinkMultipleIdList')->with('teams')->willReturn($teams);
        return $user;
    }

    private function job(?User $user, array $accounts, ChatwootAccountMembershipOrchestrator $orchestrator): ProvisionUserMemberships
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('User', 'u')->willReturn($user);
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('where')->with(['teams.id' => ['current-team'], 'status' => 'active'])->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $select->method('find')->willReturn(new EntityCollection($accounts));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('join')->with('teams')->willReturn($select);
        $em->method('getRDBRepository')->with('ChatwootAccount')->willReturn($repository);
        return new ProvisionUserMemberships($em, $orchestrator);
    }

    public function testProvisionsEveryMatchingAccountWithRolePreservation(): void
    {
        $user = $this->user();
        $accounts = [$this->createMock(Entity::class), $this->createMock(Entity::class)];
        $seen = [];
        $orchestrator = $this->createMock(ChatwootAccountMembershipOrchestrator::class);
        $orchestrator->expects($this->exactly(2))->method('ensureUserMembership')
            ->willReturnCallback(function ($account, $actualUser, $role) use (&$seen, $user) {
                $this->assertSame($user, $actualUser);
                $this->assertNull($role);
                $seen[] = $account;
                return $this->createMock(Entity::class);
            });
        $this->job($user, $accounts, $orchestrator)->run(Data::create(['userId' => 'u', 'teamsIds' => ['stale-team']]));
        $this->assertSame($accounts, $seen);
    }

    public function testOneFailingAccountDoesNotBlockOthersAndFailureIsRetryable(): void
    {
        $accounts = [$this->createMock(Entity::class), $this->createMock(Entity::class)];
        $orchestrator = $this->createMock(ChatwootAccountMembershipOrchestrator::class);
        $orchestrator->expects($this->exactly(2))->method('ensureUserMembership')
            ->willReturnCallback(function ($account) use ($accounts) {
                if ($account === $accounts[0]) {
                    throw new \RuntimeException('Platform unavailable');
                }
                return $this->createMock(Entity::class);
            });
        $this->expectExceptionMessage('Platform unavailable');
        $this->job($this->user(), $accounts, $orchestrator)->run(Data::create(['userId' => 'u']));
    }

    public function testDoesNotProvisionDeletedInactiveExternalOrTeamlessUsers(): void
    {
        foreach ([null, $this->user(active: false), $this->user('portal'), $this->user('api'),
            $this->user('system'), $this->user(teams: [])] as $user) {
            $orchestrator = $this->createMock(ChatwootAccountMembershipOrchestrator::class);
            $orchestrator->expects($this->never())->method('ensureUserMembership');
            $this->job($user, [], $orchestrator)->run(Data::create(['userId' => 'u']));
        }
    }
}
