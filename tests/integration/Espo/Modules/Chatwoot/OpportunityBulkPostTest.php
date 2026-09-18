<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\Chatwoot;

use Espo\Core\Acl;
use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Database\Schema\SchemaManager;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Jobs\BroadcastOpportunityUpdate;
use Espo\Modules\Chatwoot\Jobs\ProcessOpportunityBulkPost;
use Espo\Modules\Chatwoot\Services\OpportunityBulkPost;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/** Uses a disposable installed database (data/config.php), real ACL, Note hooks and transactions. */
class OpportunityBulkPostTest extends TestCase
{
    private static bool $schemaReady = false;
    private Application $app;
    private EntityManager $em;
    private Entity $tenant;
    private Entity $account;
    private User $author;
    private Entity $role;

    protected function setUp(): void
    {
        if (getenv('ESPO_BULK_POST_TEST') !== '1') {
            self::markTestSkipped('Set ESPO_BULK_POST_TEST=1 only with a disposable data/config.php database.');
        }
        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        if (!self::$schemaReady) {
            $this->app->getInjectableFactory()->create(SchemaManager::class)->rebuild();
            self::$schemaReady = true;
        }
        $this->em = $this->app->getContainer()->getByClass(EntityManager::class);
        if (!$this->em->getRDBRepository('User')->where(['userName' => 'system'])->findOne()) {
            $this->create('User', ['userName' => 'system', 'type' => 'system', 'isActive' => true]);
        }
        $this->app->setupSystemUser();
        $team = $this->create('Team', ['name' => 'Bulk test']);
        $this->tenant = $this->create('Tenant', ['name' => 'Bulk test', 'baseUserTeamId' => $team->getId()]);
        $platform = $this->create('ChatwootPlatform', ['name' => 'Bulk test']);
        $this->account = $this->create('ChatwootAccount', [
            'name' => 'Bulk test', 'chatwootAccountId' => random_int(1000001, 2000000000),
            'platformId' => $platform->getId(), 'tenantId' => $this->tenant->getId(),
        ]);
        $this->role = $this->create('Role', ['name' => 'Bulk test', 'mentionPermission' => 'all', 'data' => (object) [
            'Opportunity' => (object) ['read' => 'all', 'stream' => 'all', 'create' => 'yes', 'edit' => 'all', 'delete' => 'all'],
            'Note' => (object) ['read' => 'all', 'create' => 'yes', 'edit' => 'own', 'delete' => 'own'],
            'ChatwootAccount' => (object) ['read' => 'all'],
            'User' => (object) ['read' => 'all'],
            'Team' => (object) ['read' => 'all'],
        ]]);
        $user = $this->create('User', [
            'userName' => 'bulk-' . bin2hex(random_bytes(6)), 'type' => 'regular', 'isActive' => true,
            'teamsIds' => [$team->getId()], 'rolesIds' => [$this->role->getId()],
        ]);
        $this->em->getRDBRepository('User')->getRelation($user, 'teams')->relate($team);
        $this->em->getRDBRepository('User')->getRelation($user, 'roles')->relate($this->role);
        $this->author = $this->em->getEntityById('User', $user->getId());
    }

    private function create(string $type, array $data): Entity
    {
        return $this->em->createEntity($type, $data, ['skipHooks' => true]);
    }

    private function actorApp(?User $actor = null): Application
    {
        $actor = $this->em->getEntityById('User', ($actor ?? $this->author)->getId());
        return new Application(new ApplicationParams(noErrorHandler: true, services: ['user' => $actor]));
    }

    private function service(?User $actor = null): OpportunityBulkPost
    {
        return $this->actorApp($actor)->getInjectableFactory()->create(OpportunityBulkPost::class);
    }

    private function accountId(): int
    {
        return (int) $this->account->get('chatwootAccountId');
    }

