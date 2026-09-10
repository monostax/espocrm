<?php

namespace tests\unit\Espo\Modules\Chatwoot\Hooks\User;

use Espo\Core\ORM\Helper;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Hooks\User\ProvisionChatwootMemberships;
use Espo\Modules\Chatwoot\Jobs\ProvisionUserMemberships;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class ProvisionChatwootMembershipsTest extends TestCase
{
    private function user(bool $new = true): User
    {
        $user = new User('User', ['attributes' => array_fill_keys([
            'id', 'type', 'isActive', 'emailAddress', 'password', 'teamsIds',
        ], ['type' => 'varchar'])], $this->createMock(EntityManager::class), $this->createMock(Helper::class));
        $user->set(['id' => 'u', 'type' => 'regular', 'isActive' => true, 'emailAddress' => 'a@example.com']);
        if (!$new) {
            $user->setAsFetched();
        }
        return $user;
    }

    private function hook(int $expected): ProvisionChatwootMemberships
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->exactly($expected))->method('createEntity')
            ->with('Job', $this->callback(function ($values) {
                return $values['className'] === ProvisionUserMemberships::class &&
                    $values['attempts'] === 5 && $values['data']->userId === 'u';
            }))->willReturn($this->createMock(Entity::class));
        return new ProvisionChatwootMemberships($em);
    }

    public function testOrmCreationQueuesProvisioningEvenWithSilentSave(): void
    {
        $this->hook(1)->afterSave($this->user(), ['silent' => true]);
    }

    public function testTenantAssignedTeamQueuesProvisioning(): void
    {
        $this->hook(1)->afterRelate($this->user(false), ['tenantMembershipSyncInternal' => true], [
            'relationName' => 'teams', 'foreignId' => 'tenant-base-team',
        ]);
    }

    public function testEmailAndTeamEditsQueueProvisioning(): void
    {
        $hook = $this->hook(2);
        $user = $this->user(false);
        $user->set('emailAddress', 'new@example.com');
        $hook->afterSave($user, []);
        $user = $this->user(false);
        $user->set('teamsIds', ['team']);
        $hook->afterSave($user, []);
    }

    public function testPasswordSyncAndRebuildDoNotRequeueProvisioning(): void
    {
        $user = $this->user(false);
        $user->set('password', 'hash');
        $hook = $this->hook(0);
        $hook->afterSave($user, []);
        $hook->afterSave($this->user(), ['skipChatwootProvisioning' => true]);
    }
}
