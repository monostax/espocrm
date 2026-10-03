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

    private function create(bool $completion = false, ?string $key = null, string $rule = 'FREQ=DAILY;COUNT=40'): Entity
    {
        $definition = $completion
            ? (object) ['basis' => 'CompletedDate', 'interval' => (object) ['unit' => 'week', 'value' => 1]]
            : (object) ['basis' => 'ScheduledDate', 'schedule' => $rule];
        $definition->timezone = 'America/Sao_Paulo';
        $definition->dateOnly = true;
        return $this->tasks()->create((object) [
            'name' => 'Recurring Task', 'teamsIds' => [$this->tenant->get('baseUserTeamId')],
            'assignedUserId' => $this->actor->getId(), 'dateEndDate' => '2026-10-03',
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
}
