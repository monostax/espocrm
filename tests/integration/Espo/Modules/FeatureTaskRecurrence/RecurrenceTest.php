<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureTaskRecurrence;

use DateTimeImmutable;
use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\File\Manager;
use Espo\Modules\FeatureTaskRecurrence\Services\Recurrence;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/** Runs only against an explicitly supplied disposable database, in an isolated installed runtime. */
class RecurrenceTest extends TestCase
{
    private static string $source;
    private static string $runtime;
    private Application $app;
    private EntityManager $em;
    private Entity $actor;
    private Entity $tenant;

    public static function setUpBeforeClass(): void
    {
        if (!getenv('TASK_RECURRENCE_TEST_DATABASE')) self::markTestSkipped('Set TASK_RECURRENCE_TEST_DATABASE to a disposable database.');
        self::$source = getcwd();
        self::$runtime = '/tmp/opencode/task-recurrence-runtime-' . bin2hex(random_bytes(4));
        $files = new Manager();
        $files->mkdir(self::$runtime);
        foreach (['application', 'vendor', 'client', 'install', 'html', 'public', 'index.php'] as $path) symlink(self::$source . '/' . $path, self::$runtime . '/' . $path);
        foreach (['bootstrap.php', 'command.php'] as $path) copy(self::$source . '/' . $path, self::$runtime . '/' . $path);
        $files->copy(self::$source . '/custom', self::$runtime . '/custom', true);
        $files->mkdir(self::$runtime . '/data');
        chdir(self::$runtime);
        set_include_path(self::$runtime);
        require_once self::$source . '/install/core/Installer.php';
        $installer = new \Installer(new ApplicationParams(noErrorHandler: true));
        $config = ['database' => [
            'platform' => 'Postgresql', 'host' => getenv('TASK_RECURRENCE_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('TASK_RECURRENCE_TEST_PORT') ?: '55439', 'dbname' => getenv('TASK_RECURRENCE_TEST_DATABASE'),
            'user' => getenv('TASK_RECURRENCE_TEST_USER') ?: get_current_user(), 'password' => '',
        ], 'siteUrl' => 'http://127.0.0.1:8099', 'useCache' => false, 'isInstalled' => true, 'version' => '10.0.2'];
        $installer->saveData($config);
        $installer->saveConfig($config);
        $installer->rebuild();
        $installer->setSuccess();
    }

    protected function setUp(): void
    {
        chdir(self::$runtime);
        $app = new Application(new ApplicationParams(noErrorHandler: true));
        $app->setupSystemUser();
        $em = $app->getContainer()->get('entityManager');
        $slug = 'recurrence-' . bin2hex(random_bytes(4));
        $this->tenant = $em->createEntity('Tenant', ['name' => $slug, 'slug' => $slug]);
        $email = 'recurrence-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->actor = $em->createEntity('User', ['userName' => $email, 'emailAddress' => $email, 'type' => 'admin', 'isActive' => true, 'firstName' => 'Recurrence']);
        $this->actor = $em->getEntityById('User', $this->actor->getId());
        $this->app = new Application(new ApplicationParams(noErrorHandler: true, services: ['user' => $this->actor]));
        $this->em = $this->app->getContainer()->get('entityManager');
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$source)) chdir(self::$source);
    }

    private function recurrence(): Recurrence { return $this->app->getInjectableFactory()->create(Recurrence::class); }
    private function tasks(): \Espo\Core\Record\Service { return $this->app->getContainer()->getByClass(ServiceContainer::class)->get('Task'); }

    private function create(bool $completion = false, ?string $key = null, string $rule = 'FREQ=DAILY;COUNT=40', string $anchor = '2026-10-03'): Entity
    {
        $definition = $completion
            ? (object) ['basis' => 'CompletedDate', 'interval' => (object) ['unit' => 'week', 'value' => 1]]
            : (object) ['basis' => 'ScheduledDate', 'schedule' => $rule];
        $definition->timezone = 'America/Sao_Paulo';
        $definition->dateOnly = true;
        return $this->tasks()->create((object) [
            'name' => 'Recurring Task', 'teamsIds' => [$this->tenant->get('baseUserTeamId')],
            'assignedUserId' => $this->actor->getId(), 'dateEndDate' => $anchor,
            'recurrence' => (object) ['definition' => $definition, 'idempotencyKey' => $key ?? bin2hex(random_bytes(16))],
        ])->getEntity();
    }

    public function testIdempotentCreationRollingWindowAndDeletionSuppression(): void
    {
        $task = $this->create(false, 'same-submission');
        self::assertSame($task->getId(), $this->create(false, 'same-submission')->getId());
        $seriesId = $task->get('recurrenceSeriesId');
        $this->recurrence()->process($seriesId, new DateTimeImmutable('2026-10-03 12:00:00 UTC'));
        self::assertSame(31, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $seriesId])->count());
        $this->tasks()->delete($task->getId());
        $this->recurrence()->process($seriesId, new DateTimeImmutable('2026-10-03 12:00:00 UTC'));
        self::assertNull($this->em->getEntityById('Task', $task->getId()));
        self::assertSame(31, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $seriesId])->count());
    }

    public function testSparseRuleContainsOnlyNextUpcomingTask(): void
    {
        $task = $this->create(false, null, 'FREQ=YEARLY;COUNT=3');
        $this->recurrence()->process($task->get('recurrenceSeriesId'), new DateTimeImmutable('2026-10-04 12:00:00 UTC'));
        self::assertSame(2, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $task->get('recurrenceSeriesId')])->count());
        $this->recurrence()->process($task->get('recurrenceSeriesId'), new DateTimeImmutable('2026-10-05 12:00:00 UTC'));
        self::assertSame(2, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $task->get('recurrenceSeriesId')])->count());
    }

    public function testCompletionReceiptSurvivesReopeningAndPausedRecovery(): void
    {
        $task = $this->create(true);
        $seriesId = $task->get('recurrenceSeriesId');
        $data = $this->recurrence()->read($task->getId());
        $this->recurrence()->mutate($task->getId(), (object) ['action' => 'pause', 'scope' => 'WholeSeries', 'version' => $data->version]);
        $this->tasks()->update($task->getId(), (object) ['status' => 'Completed']);
        $receipt = $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['taskId' => $task->getId()])->findOne();
        self::assertNotNull($receipt->get('eventAt'));
        $this->recurrence()->process($seriesId);
        self::assertSame(1, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $seriesId])->count());
        $this->tasks()->update($task->getId(), (object) ['status' => 'Not Started']);
        self::assertNull($this->em->getEntityById('Task', $task->getId())->get('dateCompleted'));
        $data = $this->recurrence()->read($task->getId());
        $this->recurrence()->mutate($task->getId(), (object) ['action' => 'resume', 'scope' => 'WholeSeries', 'version' => $data->version]);
        $this->recurrence()->process($seriesId);
        $this->tasks()->update($task->getId(), (object) ['status' => 'Completed']);
        $this->recurrence()->process($seriesId);
        self::assertSame(2, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $seriesId])->count());
        $successor = $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['predecessorId' => $receipt->getId()])->findOne();
        $expected = (new DateTimeImmutable($receipt->get('eventAt') . ' UTC'))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->modify('+7 days')->format('Y-m-d');
        self::assertSame($expected, $this->em->getEntityById('Task', $successor->get('taskId'))->get('dateEndDate'));
    }

    public function testWholeSeriesEditPreservesOverridesAndChecksVersion(): void
    {
        $task = $this->create(false, null, 'FREQ=DAILY;COUNT=3');
        $this->recurrence()->process($task->get('recurrenceSeriesId'), new DateTimeImmutable('2026-10-03 UTC'));
        $this->tasks()->update($task->getId(), (object) ['name' => 'My occurrence']);
        $data = $this->recurrence()->read($task->getId());
        $this->recurrence()->mutate($task->getId(), (object) ['action' => 'edit', 'scope' => 'WholeSeries', 'version' => $data->version, 'patch' => (object) ['name' => 'New template']]);
        self::assertSame('My occurrence', $this->em->getEntityById('Task', $task->getId())->get('name'));
        $this->expectException(Conflict::class);
        $this->recurrence()->mutate($task->getId(), (object) ['action' => 'pause', 'scope' => 'WholeSeries', 'version' => $data->version]);
    }

    public function testEndingCompletionSeriesCannotRecreateADeletedHead(): void
    {
        $task = $this->create(true);
        $data = $this->recurrence()->read($task->getId());
        $this->recurrence()->mutate($task->getId(), (object) ['action' => 'delete', 'scope' => 'WholeSeries', 'version' => $data->version]);
        $this->recurrence()->process($task->get('recurrenceSeriesId'));
        self::assertSame(1, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $task->get('recurrenceSeriesId')])->count());
    }

    public function testFollowingSplitRetainsTaskIdsOverridesPhaseAndFiniteCount(): void
    {
        $task = $this->create(false, null, 'FREQ=WEEKLY;INTERVAL=2;COUNT=4', '2080-01-05');
        $this->recurrence()->process($task->get('recurrenceSeriesId'), new DateTimeImmutable('2080-02-15 UTC'));
        $rows = [...$this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $task->get('recurrenceSeriesId')])->order('recurrenceId')->find()];
        self::assertCount(4, $rows);
        $secondId = $rows[1]->get('taskId');
        $this->tasks()->update($secondId, (object) ['dateEndDate' => '2080-01-25']);
        $before = $this->recurrence()->read($secondId);
        $after = $this->recurrence()->mutate($secondId, (object) ['action' => 'edit', 'scope' => 'ThisAndFollowing', 'version' => $before->version, 'patch' => (object) ['priority' => 'High']]);
        self::assertNotSame($before->seriesId, $after->seriesId);
        self::assertSame($before->originalId, $after->originalId);
        self::assertSame('2080-01-25', $this->em->getEntityById('Task', $secondId)->get('dateEndDate'));
        self::assertSame('High', $this->em->getEntityById('Task', $rows[3]->get('taskId'))->get('priority'));
        $this->recurrence()->process($after->seriesId, new DateTimeImmutable('2080-03-01 UTC'));
        self::assertSame('Exhausted', $this->em->getEntityById('TaskRecurrenceSeries', $after->seriesId)->get('state'));
        self::assertSame(3, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $after->seriesId])->count());
    }

    public function testRuleChangingInitialSlotRetainsTheSelectedTaskAndSuppressesItsAlias(): void
    {
        $task = $this->create(false, null, 'FREQ=DAILY;COUNT=3', '2080-01-05');
        $before = $this->recurrence()->read($task->getId());
        $definition = clone $before->definition;
        $definition->schedule = "DTSTART;VALUE=DATE:20800110\nRDATE;VALUE=DATE:20800110,20800120";
        $after = $this->recurrence()->mutate($task->getId(), (object) ['action' => 'edit', 'scope' => 'WholeSeries', 'version' => $before->version, 'definition' => $definition]);
        self::assertSame($before->originalId, $after->originalId);
        self::assertSame('2080-01-10', $this->em->getEntityById('Task', $task->getId())->get('dateEndDate'));
        $this->recurrence()->process($after->seriesId, new DateTimeImmutable('2080-01-10 UTC'));
        self::assertSame(2, $this->em->getRDBRepository('Task')->where(['recurrenceSeriesId' => $after->seriesId])->count());
        self::assertSame(1, $this->em->getRDBRepository('Task')->where(['recurrenceSeriesId' => $after->seriesId, 'dateEndDate' => '2080-01-10'])->count());
    }

    public function testBasisMigrationRetainsHeadAndRetiresUnmodifiedCalendarFuture(): void
    {
        $task = $this->create(false, null, 'FREQ=DAILY;COUNT=3', '2080-01-05');
        $this->recurrence()->process($task->get('recurrenceSeriesId'), new DateTimeImmutable('2080-01-05 UTC'));
        $before = $this->recurrence()->read($task->getId());
        $definition = (object) ['basis' => 'CompletedDate', 'timezone' => 'America/Sao_Paulo', 'dateOnly' => true,
            'anchor' => '2080-01-05', 'interval' => (object) ['unit' => 'week', 'value' => 1], 'count' => 2];
        $after = $this->recurrence()->mutate($task->getId(), (object) ['action' => 'edit', 'scope' => 'WholeSeries', 'version' => $before->version, 'definition' => $definition]);
        self::assertSame($before->lineageId, $after->lineageId);
        self::assertSame('CompletedDate', $after->definition->basis);
        self::assertSame(1, $this->em->getRDBRepository('Task')->where(['recurrenceSeriesId' => $after->seriesId])->count());
        self::assertSame(0, $this->em->getRDBRepository('Task')->where(['recurrenceSeriesId' => $before->seriesId])->count());
        $this->tasks()->update($task->getId(), (object) ['status' => 'Completed']);
        $this->recurrence()->process($after->seriesId);
        self::assertSame(2, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $after->seriesId])->count());
    }

    public function testTwoEarlyCompletionCyclesMayHaveTheSameDeadlineAndCountIncludesInitial(): void
    {
        $task = $this->create(true);
        $seriesId = $task->get('recurrenceSeriesId');
        $series = $this->em->getEntityById('TaskRecurrenceSeries', $seriesId);
        $definition = $series->get('definition'); $definition->count = 3;
        $series->set('definition', $definition); $this->em->saveEntity($series);
        for ($i = 0; $i < 3; $i++) {
            $series = $this->em->getEntityById('TaskRecurrenceSeries', $seriesId);
            $head = $this->em->getEntityById('TaskRecurrenceOccurrence', $series->get('headId'));
            $this->tasks()->update($head->get('taskId'), (object) ['status' => 'Completed']);
            $this->recurrence()->process($seriesId);
        }
        $rows = [...$this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $seriesId])->order('sequence')->find()];
        self::assertCount(3, $rows);
        self::assertSame($rows[1]->get('originalDeadline'), $rows[2]->get('originalDeadline'));
        self::assertNotSame($rows[1]->get('recurrenceId'), $rows[2]->get('recurrenceId'));
        self::assertSame('Exhausted', $this->em->getEntityById('TaskRecurrenceSeries', $seriesId)->get('state'));
    }

    public function testReservationAndNativeCreationRollBackTogether(): void
    {
        $task = $this->create(false, null, 'FREQ=DAILY;COUNT=3', '2080-01-05');
        $seriesId = $task->get('recurrenceSeriesId');
        try {
            $this->em->getTransactionManager()->run(function () use ($seriesId) {
                $this->recurrence()->process($seriesId, new DateTimeImmutable('2080-01-05 UTC'));
                throw new \RuntimeException('Injected transaction failure');
            });
        } catch (\RuntimeException) {}
        self::assertSame(1, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $seriesId])->count());
        self::assertSame(1, $this->em->getRDBRepository('Task')->where(['recurrenceSeriesId' => $seriesId])->count());
        $this->recurrence()->process($seriesId, new DateTimeImmutable('2080-01-05 UTC'));
        self::assertSame(3, $this->em->getRDBRepository('Task')->where(['recurrenceSeriesId' => $seriesId])->count());
    }

    public function testReusableDocumentFilesReceiveIndependentAttachmentRecords(): void
    {
        $metadata = $this->app->getContainer()->get('metadata');
        $defs = $metadata->get(['entityDefs', 'Task']);
        $defs['fields']['attachments'] = ['type' => 'attachmentMultiple', 'recurrenceReusable' => true];
        $metadata->set('entityDefs', 'Task', $defs);
        $source = $this->em->createEntity('Attachment', ['name' => 'instructions.txt', 'type' => 'text/plain', 'role' => 'Attachment']);
        $document = $this->em->createEntity('Document', ['name' => 'Reusable instructions', 'fileId' => $source->getId(), 'teamsIds' => [$this->tenant->get('baseUserTeamId')]]);
        $this->em->getRDBRepository('Document')->getRelation($document, 'teams')->relateById($this->tenant->get('baseUserTeamId'));
        $source->set(['relatedType' => 'Document', 'relatedId' => $document->getId()]);
        $this->em->saveEntity($source);
        $files = $this->app->getInjectableFactory()->create(\Espo\Modules\FeatureTaskRecurrence\Tools\ReusableFiles::class);
        $data = (object) ['attachmentsIds' => [$source->getId()]];
        $files->normalize($data, $this->tenant->getId());
        $first = $files->copy($data);
        $second = $files->copy($data);
        self::assertNotSame($first->attachmentsIds, $second->attachmentsIds);
        self::assertNotSame($source->getId(), $first->attachmentsIds[0]);
        self::assertSame($source->getId(), $this->em->getEntityById('Attachment', $first->attachmentsIds[0])->getSourceId());
        self::assertSame($this->actor->getId(), $this->em->getEntityById('Attachment', $first->attachmentsIds[0])->get('createdById'));
        self::assertSame($document->getId(), $this->em->getEntityById('Attachment', $source->getId())->get('relatedId'));
        $other = $this->em->createEntity('Tenant', ['name' => 'Other files workspace', 'slug' => bin2hex(random_bytes(8))]);
        $this->expectException(\Espo\Core\Exceptions\Forbidden::class);
        $files->normalize($data, $other->getId());
    }

    public function testNativeRemindersAndAuthorshipUseTheExecutionActor(): void
    {
        $task = $this->tasks()->create((object) [
            'name' => 'Timed recurring Task', 'teamsIds' => [$this->tenant->get('baseUserTeamId')],
            'assignedUserId' => $this->actor->getId(), 'dateEnd' => '2080-01-05 13:00:00',
            'reminders' => [(object) ['type' => 'Popup', 'seconds' => 600]],
            'recurrence' => (object) ['idempotencyKey' => bin2hex(random_bytes(16)), 'definition' => (object) ['timezone' => 'America/Sao_Paulo', 'dateOnly' => false, 'schedule' => 'FREQ=DAILY;COUNT=2']],
        ])->getEntity();
        $this->recurrence()->process($task->get('recurrenceSeriesId'), new DateTimeImmutable('2080-01-05 UTC'));
        $next = $this->em->getRDBRepository('Task')->where(['recurrenceSeriesId' => $task->get('recurrenceSeriesId'), 'id!=' => $task->getId()])->findOne();
        self::assertNotNull($next);
        self::assertSame($this->actor->getId(), $next->get('createdById'));
        $reminder = $this->em->getRDBRepository('Reminder')->where(['entityType' => 'Task', 'entityId' => $next->getId()])->findOne();
        self::assertNotNull($reminder);
        self::assertSame($this->actor->getId(), $reminder->get('userId'));
        self::assertSame(600, $reminder->get('seconds'));
        $this->tasks()->update($next->getId(), (object) ['status' => 'Completed']);
        self::assertSame(0, $this->em->getRDBRepository('Reminder')->where(['entityType' => 'Task', 'entityId' => $next->getId()])->count());
    }

    public function testInactiveExecutionActorCannotGenerateOccurrences(): void
    {
        $task = $this->create(false, null, 'FREQ=DAILY;COUNT=3');
        $this->actor->set('isActive', false);
        $this->em->saveEntity($this->actor);
        $app = new Application(new ApplicationParams(noErrorHandler: true, services: ['user' => $this->actor]));
        try {
            $app->getInjectableFactory()->create(Recurrence::class)->process($task->get('recurrenceSeriesId'));
            self::fail('An inactive execution identity generated Tasks.');
        } catch (\Espo\Core\Exceptions\Forbidden) {
            self::assertSame(1, $this->em->getRDBRepository('Task')->where(['recurrenceSeriesId' => $task->get('recurrenceSeriesId')])->count());
        }
    }

    public function testEndFollowingAllowsExplicitlyClearingTheSelectedDeadline(): void
    {
        $task = $this->create(false, null, 'FREQ=DAILY;COUNT=3', '2080-01-05');
        $series = $this->recurrence()->read($task->getId());
        $this->recurrence()->mutate($task->getId(), (object) ['action' => 'end', 'scope' => 'ThisAndFollowing',
            'version' => $series->version, 'patch' => (object) ['dateEnd' => null, 'dateEndDate' => null]]);
        self::assertNull($this->em->getEntityById('Task', $task->getId())->get('dateEndDate'));
        $this->recurrence()->process($series->seriesId, new DateTimeImmutable('2080-01-05 UTC'));
        self::assertSame(1, $this->em->getRDBRepository('Task')->where(['recurrenceSeriesId' => $series->seriesId])->count());
    }

    private function reader(string $teamId, bool $hideDeadline = false, bool $readOnlyDeadline = false, bool $hideName = false): Application
    {
        $fields = new \stdClass();
        if ($hideDeadline || $readOnlyDeadline) $fields->dateEnd = (object) ['read' => $hideDeadline ? 'no' : 'yes', 'edit' => 'no'];
        if ($hideName) $fields->name = (object) ['read' => 'no', 'edit' => 'no'];
        $role = $this->em->createEntity('Role', ['name' => 'Recurrence reader',
            'data' => (object) ['Task' => (object) ['read' => 'team', 'edit' => 'team', 'delete' => 'team', 'create' => 'yes']],
            'fieldData' => (object) ['Task' => $fields],
        ]);
        $email = bin2hex(random_bytes(8)) . '@example.test';
        $user = $this->em->createEntity('User', ['userName' => $email, 'emailAddress' => $email, 'type' => 'regular', 'isActive' => true]);
        $this->em->getRDBRepository('User')->getRelation($user, 'teams')->relateById($teamId);
        $this->em->getRDBRepository('User')->getRelation($user, 'roles')->relateById($role->getId());
        return new Application(new ApplicationParams(noErrorHandler: true, services: ['user' => $this->em->getEntityById('User', $user->getId())]));
    }

    public function testTeamScopedReaderCannotReadAnotherWorkspaceSeries(): void
    {
        $task = $this->create();
        $other = $this->em->createEntity('Tenant', ['name' => 'Other reader workspace', 'slug' => bin2hex(random_bytes(8))]);
        $reader = $this->reader($other->get('baseUserTeamId'));
        $this->expectException(\Espo\Core\Exceptions\Forbidden::class);
        $reader->getInjectableFactory()->create(Recurrence::class)->read($task->getId());
    }

    public function testDeadlineFieldAclCannotBeBypassedThroughRecurrenceIdentityOrDefinition(): void
    {
        $task = $this->create();
        $reader = $this->reader($this->tenant->get('baseUserTeamId'), true);
        $services = $reader->getContainer()->getByClass(ServiceContainer::class);
        $output = $services->get('Task')->read($task->getId())->getValueMap();
        self::assertFalse(property_exists($output, 'dateEndDate'));
        self::assertFalse(property_exists($output, 'recurrenceId'));
        self::assertFalse(property_exists($output, 'recurrenceSeriesId'));
        self::assertFalse(property_exists($output, 'recurrence'));
        $this->expectException(\Espo\Core\Exceptions\Forbidden::class);
        $reader->getInjectableFactory()->create(Recurrence::class)->read($task->getId());
    }

    public function testOverlappingActorBoundWorkersReserveEachSlotOnce(): void
    {
        $task = $this->create(false, null, 'FREQ=DAILY;COUNT=4', '2080-01-05');
        $seriesId = $task->get('recurrenceSeriesId');
        $script = 'require "bootstrap.php";
            $initial = new \Espo\Core\Application(new \Espo\Core\Application\ApplicationParams(noErrorHandler: true));
            $initial->setupSystemUser();
            $actor = $initial->getContainer()->get("entityManager")->getEntityById("User", $argv[1]);
            $app = new \Espo\Core\Application(new \Espo\Core\Application\ApplicationParams(noErrorHandler: true, services: ["user" => $actor]));
            $app->getInjectableFactory()->create(\Espo\Modules\FeatureTaskRecurrence\Services\Recurrence::class)->process($argv[2], new \DateTimeImmutable("2080-01-05 UTC"));';
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $script, $this->actor->getId(), $seriesId],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$runtime);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output);
        }
        self::assertSame(4, $this->em->getRDBRepository('Task')->where(['recurrenceSeriesId' => $seriesId])->count());
        self::assertSame(4, $this->em->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $seriesId])->count());
    }

    public function testScopedTemplateCannotAssignAUserFromAnotherWorkspace(): void
    {
        $task = $this->create();
        $other = $this->em->createEntity('Tenant', ['name' => 'Other assignment workspace', 'slug' => bin2hex(random_bytes(8))]);
        $member = $this->reader($other->get('baseUserTeamId'))->getContainer()->get('user');
        $series = $this->recurrence()->read($task->getId());
        $this->expectException(\Espo\Core\Exceptions\Forbidden::class);
        $this->recurrence()->mutate($task->getId(), (object) ['action' => 'edit', 'scope' => 'WholeSeries', 'version' => $series->version,
            'patch' => (object) ['assignedUserId' => $member->getId()]]);
    }

    public function testReadOnlyDeadlineRestrictsAdvertisedSeriesCapabilities(): void
    {
        $task = $this->create();
        $reader = $this->reader($this->tenant->get('baseUserTeamId'), false, true);
        $series = $reader->getInjectableFactory()->create(Recurrence::class)->read($task->getId());
        self::assertNotNull($series);
        self::assertFalse($series->canEdit);
        self::assertFalse($series->canDelete);
    }

    private function activityRequest(int $accountId, string $taskId, ?object $body = null): \Espo\Core\Api\Request
    {
        $request = $this->createMock(\Espo\Core\Api\Request::class);
        $request->method('getQueryParam')->willReturnCallback(fn ($name) => $name === 'accountId' ? (string) $accountId : null);
        $request->method('getRouteParam')->willReturnCallback(fn ($name) => ['type' => 'Task', 'id' => $taskId][$name] ?? null);
        $request->method('getParsedBody')->willReturn($body ?? new \stdClass());
        return $request;
    }

    private function account(Entity $tenant): int
    {
        $id = random_int(100000, 999999999);
        $this->em->createEntity('ChatwootAccount', ['name' => 'Recurrence test workspace', 'tenantId' => $tenant->getId(), 'chatwootAccountId' => $id],
            [\Espo\Core\ORM\Repository\Option\SaveOption::SKIP_ALL => true]);
        return $id;
    }

    public function testActivityInboxRecurrenceReadPreviewAndTeamPatchRespectWorkspace(): void
    {
        $task = $this->create(false, null, 'FREQ=DAILY;COUNT=2', '2080-01-05');
        $this->recurrence()->process($task->get('recurrenceSeriesId'), new DateTimeImmutable('2080-01-05 UTC'));
        $account = $this->account($this->tenant);
        $other = $this->em->createEntity('Tenant', ['name' => 'Other API workspace', 'slug' => bin2hex(random_bytes(8))]);
        $otherAccount = $this->account($other);
        $controller = $this->app->getInjectableFactory()->create(\Espo\Modules\Chatwoot\Controllers\ActivityInbox::class);
        $series = $controller->getActionRecurrenceRead($this->activityRequest($account, $task->getId()));
        self::assertSame($task->get('recurrenceSeriesId'), $series->seriesId);
        $list = $controller->getActionList($this->activityRequest($account, $task->getId()));
        self::assertSame(2, $list->total);
        foreach ($list->list as $row) self::assertSame('DAILY', $row->recurrenceBadge->summary->frequency);
        foreach (['read', 'preview'] as $operation) {
            $request = $this->activityRequest($otherAccount, $task->getId(), (object) ['taskId' => $task->getId(), 'definition' => $series->definition]);
            try {
                if ($operation === 'read') $controller->getActionRecurrenceRead($request);
                else $controller->postActionRecurrencePreview($request);
                self::fail('A requested workspace exposed another workspace recurrence.');
            } catch (\Espo\Core\Exceptions\NotFound) { self::assertTrue(true); }
        }
        $this->expectException(\Espo\Core\Exceptions\BadRequest::class);
        $controller->postActionRecurrenceMutate($this->activityRequest($account, $task->getId(), (object) [
            'action' => 'edit', 'scope' => 'WholeSeries', 'version' => $series->version,
            'patch' => (object) ['teamsIds' => [$other->get('baseUserTeamId')]],
        ]));
    }

    public function testActivityInboxRecurrenceCannotExposeAFieldHiddenDeadline(): void
    {
        $task = $this->create();
        $account = $this->account($this->tenant);
        $reader = $this->reader($this->tenant->get('baseUserTeamId'), true);
        $controller = $reader->getInjectableFactory()->create(\Espo\Modules\Chatwoot\Controllers\ActivityInbox::class);
        $list = $controller->getActionList($this->activityRequest($account, $task->getId()));
        self::assertSame(1, $list->total);
        self::assertFalse(property_exists($list->list[0], 'recurrenceSeriesId'));
        self::assertFalse(property_exists($list->list[0], 'recurrenceId'));
        self::assertFalse(property_exists($list->list[0], 'recurrenceBadge'));
        $this->expectException(\Espo\Core\Exceptions\Forbidden::class);
        $controller->getActionRecurrenceRead($this->activityRequest($account, $task->getId()));
    }

    public function testScopedDeadlineTypeChangeRequiresExplicitDefinitionMigration(): void
    {
        $task = $this->create(false, null, 'FREQ=DAILY;COUNT=3', '2080-01-05');
        $series = $this->recurrence()->read($task->getId());
        $this->expectException(\Espo\Core\Exceptions\BadRequest::class);
        $this->recurrence()->mutate($task->getId(), (object) ['action' => 'edit', 'scope' => 'WholeSeries', 'version' => $series->version,
            'patch' => (object) ['dateEndDate' => null, 'dateEnd' => '2080-01-05 13:00:00']]);
    }

    public function testClosedInitialTaskCannotBindACompletionSeriesAndCreationRollsBack(): void
    {
        $taskCount = $this->em->getRDBRepository('Task')->count();
        $seriesCount = $this->em->getRDBRepository('TaskRecurrenceSeries')->count();
        try {
            $this->tasks()->create((object) ['name' => 'Initially completed recurrence', 'status' => 'Completed',
            'teamsIds' => [$this->tenant->get('baseUserTeamId')], 'assignedUserId' => $this->actor->getId(), 'dateEndDate' => '2080-01-05',
            'recurrence' => (object) ['idempotencyKey' => bin2hex(random_bytes(16)), 'definition' => (object) [
                'basis' => 'CompletedDate', 'dateOnly' => true, 'timezone' => 'America/Sao_Paulo',
                'interval' => (object) ['unit' => 'week', 'value' => 1], 'count' => 2,
            ]]]);
            self::fail('A closed initial Task bound to a new recurrence series.');
        } catch (\Espo\Core\Exceptions\BadRequest $exception) {
            self::assertStringContainsString('open Task', $exception->getMessage());
            self::assertSame($taskCount, $this->em->getRDBRepository('Task')->count());
            self::assertSame($seriesCount, $this->em->getRDBRepository('TaskRecurrenceSeries')->count());
        }
    }

    public function testTechnicalSeriesNameDoesNotExposeAHiddenTaskName(): void
    {
        $task = $this->create();
        $reader = $this->reader($this->tenant->get('baseUserTeamId'), false, false, true);
        $output = $reader->getContainer()->getByClass(ServiceContainer::class)->get('Task')->read($task->getId())->getValueMap();
        self::assertFalse(property_exists($output, 'name'));
        self::assertFalse(property_exists($output, 'recurrenceSeriesName'));
        self::assertTrue(property_exists($output, 'recurrence'));
    }
}