    private function opportunities(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->create('Opportunity', [
                'name' => "Bulk $i", 'tenantId' => $this->tenant->getId(), 'assignedUserId' => $this->author->getId(),
            ])->getId();
        }
        return $ids;
    }

    private function request(array $ids, string $post = '**Hello**'): object
    {
        return (object) ['ids' => $ids, 'post' => $post, 'idempotencyKey' => bin2hex(random_bytes(16))];
    }

    private function work(string $id, ?int $generation = null): void
    {
        $operation = $this->em->getEntityById(OpportunityBulkPost::OPERATION, $id);
        $job = $this->em->getEntityById('Job', $operation->get('jobId'));
        $job->set('status', 'Running');
        $this->em->saveEntity($job);
        $this->app->getInjectableFactory()->create(ProcessOpportunityBulkPost::class)
            ->run(Data::create(['operationId' => $id, 'generation' => $generation ?? $operation->get('generation')]));
        $job->set('status', 'Success');
        $this->em->saveEntity($job);
    }

    public function testChunksAreResumableAndDuplicateSubmissionsAndWorkerDeliveriesAreSafe(): void
    {
        $count = max(55, (int) getenv('ESPO_BULK_POST_SCALE'));
        $ids = $this->opportunities($count);
        $request = $this->request($ids);
        $service = $this->service();
        $operation = $service->submit($this->accountId(), $request);
        self::assertSame($operation->id, $service->submit($this->accountId(), $request)->id);
        $this->work($operation->id);
        self::assertSame(50, $service->status($this->accountId(), $operation->id)->succeeded);
        // Redeliver the original generation without consuming the continuation job.
        $this->app->getInjectableFactory()->create(ProcessOpportunityBulkPost::class)
            ->run(Data::create(['operationId' => $operation->id, 'generation' => 1]));
        while ($service->status($this->accountId(), $operation->id)->status !== 'Completed') {
            $this->work($operation->id);
            self::assertSame(0, $service->status($this->accountId(), $operation->id)->failed);
        }
        self::assertSame('Completed', $service->status($this->accountId(), $operation->id)->status);
        self::assertSame($count, $this->em->getRDBRepository('Note')->where(['parentId' => $ids, 'createdById' => $this->author->getId()])->count());
        self::assertSame($count, $this->em->getRDBRepository('OpportunityReadState')->where(['opportunityId' => $ids, 'userId' => $this->author->getId()])->count());
        $native = 0;
        $accountBroadcasts = 0;
        foreach ($this->em->getRDBRepository('Job')->where(['className' => BroadcastOpportunityUpdate::class])->find() as $job) {
            $data = $job->get('data');
            if (($data->tenantIds ?? []) !== [$this->tenant->getId()]) continue;
            if (isset($data->opportunityId)) {
                self::assertTrue($data->skipChatwoot);
                $native++;
            } else $accountBroadcasts++;
        }
        self::assertGreaterThanOrEqual($count, $native);
        self::assertSame((int) ceil($count / 50), $accountBroadcasts, 'One durable account invalidation per chunk.');
    }

    public function testRejectsMixedTenantsEvenForAnAdministrator(): void
    {
        $ids = $this->opportunities(2);
        $foreign = $this->create('Tenant', ['name' => 'Other tenant']);
        $opportunity = $this->em->getEntityById('Opportunity', $ids[1]);
        $opportunity->set('tenantId', $foreign->getId());
        $this->em->saveEntity($opportunity, ['skipHooks' => true]);
        $this->author->set('type', 'admin');
        $this->em->saveEntity($this->author, ['skipHooks' => true]);
        $this->expectException(Forbidden::class);
        $this->service()->submit($this->accountId(), $this->request($ids));
    }

    public function testMovedTargetsFailAndRetryOnlyPublishesTheUnsuccessfulTarget(): void
    {
        $ids = $this->opportunities(2);
        $service = $this->service();
        $operation = $service->submit($this->accountId(), $this->request($ids));
        $foreign = $this->create('Tenant', ['name' => 'Other tenant']);
        $moved = $this->em->getEntityById('Opportunity', $ids[1]);
        $moved->set('tenantId', $foreign->getId());
        $this->em->saveEntity($moved, ['skipHooks' => true]);
        $this->work($operation->id);
        $result = $service->status($this->accountId(), $operation->id);
        self::assertSame(1, $result->succeeded);
        self::assertSame(1, $result->failed);
        self::assertSame('Partial', $result->status);
        $moved->set('tenantId', $this->tenant->getId());
        $this->em->saveEntity($moved, ['skipHooks' => true]);
        $service->retry($this->accountId(), $operation->id);
        $this->work($operation->id);
        self::assertSame(2, $service->status($this->accountId(), $operation->id)->succeeded);
        self::assertSame(2, $this->em->getRDBRepository('Note')->where(['parentId' => $ids])->count());
    }

    public function testWorkerRechecksStreamPermissionAfterSubmission(): void
    {
        $ids = $this->opportunities(1);
        $operation = $this->service()->submit($this->accountId(), $this->request($ids));
        $data = $this->role->get('data');
        $data->Opportunity->stream = 'no';
        $this->role->set('data', $data);
        $this->em->saveEntity($this->role, ['skipHooks' => true]);
        $this->work($operation->id);
        self::assertSame(0, $this->em->getRDBRepository('Note')->where(['parentId' => $ids])->count());
        self::assertSame(1, $this->service()->status($this->accountId(), $operation->id)->failed);
    }

    public function testOtherUsersCannotReadResultsOrRetryEvenInTheSameTenant(): void
    {
        $operation = $this->service()->submit($this->accountId(), $this->request($this->opportunities(1)));
        $other = $this->create('User', ['userName' => 'other-' . bin2hex(random_bytes(6)), 'type' => 'admin', 'isActive' => true]);
        $service = $this->service($other);
        foreach (['status', 'results', 'retry'] as $method) {
            try {
                $method === 'results' ? $service->results($this->accountId(), $operation->id, null) : $service->$method($this->accountId(), $operation->id);
                self::fail('Another actor accessed the operation.');
            } catch (NotFound) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $service->recent($this->accountId())->list);
    }

    public function testAccountRebindingCannotRetargetTheOperation(): void
    {
        $ids = $this->opportunities(1);
        $operation = $this->service()->submit($this->accountId(), $this->request($ids));
        $platform = $this->create('ChatwootPlatform', ['name' => 'Replacement']);
        $this->account->set('platformId', $platform->getId());
        $this->em->saveEntity($this->account, ['skipHooks' => true]);
        $this->work($operation->id);
        self::assertSame(0, $this->em->getRDBRepository('Note')->where(['parentId' => $ids])->count());
        $this->expectException(NotFound::class);
        $this->service()->status($this->accountId(), $operation->id);
    }

    public function testPrivateLedgersCannotBeReadOrWrittenThroughGenericAcl(): void
    {
        $acl = $this->actorApp()->getContainer()->getByClass(Acl::class);
        foreach ([OpportunityBulkPost::OPERATION, OpportunityBulkPost::TARGET] as $scope) {
            foreach (['read', 'create', 'edit', 'delete'] as $action) self::assertFalse($acl->check($scope, $action));
        }
    }

    public function testStatusIsBoundToTheExactAccountEvenWithinOneTenant(): void
    {
        $operation = $this->service()->submit($this->accountId(), $this->request($this->opportunities(1)));
        $other = $this->create('ChatwootAccount', [
            'name' => 'Other account', 'chatwootAccountId' => random_int(1000001, 2000000000),
            'tenantId' => $this->tenant->getId(), 'platformId' => $this->account->get('platformId'),
        ]);
        $this->expectException(NotFound::class);
        $this->service()->status((int) $other->get('chatwootAccountId'), $operation->id);
    }

    public function testAmbiguousNumericWorkspaceIdsFailClosed(): void
    {
        $platform = $this->create('ChatwootPlatform', ['name' => 'Other platform']);
        $this->create('ChatwootAccount', [
            'name' => 'Same number', 'chatwootAccountId' => $this->accountId(),
            'tenantId' => $this->tenant->getId(), 'platformId' => $platform->getId(),
        ]);
        $this->expectException(NotFound::class);
        $this->service()->submit($this->accountId(), $this->request($this->opportunities(1)));
    }

    public function testResultsArePaginatedWithoutExpandingTheSelection(): void
    {
        $ids = $this->opportunities(101);
        $service = $this->service();
        $operation = $service->submit($this->accountId(), $this->request($ids));
        $first = $service->results($this->accountId(), $operation->id, null);
        $second = $service->results($this->accountId(), $operation->id, $first->next);
        self::assertCount(100, $first->list);
        self::assertCount(1, $second->list);
        self::assertNull($second->next);
        $returned = array_column([...$first->list, ...$second->list], 'opportunityId');
        sort($ids);
        sort($returned);
        self::assertSame($ids, $returned);
    }

    public function testIdempotencyKeyCannotBeReusedWithDifferentContent(): void
    {
        $request = $this->request($this->opportunities(1));
        $service = $this->service();
        $service->submit($this->accountId(), $request);
        $request->post = 'Different';
        $this->expectException(Conflict::class);
        $service->submit($this->accountId(), $request);
    }

    public function testWorkspaceMembershipAndPostingPermissionAreRequired(): void
    {
        $ids = $this->opportunities(1);
        $data = $this->role->get('data');
        // Espo's Note create checker uses the parent's stream permission.
        $data->Opportunity->stream = 'no';
        $this->role->set('data', $data);
        $this->em->saveEntity($this->role, ['skipHooks' => true]);
        try {
            $this->service()->submit($this->accountId(), $this->request($ids));
            self::fail('Note creation denied by role must reject submission.');
        } catch (Forbidden) {
            self::assertSame(0, $this->em->getRDBRepository(OpportunityBulkPost::OPERATION)->where(['userId' => $this->author->getId()])->count());
        }
        // Broad CRM read permission does not replace explicit workspace membership.
        $tenant = $this->create('Tenant', ['name' => 'Other tenant']);
        $this->account->set('tenantId', $tenant->getId());
        $this->em->saveEntity($this->account, ['skipHooks' => true]);
        $this->expectException(Forbidden::class);
        $this->service()->recent($this->accountId());
    }

    public function testDisabledAuthorCannotRunQueuedWork(): void
    {
        $ids = $this->opportunities(1);
        $operation = $this->service()->submit($this->accountId(), $this->request($ids));
        $this->author->set('isActive', false);
        $this->em->saveEntity($this->author, ['skipHooks' => true]);
        try {
            $this->work($operation->id);
            self::fail('An inactive author executed a job.');
        } catch (Forbidden) {
            self::assertSame(0, $this->em->getRDBRepository('Note')->where(['parentId' => $ids])->count());
        }
    }

    public function testMentionsAndFormattingUseTheNormalNotePipeline(): void
    {
        $ids = $this->opportunities(2);
        $mentioned = $this->create('User', ['userName' => 'mentioned-' . bin2hex(random_bytes(6)), 'type' => 'regular', 'isActive' => true]);
        $team = $this->em->getEntityById('Team', $this->author->getTeamIdList()[0]);
        $this->em->getRDBRepository('User')->getRelation($mentioned, 'teams')->relate($team);
        $this->em->getRDBRepository('User')->getRelation($mentioned, 'roles')->relate($this->role);
        $identity = $this->create('ChatwootUser', [
            'chatwootUserId' => 77, 'platformId' => $this->account->get('platformId'), 'assignedUserId' => $mentioned->getId(),
        ]);
        $membership = $this->create('ChatwootAccountUserMembership', ['chatwootAccountId' => $this->account->getId(), 'chatwootUserId' => $identity->getId()]);
        $chatwootTeam = $this->create('ChatwootTeam', ['name' => 'Team', 'accountId' => $this->account->getId(), 'chatwootTeamId' => 88]);
        $this->em->getRDBRepository('ChatwootAccountUserMembership')->getRelation($membership, 'chatwootTeams')->relate($chatwootTeam);
        $post = '**Hello** [@Mentioned](mention://user/77/Mentioned) [@Team](mention://team/88/Team)';
        $operation = $this->service()->submit($this->accountId(), $this->request($ids, $post));
        $this->work($operation->id);
        self::assertSame(2, $this->service()->status($this->accountId(), $operation->id)->succeeded);
        foreach ($this->em->getRDBRepository('Note')->where(['parentId' => $ids])->find() as $note) {
            self::assertSame($post, $note->get('post'));
            self::assertSame([$mentioned->getId()], $note->get('opportunityMentionUserIds'));
            self::assertSame($this->author->getId(), $note->get('createdById'));
        }
        self::assertSame(2, $this->em->getRDBRepository('OpportunityReadState')->where([
            'opportunityId' => $ids, 'userId' => $mentioned->getId(), 'isParticipant' => true,
        ])->count());
    }

    public function testNoteAndOutboxRollbackIfPersistingTheTargetResultFails(): void
    {
        if ($this->em->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') self::markTestSkipped('PostgreSQL fault injection.');
        $ids = $this->opportunities(1);
        $service = $this->service();
        $operation = $service->submit($this->accountId(), $this->request($ids));
        $pdo = $this->em->getPDO();
        $pdo->exec("CREATE OR REPLACE FUNCTION bulk_post_test_failure() RETURNS trigger LANGUAGE plpgsql AS '
            BEGIN IF NEW.operation_id = ''" . $operation->id . "'' AND NEW.status = ''Succeeded'' THEN
            RAISE EXCEPTION ''Injected result failure''; END IF; RETURN NEW; END;'");
        $pdo->exec('CREATE TRIGGER bulk_post_test_failure BEFORE UPDATE ON opportunity_bulk_post_target FOR EACH ROW EXECUTE FUNCTION bulk_post_test_failure()');
        try {
            $this->work($operation->id);
        } finally {
            $pdo->exec('DROP TRIGGER bulk_post_test_failure ON opportunity_bulk_post_target');
            $pdo->exec('DROP FUNCTION bulk_post_test_failure()');
        }
        self::assertSame(1, $service->status($this->accountId(), $operation->id)->failed);
        self::assertSame(0, $this->em->getRDBRepository('Note')->where(['parentId' => $ids])->count());
        self::assertSame(0, $this->em->getRDBRepository('OpportunityReadState')->where(['opportunityId' => $ids])->count());
        self::assertFalse((bool) $this->em->getEntityById(OpportunityBulkPost::OPERATION, $operation->id)->get('broadcastPending'));
        $service->retry($this->accountId(), $operation->id);
        $this->work($operation->id);
        self::assertSame(1, $service->status($this->accountId(), $operation->id)->succeeded);
        self::assertSame(1, $this->em->getRDBRepository('Note')->where(['parentId' => $ids])->count());
    }
}
