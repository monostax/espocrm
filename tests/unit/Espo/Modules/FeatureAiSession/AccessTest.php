<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\FeatureAiSession;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureAiSession\Services\Access;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Chatwoot/Support/EntityDouble.php';

class AccessTest extends TestCase
{
    private Access $access;
    private User $owner;
    private EntityDouble $session;
    private array $records;
    private bool $tenantAllowed = true;
    private bool $accountAllowed = true;
    private bool $member = true;
    private bool $fieldsAllowed = true;

    protected function setUp(): void
    {
        $this->owner = $this->createMock(User::class);
        $this->owner->method('isActive')->willReturn(true);
        $this->owner->method('isRegular')->willReturn(true);
        $this->owner->method('getId')->willReturn('owner');
        $ai = $this->createMock(User::class);
        $ai->method('isActive')->willReturn(true);
        $this->session = new EntityDouble(['assignedUserId' => 'owner', 'tenantId' => 'tenant', 'chatwootAccountId' => 'crm-account']);
        $this->records = [
            'ChatwootAccount/crm-account' => new EntityDouble(['id' => 'crm-account', 'tenantId' => 'tenant', 'platformId' => 'platform']),
            'ChatwootAccountUserMembership/agent' => new EntityDouble(['id' => 'agent', 'chatwootAccountId' => 'crm-account', 'chatwootUserId' => 'identity', 'isAI' => true]),
            'ChatwootUser/identity' => new EntityDouble(['assignedUserId' => 'ai', 'platformId' => 'platform']), 'User/ai' => $ai,
        ];
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $this->records["$type/$id"] ?? null);
        $repo = $this->createMock(RDBRepository::class);
        $select = $this->createMock(RDBSelectBuilder::class);
        $em->method('getRDBRepository')->willReturn($repo);
        $repo->method('join')->willReturn($select);
        $select->method('where')->willReturnSelf();
        $select->method('findOne')->willReturnCallback(fn () => $this->member ? new EntityDouble(['id' => 'human-membership']) : null);
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('canActForTenant')->willReturnCallback(fn () => $this->tenantAllowed);
        $acl = $this->createMock(AclManager::class);
        $acl->method('checkEntityRead')->willReturnCallback(fn () => $this->accountAllowed);
        $acl->method('checkField')->willReturnCallback(fn () => $this->fieldsAllowed);
        $this->access = new Access($em, $tenants, $acl);
    }

    public function testOwnerNeedsLiveTenantAccountAndMembership(): void
    {
        self::assertTrue($this->access->owner($this->owner, $this->session));
        $this->tenantAllowed = false;
        self::assertFalse($this->access->owner($this->owner, $this->session));
        $this->tenantAllowed = true;
        $this->accountAllowed = false;
        self::assertFalse($this->access->owner($this->owner, $this->session));
        $this->accountAllowed = true;
        $this->member = false;
        self::assertFalse($this->access->owner($this->owner, $this->session));
    }

    public function testBroadRecordRoleDoesNotGrantAnotherUsersSession(): void
    {
        $this->session->set('assignedUserId', 'someone-else');
        self::assertFalse($this->access->owner($this->owner, $this->session));
    }

    public function testAccountTenantMoveRevokesSession(): void
    {
        $this->records['ChatwootAccount/crm-account']->set('tenantId', 'foreign');
        self::assertFalse($this->access->owner($this->owner, $this->session));
    }

    public function testExactMembershipIsValidated(): void
    {
        self::assertSame('agent', $this->access->agent($this->owner, $this->session, 'agent')->getId());
        $this->records['ChatwootAccountUserMembership/agent']->set('chatwootAccountId', 'other');
        $this->expectException(Forbidden::class);
        $this->access->agent($this->owner, $this->session, 'agent');
    }

    public function testForeignPlatformIdentityIsRejected(): void
    {
        $this->records['ChatwootUser/identity']->set('platformId', 'other');
        $this->expectException(Forbidden::class);
        $this->access->agent($this->owner, $this->session, 'agent');
    }

    public function testHiddenAgentFieldsCannotBeDisclosedByRecipientApi(): void
    {
        $this->fieldsAllowed = false;
        $this->expectException(Forbidden::class);
        $this->access->agent($this->owner, $this->session, 'agent');
    }
}
