<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityPostMentions;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Support/EntityDouble.php';

class OpportunityAiMentionsTest extends TestCase
{
    private OpportunityPostMentions $mentions;
    private Note $note;
    private bool $canReadMembership = true;
    private OpportunityPostMentions $realMentions;
    private EntityDouble $parent;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManager::class);
        $account = new EntityDouble(['id' => 'account', 'platformId' => 'platform', 'tenantId' => 'tenant', 'chatwootAccountId' => 1]);
        $opportunity = $this->parent = new EntityDouble(['tenantId' => 'tenant']);
        $memberships = [];
        $identities = [];
        // Two AI identities owned by the same CRM user exercise the lossy-ID mapping case.
        foreach ([7, 8] as $platformId) {
            $memberships[] = new EntityDouble(['id' => "ai-$platformId", 'chatwootUserId' => "cw-$platformId", 'isAI' => true, 'chatwootAccountId' => 'account']);
            $identities["cw-$platformId"] = new EntityDouble(['chatwootUserId' => $platformId, 'assignedUserId' => 'ai-user', 'platformId' => 'platform']);
        }
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => match ($type) {
            'Opportunity', 'Initiative', 'Task', 'Meeting', 'Call', 'Account', 'Contact' => $opportunity,
            'ChatwootAccountUserMembership' => array_values(array_filter($memberships, fn ($m) => $m->getId() === $id))[0] ?? null,
            'ChatwootAccount' => $account,
            'ChatwootUser' => $identities[$id] ?? null,
            default => null,
        });
        $accounts = $this->createMock(RDBRepository::class);
        $accountSelect = $this->createMock(RDBSelectBuilder::class);
        $accounts->method('where')->with(['tenantId' => 'tenant', 'chatwootAccountId' => 1])->willReturn($accountSelect);
        $accountSelect->method('limit')->with(0, 2)->willReturnSelf();
        $accountSelect->method('find')->willReturn(new EntityCollection([$account]));
        $members = $this->createMock(RDBRepository::class);
        $memberSelect = $this->createMock(RDBSelectBuilder::class);
        $members->method('join')->with('chatwootUser')->willReturn($memberSelect);
        $memberSelect->method('where')->with([
            'chatwootAccountId' => 'account', 'chatwootUser.platformId' => 'platform', 'isAI' => true,
        ])->willReturnSelf();
        $memberSelect->method('find')->willReturn(new EntityCollection($memberships));
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('ai-user');
        $user->method('isRegular')->willReturn(true);
        $users = $this->createMock(RDBRepository::class);
        $usersSelect = $this->createMock(RDBSelectBuilder::class);
        $users->method('where')->with(['id' => ['ai-user'], 'isActive' => true])->willReturn($usersSelect);
        $usersSelect->method('find')->willReturn(new EntityCollection([$user]));
        $em->method('getRDBRepository')->willReturnCallback(fn ($type) => match ($type) {
            'ChatwootAccount' => $accounts,
            'ChatwootAccountUserMembership' => $members,
            'User' => $users,
        });
        $acl = $this->createMock(Acl::class);
        $acl->method('check')->willReturnCallback(fn () => $this->canReadMembership);
        $acl->method('checkUserPermission')->willReturn(true);
        $manager = $this->createMock(AclManager::class);
        $manager->method('checkEntityRead')->willReturn(true);
        $manager->method('checkEntityStream')->willReturn(true);
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('canActForTenant')->willReturn(true);
        $this->realMentions = new OpportunityPostMentions($em, $acl, $manager, $tenants, $this->createMock(TenantResolver::class));
        $this->mentions = $this->getMockBuilder(OpportunityPostMentions::class)
            ->setConstructorArgs([$em, $acl, $this->createMock(AclManager::class),
                $this->createMock(UserTenantResolver::class), $this->createMock(TenantResolver::class)])
            ->onlyMethods(['resolve'])->getMock();
        // Author/ACL normalization is already covered separately. Here it deliberately
        // accepts the shared user so only exact mention selection can disambiguate AIs.
        $this->mentions->method('resolve')->with($this->anything(), false)->willReturn(['ai-user']);
        $defs = ['attributes' => []];
        foreach (['id', 'parentType', 'parentId', 'createdById', 'post', 'opportunityChatwootAccountId'] as $field) {
            $defs['attributes'][$field] = ['type' => 'varchar'];
        }
        $defs['attributes']['data'] = ['type' => 'jsonObject'];
        $defs['attributes']['opportunityMentionUserIds'] = ['type' => 'jsonArray'];
        $this->note = new Note('Note', $defs);
        $this->note->set([
            'id' => 'note', 'parentType' => 'Opportunity', 'parentId' => 'opp', 'createdById' => 'human', 'opportunityChatwootAccountId' => 1,
            'opportunityMentionUserIds' => ['ai-user'], 'data' => (object) [],
        ]);
    }

    public function testDirectMentionSelectsOnlyThatChatwootIdentity(): void
    {
        $this->note->setPost('[@Assistant](mention://user/7/Assistant) What next?');
        $targets = $this->mentions->resolveAiTargets($this->note);
        self::assertSame(['ai-7'], array_column($targets, 'aiAgentMembershipId'));
    }

    public function testTeamMentionDoesNotWakeAiMembersEvenWhenNormalizedIdsContainThem(): void
    {
        $this->note->setPost('[@Team](mention://team/7/Team) Help');
        self::assertSame([], $this->mentions->resolveAiTargets($this->note));
    }

    public function testNativeParserSeeingALinkLabelDoesNotSelectAnotherAi(): void
    {
        $this->note->setPost('[@ai-user](mention://user/7/Assistant) Help');
        $this->note->setData((object) ['mentions' => (object) [
            '@ai-user' => (object) ['_scope' => 'User', 'id' => 'ai-user'],
        ]]);
        self::assertSame(['ai-7'], array_column($this->mentions->resolveAiTargets($this->note), 'aiAgentMembershipId'));
    }

    public function testMultipleExplicitMentionsProduceOneTargetEach(): void
    {
        $this->note->setPost('[@A](mention://user/7/A) [@B](mention://user/8/B) [@A](mention://user/7/A)');
        self::assertSame(['ai-7', 'ai-8'], array_column($this->mentions->resolveAiTargets($this->note), 'aiAgentMembershipId'));
    }

    public function testAiAuthoredPostsCannotTriggerAPeer(): void
    {
        $this->note->setPost('[@Assistant](mention://user/8/Assistant)');
        $this->note->set('createdById', 'ai-user');
        self::assertSame([], $this->mentions->resolveAiTargets($this->note));
    }

    public function testMembershipReferencesSelectTheExactAiAcrossAllStreamTypes(): void
    {
        foreach (['Opportunity', 'Initiative', 'Task', 'Meeting', 'Call', 'Account', 'Contact'] as $type) {
            $this->note->set('parentType', $type);
            $this->note->setPost('[Assistant](#crm-reference/v1/record/ChatwootAccountUserMembership/ai-7) Help');
            self::assertSame(['ai-7'], array_column($this->mentions->resolveAiTargets($this->note), 'aiAgentMembershipId'));
        }
        $this->canReadMembership = false;
        self::assertSame([], $this->mentions->resolveAiTargets($this->note));
    }

    public function testMembershipReferencesNormalizeToAuthorizedUsersWithoutCrossAccountOrTenantAccess(): void
    {
        $this->note->setPost('[Assistant](#crm-reference/v1/record/ChatwootAccountUserMembership/ai-7) Help');
        self::assertSame(['ai-user'], $this->realMentions->resolve($this->note));
        self::assertSame(['ai-7'], array_column($this->realMentions->resolveAiTargets($this->note), 'aiAgentMembershipId'));
        $this->note->set('opportunityChatwootAccountId', 2);
        self::assertSame([], $this->realMentions->resolve($this->note));
        $this->note->set('opportunityChatwootAccountId', 1);
        $this->parent->set('tenantId', 'other');
        self::assertSame([], $this->realMentions->resolve($this->note));
    }
}
