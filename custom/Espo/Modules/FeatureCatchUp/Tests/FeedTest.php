<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\DataCache;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityActivityBuckets;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\Modules\Chatwoot\Services\OpportunityThreadState;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Rollout;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;

class FeedTest extends TestCase
{
    private function fixture(bool $allowed = true): array
    {
        $user = $this->createMock(User::class);
        $user->method('isRegular')->willReturn($allowed);
        $user->method('getId')->willReturn('user-a');
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkField')->willReturn(true);
        $acl->method('checkEntityRead')->willReturn(true);
        $states = $this->createMock(OpportunityReadStateService::class);
        $threads = $this->createMock(OpportunityThreadState::class);
        $cache = $this->createMock(DataCache::class);
        $em = $this->createMock(EntityManager::class);
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->method('run')->willReturnCallback(fn ($work) => $work());
        $em->method('getTransactionManager')->willReturn($transactions);
        $service = new Feed($em, $this->createMock(SelectBuilderFactory::class), $acl, $user,
            $this->createMock(UserTenantResolver::class), $states, $threads,
            $this->createMock(OpportunityEventAccess::class),
            $this->createMock(OpportunityActivityBuckets::class), $cache, $this->createMock(Rollout::class));
        return [$service, $states, $threads, $cache, $em];
    }

    public function testPortalCannotReadOrReviewSnapshots(): void
    {
        [$service, $states, , $cache] = $this->fixture(false);
        $states->expects(self::never())->method('getReadState');
        $cache->expects(self::never())->method('tryGet');
        $this->expectException(Forbidden::class);
        $service->review('opportunity', 'token');
    }

    public function testCachedSnapshotNeverBypassesCurrentRecordAccess(): void
    {
        [$service, $states, , $cache] = $this->fixture();
        $states->method('getReadState')->willThrowException(new Forbidden());
        $cache->expects(self::never())->method('tryGet');
        $this->expectException(Forbidden::class);
        $service->review('opportunity', 'token');
    }

    public function testInterveningMarkUnreadWinsOverOldCard(): void
    {
        [$service, $states, $threads, $cache] = $this->fixture();
        $states->method('getReadState')->willReturn(['version' => 8]);
        $cache->method('tryGet')->willReturn(['token' => 'token', 'expires' => time() + 60, 'review' => ['version' => 7]]);
        $states->expects(self::never())->method('markRead');
        $threads->expects(self::never())->method('markRead');
        $this->expectException(Conflict::class);
        $service->review('opportunity', 'token');
    }

    public function testReviewUsesOnlyDisplayedPostAndThreadCutoffs(): void
    {
        [$service, $states, $threads, $cache, $em] = $this->fixture();
        $states->method('getReadState')->willReturn(['version' => 7]);
        $cache->expects(self::once())->method('tryGet')
            ->with('catchUp/snapshots/' . hash('sha256', 'user-a:opportunity'))
            ->willReturn(['token' => 'token', 'expires' => time() + 60, 'review' => [
                'lastPostId' => 'displayed-post', 'version' => 7,
                'threads' => ['root' => ['number' => 42, 'version' => 3]],
            ]]);
        $root = $this->createMock(Note::class);
        $root->method('get')->with('parentId')->willReturn('opportunity');
        $em->method('getEntityById')->with('Note', 'root')->willReturn($root);
        $states->expects(self::once())->method('markRead')->with('opportunity', 'displayed-post', 7)->willReturn([]);
        $threads->expects(self::once())->method('markRead')->with($root, 42, 3)->willReturn([]);
        $threads->expects(self::never())->method('markAllRead');
        $service->review('opportunity', 'token');
    }
}
