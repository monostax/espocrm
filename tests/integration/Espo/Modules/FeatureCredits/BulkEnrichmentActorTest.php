<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureCredits;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Record\{CreateParams,ServiceContainer};
use Espo\Core\Utils\File\Manager;
use Espo\Modules\FeatureCredits\Accounting\RecordId;
use Espo\Modules\FeatureCredits\Enrichment\{ActorScope,BatchRunner};
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Bulk runs step inside scheduled jobs, whose container already holds the system user. A mocked
 * ActorScope hid that Espo refuses to replace that user, so in production every step failed before
 * its first record. Full disposable Espo runtime: real container, ORM, ACL and hooks.
 */
class BulkEnrichmentActorTest extends TestCase
{
    private static string $source;
    private static string $runtime;
    private Application $job;
    private EntityManager $em;
    private string $tenant;
    private string $team;
    private string $actor;

    public static function setUpBeforeClass(): void
    {
        if (!getenv('TEST_DATABASE_NAME')) self::markTestSkipped('Requires an empty disposable CRM application database.');
        self::$source = getcwd();
        self::$runtime = '/tmp/opencode/bulk-enrichment-actor-' . bin2hex(random_bytes(4));
        $files = new Manager(); $files->mkdir(self::$runtime);
        foreach (['application','vendor','client','install','html','public','index.php'] as $path) symlink(self::$source . '/' . $path,self::$runtime . '/' . $path);
        foreach (['bootstrap.php','command.php','run-module-script.php'] as $path) copy(self::$source . '/' . $path,self::$runtime . '/' . $path);
        $files->copy(self::$source . '/custom',self::$runtime . '/custom',true); $files->mkdir(self::$runtime . '/data');
        chdir(self::$runtime); set_include_path(self::$runtime);
        require_once self::$source . '/install/core/Installer.php';
        $installer = new \Installer(new ApplicationParams(noErrorHandler: true));
        $config = ['database' => ['platform' => getenv('TEST_DATABASE_PLATFORM'),'host' => getenv('TEST_DATABASE_HOST'),
            'port' => getenv('TEST_DATABASE_PORT'),'dbname' => getenv('TEST_DATABASE_NAME'),
            'user' => getenv('TEST_DATABASE_USER'),'password' => getenv('TEST_DATABASE_PASSWORD')],
            'siteUrl' => 'http://127.0.0.1:8098','useCache' => false,'isInstalled' => true,'version' => '10.0.2'];
        $installer->saveData($config); $installer->saveConfig($config); $installer->rebuild(); $installer->setSuccess();
    }

    protected function setUp(): void
    {
        chdir(self::$runtime);
        // What the job runner hands a job: an application whose user is already the system user.
        $this->job = new Application(new ApplicationParams(noErrorHandler: true)); $this->job->setupSystemUser();
        $this->em = $this->job->getContainer()->getByClass(EntityManager::class);
        $tenant = $this->em->createEntity('Tenant',['name' => 'Bulk fixture ' . bin2hex(random_bytes(4))]);
        $this->tenant = $tenant->getId();
        $this->team = $this->em->createEntity('Team',['name' => 'Bulk team','tenantId' => $this->tenant])->getId();
        $user = $this->em->createEntity('User',['userName' => 'bulk-' . $this->tenant . '@example.test',
            'emailAddress' => 'bulk-' . $this->tenant . '@example.test','type' => 'admin','isActive' => true]);
        $this->actor = $user->getId();
        $this->em->getRDBRepository('Tenant')->getRelation($tenant,'users')->relate($user);
    }

    private function make(string $class): object { return $this->job->getContainer()->getByClass(InjectableFactory::class)->create($class); }

    public function testWorkInAJobRunsAsThePurchaserAndTheJobKeepsItsSystemUser(): void
    {
        $scope = $this->make(ActorScope::class);
        [$current,$account] = $scope->run($this->actor,fn (callable $make): array => [
            $make(ApplicationState::class)->getUserId(),
            // The same normal record path BatchApplication saves through.
            $make(ServiceContainer::class)->get('Account')->create((object) ['name' => 'Saved by a bulk step','tenantId' => $this->tenant,
                'teamsIds' => [$this->team]],CreateParams::create()),
        ]);
        $this->assertSame($this->actor,$current);
        $this->assertSame($this->actor,$account->get('createdById'));
        $this->assertTrue($this->job->getContainer()->getByClass(ApplicationState::class)->getUser()->isSystem());
        // The same job process may step again.
        $this->assertSame($this->actor,$scope->run($this->actor,fn (callable $make): string => $make(ApplicationState::class)->getUserId()));
    }

    public function testInactivePurchaserIsForbidden(): void
    {
        $user = $this->em->getEntityById('User',$this->actor); $user->set('isActive',false); $this->em->saveEntity($user);
        $this->expectException(Forbidden::class);
        $this->make(ActorScope::class)->run($this->actor,fn (): null => null);
    }

    public function testStepsFinishTheProductionRunShapes(): void
    {
        $contact = $this->em->createEntity('Contact',['lastName' => 'Bulk target','tenantId' => $this->tenant,'teamsIds' => [$this->team]]);
        // Manuella's run on 2026-11-10: two sources, email and phone. No provider is configured here.
        $running = $this->batch('running','pending',$contact->getId());
        $cancelled = $this->batch('cancelled','cancelled',$contact->getId());
        $runner = $this->make(BatchRunner::class);

        $this->assertFalse($runner->step($running));
        $this->assertSame(['completed','skipped','no_route'],$this->states($running));
        // A cancelled run used to be stepped forever; it now closes.
        $this->assertFalse($runner->step($cancelled));
        $this->assertSame(['cancelled','cancelled',null],$this->states($cancelled));
        $this->assertSame([],array_intersect([$running,$cancelled],$runner->stale()));
    }

    private function batch(string $state,string $itemState,string $target): string
    {
        $id = RecordId::generate(); $pdo = $this->em->getPDO();
        $pdo->prepare('INSERT INTO enrichment_batch (id,tenant_id,created_by_id,request_key,target_type,product,settings,state,total,created_at,deleted)
            VALUES (?,?,?,?,?,?,?,?,1,?,FALSE)')->execute([$id,$this->tenant,$this->actor,'k-' . $id,'Contact','combined',
                '{"products":["email","phone"],"refresh":true,"sources":[2,3]}',$state,gmdate('Y-m-d H:i:s')]);
        $pdo->prepare('INSERT INTO enrichment_batch_item (id,batch_id,tenant_id,target_id,position,state,deleted) VALUES (?,?,?,?,0,?,FALSE)')
            ->execute([RecordId::generate(),$id,$this->tenant,$target,$itemState]);
        return $id;
    }

    private function states(string $batch): array
    {
        $q = $this->em->getPDO()->prepare('SELECT b.state,i.state AS item,i.reason,b.completed_at FROM enrichment_batch b
            JOIN enrichment_batch_item i ON i.batch_id=b.id WHERE b.id=?');
        $q->execute([$batch]); $row = $q->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($row['completed_at']);
        return [$row['state'],$row['item'],$row['reason']];
    }
}
