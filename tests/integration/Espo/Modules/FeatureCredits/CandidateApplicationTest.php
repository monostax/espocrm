<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureCredits;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\{Conflict,Forbidden};
use Espo\Core\Utils\{Crypt,File\Manager};
use Espo\Modules\FeatureCredits\Accounting\{Clock,Funding,GrantInput,ReservationInput,Reservations,WalletLock,FixedSettlement,RecordId};
use Espo\Modules\FeatureCredits\Enrichment\{Access,ActorAccess,ApplicationInput,CandidateApplication,CandidateCache,Catalog,ProviderAvailability,Purchases,QuoteInput,Quotes,RoutePlanner};
use Espo\Modules\FeatureRecordKnowledge\Services\Relations;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Full disposable Espo runtime, real ORM/ACL/hooks/record and manual relation services. */
class CandidateApplicationTest extends TestCase
{
    private static string $source;
    private static string $runtime;
    private Application $app;
    private EntityManager $em;
    private string $tenant;
    private string $team;
    private string $actor;
    private string $account;
    private const NOW = '2026-10-13 00:00:00';

    private function dropCheck(PDO $pdo,string $table,string $name): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' &&
            !str_contains($pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB');
        $pdo->exec('ALTER TABLE ' . $table . ' DROP ' . ($mysql ? 'CHECK' : 'CONSTRAINT') . ' ' . $name);
    }

    public static function setUpBeforeClass(): void
    {
        if (!getenv('TEST_DATABASE_NAME')) self::markTestSkipped('Requires an empty disposable CRM application database.');
        self::$source = getcwd();
        self::$runtime = '/tmp/opencode/credit-application-' . bin2hex(random_bytes(4));
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
        require_once self::$source . '/tests/unit/Espo/Modules/FeatureCredits/EnrichmentFixtures.php';
    }

    protected function setUp(): void
    {
        chdir(self::$runtime);
        $system = new Application(new ApplicationParams(noErrorHandler: true)); $system->setupSystemUser();
        $em = $system->getContainer()->get('entityManager');
        $tenant = $em->createEntity('Tenant',['name' => 'Application fixture ' . bin2hex(random_bytes(4))]);
        $this->tenant = $tenant->getId();
        $team = $em->createEntity('Team',['name' => 'Application team','tenantId' => $this->tenant]); $this->team = $team->getId();
        $user = $em->createEntity('User',['userName' => 'apply-' . $this->tenant . '@example.test',
            'emailAddress' => 'apply-' . $this->tenant . '@example.test','type' => 'admin','isActive' => true]);
        $this->actor = $user->getId();
        $em->getRDBRepository('Tenant')->getRelation($tenant,'users')->relate($user);
        $this->app = new Application(new ApplicationParams(noErrorHandler: true)); $this->app->getContainer()->set('user',$em->getEntityById('User',$this->actor));
        $this->em = $this->app->getContainer()->get('entityManager');
        $account = $this->em->createEntity('Account',['name' => 'Company','tenantId' => $this->tenant,'teamsIds' => [$this->team],
            'cnpj' => '11222333000181','website' => 'https://fixture.com.br','linkedinUrl' => 'https://www.linkedin.com/company/fixture']);
        $this->account = $account->getId();
    }

    private function service(string $class): object { return $this->app->getContainer()->get('injectableFactory')->create($class); }

    /** Fixture-only quote funding bridge: all purchase/accounting services are real; suppliers never called. */
    private function purchased(array $values = [],string $product = 'socios',?string $contactId = null): array
    {
        $clock = $this->createStub(Clock::class); $clock->method('now')->willReturn(self::NOW);
        $access = $this->service(Access::class);
        $actors = $this->createStub(ActorAccess::class); $actors->method('forActor')->willReturn($access);
        $available = $this->createStub(ProviderAvailability::class);
        $products = $product === 'emailPhone' ? ['email','phone'] : [$product];
        $provider = match ($product) { 'socios' => 'cpfcnpj','cnpj' => 'registrobr',default => 'apollo' };
        $available->method('inspect')->willReturn([$provider => array_fill_keys($products,str_repeat('a',64))]);
        $lock = new WalletLock($this->em,$clock); $funding = new Funding($lock); $reservations = new Reservations($lock,$funding);
        $funding->grant(new GrantInput($this->tenant,'purchase','fixture-funding','60',self::NOW,null,(object) ['fixture' => true]));
        $rate = RecordId::generate();
        $this->em->getPDO()->prepare('INSERT INTO tenant_credit_billing_rate (id,tenant_id,version,effective_from,currency,credit_unit_price,monthly_credits,created_at)
            VALUES (?,?,?,?,?,?,?,?)')->execute([$rate,$this->tenant,$rate,'2026-10-01 00:00:00','USD','1.00000000','0.0000',self::NOW]);
        $catalog = new Catalog($this->em,$clock);
        $definition = \tests\unit\Espo\Modules\FeatureCredits\EnrichmentFixtures::discoveryDefinition('application-fixture','2026-10-13 00:00:00');
        $publicationClock = $this->createStub(Clock::class); $publicationClock->method('now')->willReturn('2026-10-12 00:00:00');
        (new Catalog($this->em,$publicationClock))->publish((object) $definition,$this->actor);
        $quotes = new Quotes($this->em,$clock,$access,$catalog,$available,new RoutePlanner(),$lock,$reservations);
        $quote = $quotes->quote(new QuoteInput((object) ['tenantId' => $this->tenant,'targetType' => $contactId === null ? 'Account' : 'Contact','targetId' => $contactId ?? $this->account,
            'products' => $products,'requestKey' => 'fixture-purchase','waterfall' => false,'refresh' => false,
            ...(in_array($product,['employees','socios'],true) ? ['count' => 1] : [])]));
        $quotes->confirm($this->tenant,$quote['id']);
        $hold = $reservations->reserve(new ReservationInput($this->tenant,'enrichment','enrichment:' . $quote['id'],
            'enrichment:' . $quote['id'],$rate,$quote['maximum']));
        $this->em->getPDO()->prepare('UPDATE enrichment_quote SET usage_id=?,admitted_at=? WHERE id=?')->execute([$hold['usageId'],self::NOW,$quote['id']]);
        $crypt = $this->app->getContainer()->get('injectableFactory')->create(Crypt::class);
        if (in_array($product,['email','phone','company','emailPhone'],true)) {
            $supplier = $this->createStub(\Espo\Modules\FeatureCredits\Enrichment\Providers\Supplier::class);
            $supplier->method('revision')->willReturn(str_repeat('a',64));
            $supplier->method('lookup')->willReturnCallback(static fn ($type,$field) =>
                new \Espo\Modules\FeatureCredits\Enrichment\Providers\Result('match',$product === 'emailPhone' ? $values[$field] : $values));
            $registry = $this->createStub(\Espo\Modules\FeatureCredits\Enrichment\Providers\Registry::class); $registry->method('get')->willReturn($supplier);
            $execution = new \Espo\Modules\FeatureCredits\Enrichment\Execution($this->em,$lock,$actors,$registry,new FixedSettlement($funding),$crypt,$clock);
            $execution->run($this->tenant,$quote['id']);
            $q = $this->em->getPDO()->prepare('SELECT id,product FROM enrichment_attempt WHERE quote_id=? AND purchased_at IS NOT NULL'); $q->execute([$quote['id']]);
            $receipts = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $receipts[$row['product']] = $this->service(\Espo\Modules\FeatureCredits\Enrichment\FieldReview::class)->read($this->tenant,$row['id'])['receipt'];
            return $product === 'emailPhone' ? $receipts : $receipts[$product];
        }
        $cache = new CandidateCache($lock,$crypt,$actors,$available);
        $facts = $product === 'employees' ? ['name' => 'Fixture Person','currentEmployment' => true,'title' => 'Director',...$values] :
            ($product === 'cnpj' ? $values : ['name' => 'Fixture Person','role' => 'administrator','cpf' => '52998224725',...$values]);
        $source = $cache->store($this->tenant,$quote['id'],$provider,hash('sha256','source'),hash('sha256','person'),self::NOW,'2026-10-14 00:00:00',$facts,[],true);
        $purchases = new Purchases($lock,new FixedSettlement($funding),$actors,$available,$crypt);
        $receipt = $purchases->purchaseCached($this->tenant,$quote['id'],$source['id']); $purchases->close($this->tenant,$quote['id']);
        return $receipt;
    }

    private function input(array $receipt,bool $create = true): ApplicationInput
    {
        return new ApplicationInput((object) ['tenantId' => $this->tenant,'purchaseId' => $receipt['id'],'createIfUnmatched' => $create,
            'contactData' => (object) ['firstName' => 'Fixture','lastName' => 'Person','teamsIds' => [$this->team]]]);
    }

    private function countRecords(string $table): int
    {
        $q = $this->em->getPDO()->prepare("SELECT COUNT(*) FROM $table WHERE tenant_id=? AND deleted=FALSE");
        $q->execute([$this->tenant]); return (int) $q->fetchColumn();
    }

    public function testCombinedFieldApplicationAcceptsOnlyReceiptBackedSiblingIdentityChanges(): void
    {
        $purchases = $this->purchased(['email' => ['selected@example.test'],'phone' => ['+5511999991111']],'emailPhone');
        $service = $this->service(\Espo\Modules\FeatureCredits\Enrichment\FieldApplication::class);
        $review = $this->service(\Espo\Modules\FeatureCredits\Enrichment\FieldReview::class);
        $email = (object) ['tenantId' => $this->tenant,'id' => $purchases['email']['id'],'source' => 'field',
            'fields' => (object) ['emailAddress' => 'selected@example.test'],'expected' => (object) ['emailAddress' => null]];
        $phone = (object) ['tenantId' => $this->tenant,'id' => $purchases['phone']['id'],'source' => 'field',
            'fields' => (object) ['phoneNumber' => '+5511999991111'],'expected' => (object) ['phoneNumber' => null]];
        $pdo = $this->em->getPDO();
        $snapshot = $pdo->query('SELECT snapshot FROM enrichment_quote WHERE id=' . $pdo->quote($purchases['email']['quoteId']))->fetchColumn();
        $first = $service->apply($email);
        $this->assertFalse($review->read($this->tenant,$purchases['phone']['id'])['identityChanged']);
        $second = $service->apply($phone);
        $this->assertSame($first,$service->apply($email));
        $this->assertSame($second,$service->apply($phone));
        $this->assertFalse($review->read($this->tenant,$purchases['email']['id'])['identityChanged']);
        $this->assertSame($snapshot,$pdo->query('SELECT snapshot FROM enrichment_quote WHERE id=' . $pdo->quote($purchases['email']['quoteId']))->fetchColumn());
        $account = $this->em->getEntityById('Account',$this->account); $account->set('name','Unrelated manual change'); $this->em->saveEntity($account);
        $this->assertTrue($review->read($this->tenant,$purchases['email']['id'])['identityChanged']);
        try { $service->apply($email); $this->fail('Manual identity change was accepted as a sibling application.'); } catch (Conflict) {}
        $this->assertSame([], $this->service(\Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit::class)->inspect($this->tenant)['findings']);
    }

    public function testSocioApplicationAcceptsCompanyEditsAndChangedRetryFormWithoutDuplicatingRecords(): void
    {
        $purchase = $this->purchased(['role' => 'Sócio-Administrador']);
        $account = $this->em->getEntityById('Account',$this->account);
        $account->set('cnpj','04252011000110'); $this->em->saveEntity($account);
        $reviewer = $this->service(\Espo\Modules\FeatureCredits\Enrichment\PurchasedReview::class);
        $review = $reviewer->read($this->tenant,$purchase['id']);
        $this->assertTrue($review['identityChanged']);
        $this->assertTrue($review['application']['available']);
        $this->assertNull($review['application']['reason']);
        $service = $this->service(CandidateApplication::class); $input = $this->input($purchase);
        $receipt = $service->apply($input);
        $this->assertCount(2,$receipt['relationIds']);
        $this->assertSame($receipt,$service->apply($input));
        $retry = new ApplicationInput((object) ['tenantId' => $this->tenant,'purchaseId' => $purchase['id'],
            'createIfUnmatched' => false,'contactData' => (object) ['lastName' => 'Changed retry form']]);
        $this->assertSame($receipt,$service->apply($retry));
        $this->assertSame(1,$this->countRecords('contact'));
        $this->assertSame(2,$this->countRecords('record_relation'));
        $this->assertSame('Person',$this->em->getEntityById('Contact',$receipt['contactId'])->get('lastName'));
        $review = $this->service(\Espo\Modules\FeatureCredits\Enrichment\PurchasedReview::class)->read($this->tenant,$purchase['id']);
        $this->assertSame('Sócio-Administrador',$review['values']->role);
        $this->assertTrue($review['application']['creationForm']['available']);
        $this->assertContains('lastName',array_column($review['application']['creationForm']['fields'],'name'));
    }

    public function testReviewedCreationFormOffersTenantTeamAndCreatesThroughNormalRecords(): void
    {
        $tenant = $this->em->getEntityById('Tenant',$this->tenant);
        $tenant->set('baseUserTeamId',$this->team); $this->em->saveEntity($tenant);
        $purchase = $this->purchased();
        $review = $this->service(\Espo\Modules\FeatureCredits\Enrichment\PurchasedReview::class)->read($this->tenant,$purchase['id']);
        $form = $review['application']['creationForm'];
        $this->assertTrue($form['available']);
        $this->assertContains($this->team,array_column($form['teams'],'id'));
        $receipt = $this->service(CandidateApplication::class)->apply(new ApplicationInput((object) [
            'tenantId' => $this->tenant,'purchaseId' => $purchase['id'],'createIfUnmatched' => true,
            'contactData' => (object) ['lastName' => 'Reviewed human choice','teamsIds' => [$this->team]],
        ]));
        $contact = $this->em->getEntityById('Contact',$receipt['contactId']);
        $this->assertSame('Reviewed human choice',$contact->get('lastName'));
        $this->assertSame($this->tenant,$contact->get('tenantId'));
    }

    public function testExplicitRegistrantApplicationAndReplayNeverOverwriteLaterManualCnpj(): void
    {
        $purchase = $this->purchased(['cnpj' => '04252011000110'],'cnpj');
        $input = (object) ['tenantId' => $this->tenant,'id' => $purchase['id'],'source' => 'rdap',
            'fields' => (object) ['cnpj' => '04252011000110'],'expected' => (object) ['cnpj' => '11222333000181']];
        $service = $this->service(\Espo\Modules\FeatureCredits\Enrichment\FieldApplication::class);
        $receipt = $service->apply($input); $this->assertSame($receipt,$service->apply($input));
        $this->assertSame('04252011000110',$this->em->getEntityById('Account',$this->account)->get('cnpj'));
        $this->assertSame(['cnpj'],$receipt['fields']);
        $account = $this->em->getEntityById('Account',$this->account); $account->set('cnpj','11222333000181'); $this->em->saveEntity($account);
        try { $service->apply($input); $this->fail('Retry overwrote manual CNPJ.'); } catch (Conflict) {}
        $this->assertSame('11222333000181',$this->em->getEntityById('Account',$this->account)->get('cnpj'));
        $this->assertSame([], $this->service(\Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit::class)->inspect($this->tenant)['findings']);
    }

    public function testExplicitCompanyFieldsAreAtomicWithReceiptAndRequirePurchasedValues(): void
    {
        $purchase = $this->purchased(['name' => 'Purchased company','description' => 'Purchased description'],'company');
        $input = (object) ['tenantId' => $this->tenant,'id' => $purchase['id'],'source' => 'field',
            'fields' => (object) ['name' => 'Purchased company','description' => 'Purchased description'],
            'expected' => (object) ['name' => 'Company','description' => null]];
        $service = $this->service(\Espo\Modules\FeatureCredits\Enrichment\FieldApplication::class); $pdo = $this->em->getPDO();
        $pdo->exec("ALTER TABLE enrichment_attempt ADD CONSTRAINT injected_field_application CHECK (id<>'" . $purchase['id'] . "' OR application_receipt IS NULL)");
        try { $service->apply($input); $this->fail('Field receipt failure ignored.'); }
        catch (PDOException $e) { $this->assertStringContainsString('injected_field_application',$e->getMessage()); }
        finally { $this->dropCheck($pdo,'enrichment_attempt','injected_field_application'); }
        $this->assertSame('Company',$this->em->getEntityById('Account',$this->account)->get('name'));
        $this->assertNull($this->em->getEntityById('Account',$this->account)->get('description'));
        $invalid = clone $input; $invalid->fields = (object) ['name' => 'Not purchased']; $invalid->expected = (object) ['name' => 'Company'];
        try { $service->apply($invalid); $this->fail('Unpurchased value applied.'); } catch (\InvalidArgumentException) {}
        $receipt = $service->apply($input); $this->assertSame($receipt,$service->apply($input));
        $this->assertSame('Purchased company',$this->em->getEntityById('Account',$this->account)->get('name'));
        $this->assertSame([], $this->service(\Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit::class)->inspect($this->tenant)['findings']);
    }

    public function testFieldApplicationRequiresHumanAndRejectsStaleDestination(): void
    {
        $purchase = $this->purchased(['description' => 'Purchased description'],'company');
        $input = (object) ['tenantId' => $this->tenant,'id' => $purchase['id'],'source' => 'field',
            'fields' => (object) ['description' => 'Purchased description'],'expected' => (object) ['description' => null]];
        $service = $this->service(\Espo\Modules\FeatureCredits\Enrichment\FieldApplication::class);
        $this->app->getContainer()->get('user')->set('type','api');
        try { $service->apply($input); $this->fail('API actor applied fields.'); } catch (Forbidden) {}
        $this->app->getContainer()->get('user')->set('type','admin');
        $account = $this->em->getEntityById('Account',$this->account); $account->set('description','Manual description'); $this->em->saveEntity($account);
        try { $service->apply($input); $this->fail('Concurrent manual field overwritten.'); } catch (Conflict) {}
        $this->assertSame('Manual description',$this->em->getEntityById('Account',$this->account)->get('description'));
    }

    public function testOwnContactEmailApplicationUsesNormalRecordServiceAndFreeReplay(): void
    {
        $this->assertContactFieldApplication('email','emailAddress','selected@example.test');
    }

    public function testOwnContactPhoneApplicationUsesNormalRecordServiceAndFreeReplay(): void
    {
        $this->assertContactFieldApplication('phone','phoneNumber','+5511999991111');
    }

    private function assertContactFieldApplication(string $product,string $field,string $value): void
    {
        $contact = $this->em->createEntity('Contact',['lastName' => 'Subject','cpf' => '52998224725','tenantId' => $this->tenant,'teamsIds' => [$this->team]]);
        $purchase = $this->purchased([$value],$product,$contact->getId());
        $input = (object) ['tenantId' => $this->tenant,'id' => $purchase['id'],'source' => 'field',
            'fields' => (object) [$field => $value],'expected' => (object) [$field => null]];
        $service = $this->service(\Espo\Modules\FeatureCredits\Enrichment\FieldApplication::class);
        $receipt = $service->apply($input); $this->assertSame($receipt,$service->apply($input));
        $this->assertSame($value,$this->em->getEntityById('Contact',$contact->getId())->get($field));
        $this->assertSame([], $this->service(\Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit::class)->inspect($this->tenant)['findings']);
    }

    public function testIndependentFieldSelectionAndErasureCannotRestorePurchasedData(): void
    {
        $purchase = $this->purchased(['description' => 'Purchased description'],'company');
        $apply = ['tenantId' => $this->tenant,'id' => $purchase['id'],'source' => 'field',
            'fields' => (object) ['description' => 'Purchased description'],'expected' => (object) ['description' => null]];
        $erase = ['source' => 'erase','type' => 'Account','id' => $this->account,'fields' => ['description']];
        $results = $this->applicationRace([$apply,$erase]);
        $staleMariaRead = ($results[1]['error'] ?? null) === PDOException::class &&
            str_contains($this->em->getPDO()->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB') &&
            str_contains($results[1]['message'] ?? '',"General error: 1020 Record has changed since last read in table 'enrichment_attempt'");
        if (($results[1]['error'] ?? null) === Conflict::class || $staleMariaRead) {
            // Espo optimistic concurrency or MariaDB's stale-row rejection aborts the erasure.
            // Verify cleanup rolled back, then retry erasure from a fresh read; never accept partial erasure.
            if (!$staleMariaRead) $this->assertSame('Record changed or removed. Read the latest version before saving.',$results[1]['message']);
            $this->assertFalse($this->service(\Espo\Modules\FeatureCredits\Enrichment\FieldReview::class)->read($this->tenant,$purchase['id'])['erased']);
            $this->service(\Espo\Tools\DataPrivacy\Erasor::class)->erase('Account',$this->account,['description']);
        } else {
            $this->assertSame(['result' => ['erased' => true]],$results[1]);
        }
        $this->assertTrue(isset($results[0]['result']) || ($results[0]['error'] ?? null) === Conflict::class,json_encode($results));
        $this->assertNull($this->em->getEntityById('Account',$this->account)->get('description'));
        $read = $this->service(\Espo\Modules\FeatureCredits\Enrichment\FieldReview::class)->read($this->tenant,$purchase['id']);
        $this->assertTrue($read['erased']); $this->assertSame([],(array) $read['values']);
        $this->assertSame([], $this->service(\Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit::class)->inspect($this->tenant)['findings']);
    }

    public function testIndependentDuplicateFieldSelectionsCommitOneApplicationReceipt(): void
    {
        $purchase = $this->purchased(['description' => 'Purchased description'],'company');
        $input = ['tenantId' => $this->tenant,'id' => $purchase['id'],'source' => 'field',
            'fields' => (object) ['description' => 'Purchased description'],'expected' => (object) ['description' => null]];
        $results = $this->applicationRace([$input,$input]);
        $this->assertArrayHasKey('result',$results[0],json_encode($results)); $this->assertSame($results[0],$results[1]);
        $this->assertSame('Purchased description',$this->em->getEntityById('Account',$this->account)->get('description'));
        $this->assertSame([], $this->service(\Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit::class)->inspect($this->tenant)['findings']);
    }

    private function applicationRace(array $inputs): array
    {
        $pdo = $this->em->getPDO(); $workers = []; $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT id FROM tenant WHERE id=? FOR UPDATE'); $q->execute([$this->tenant]);
        try {
            foreach ($inputs as $input) {
                $process = proc_open([PHP_BINARY,self::$source . '/tests/integration/Espo/Modules/FeatureCredits/application-worker.php',
                    self::$runtime,$this->actor,base64_encode(json_encode($input,JSON_THROW_ON_ERROR))],
                    [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],$pipes);
                $this->assertIsResource($process); $workers[] = [$process,$pipes]; stream_set_timeout($pipes[1],30);
                $this->assertSame("ready\n",fgets($pipes[1]));
            }
            foreach ($workers as [$process,$pipes]) {
                $read = [$pipes[1]]; $write = $except = [];
                $this->assertSame(0,stream_select($read,$write,$except,0,150000));
            }
            $pdo->commit(); $results = [];
            foreach ($workers as [$process,$pipes]) {
                $results[] = json_decode(fgets($pipes[1]),true,32,JSON_THROW_ON_ERROR);
                $this->assertSame('',stream_get_contents($pipes[2]));
            }
            return $results;
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($workers as [$process,$pipes]) {
                if (proc_get_status($process)['running']) proc_terminate($process);
                foreach ($pipes as $pipe) fclose($pipe); proc_close($process);
            }
        }
    }

    public function testAtomicCreateAndReceiptFailureRollbackThenFreeReplay(): void
    {
        $purchase = $this->purchased(); $input = $this->input($purchase); $pdo = $this->em->getPDO();
        $pdo->exec("ALTER TABLE enrichment_purchase ADD CONSTRAINT injected_application CHECK (id<>'" . $purchase['id'] . "' OR application_receipt IS NULL)");
        try { $this->service(CandidateApplication::class)->apply($input); $this->fail('Receipt write failure ignored.'); }
        catch (PDOException $e) { $this->assertStringContainsString('injected_application',$e->getMessage()); }
        finally { $this->dropCheck($pdo,'enrichment_purchase','injected_application'); }
        $this->assertSame(0,$this->countRecords('contact')); $this->assertSame(0,$this->countRecords('record_relation'));
        $result = $this->service(CandidateApplication::class)->apply($input);
        $this->assertSame($result,$this->service(CandidateApplication::class)->apply($input));
        $this->assertSame(1,$this->countRecords('contact')); $this->assertSame(1,$this->countRecords('record_relation'));
        $contact = $this->em->getEntityById('Contact',$result['contactId']); $this->assertSame('52998224725',$contact->get('cpf'));
        $relation = $this->em->getEntityById('RecordRelation',$result['relationIds'][0]);
        $this->assertSame('manual',$relation->get('origin')); $this->assertSame('confirmed',$relation->get('status'));
        $this->assertSame($this->actor,$relation->get('decidedById')); $this->assertSame('builtin:administers',$relation->get('predicate'));
        $this->assertStringNotContainsString('52998224725',json_encode($relation->get('qualifiers')));
        $q = $pdo->prepare("SELECT COUNT(*) FROM credit_transaction WHERE tenant_id=? AND type='debit'"); $q->execute([$this->tenant]);
        $this->assertSame(1,(int) $q->fetchColumn());
        $this->assertSame([], $this->service(\Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit::class)->inspect($this->tenant)['findings']);
    }

    public function testExistingContactPreservesPrimaryAccountManualRoleAndRejectedRelation(): void
    {
        $purchase = $this->purchased(['role' => 'owner_administrator','ownershipPercentage' => '25']);
        $other = $this->em->createEntity('Account',['name' => 'Primary','tenantId' => $this->tenant]);
        $contact = $this->em->createEntity('Contact',['firstName' => 'Existing','lastName' => 'Person','cpf' => '52998224725',
            'tenantId' => $this->tenant,'accountId' => $other->getId(),'teamsIds' => [$this->team]]);
        $this->em->getRDBRepository('Contact')->getRelation($contact,'accounts')->relateById($this->account);
        $this->em->getPDO()->prepare('UPDATE account_contact SET role=? WHERE account_id=? AND contact_id=?')->execute(['Manual role',$this->account,$contact->getId()]);
        $old = $this->service(Relations::class)->submit((object) ['tenantId' => $this->tenant,'subjectType' => 'Contact','subjectId' => $contact->getId(),
            'objectType' => 'Account','objectId' => $this->account,'predicate' => 'owns','qualifiers' => (object) ['role' => 'owner'],'idempotencyKey' => 'old'],true);
        $this->em->getPDO()->prepare("UPDATE record_relation SET status='rejected' WHERE id=?")->execute([$old['id']]);
        $result = $this->service(CandidateApplication::class)->apply($this->input($purchase,false));
        $this->assertSame($contact->getId(),$result['contactId']); $this->assertCount(2,$result['relationIds']);
        $this->assertSame($other->getId(),$this->em->getEntityById('Contact',$contact->getId())->get('accountId'));
        $q = $this->em->getPDO()->prepare('SELECT role FROM account_contact WHERE account_id=? AND contact_id=?');
        $q->execute([$this->account,$contact->getId()]); $this->assertSame('Manual role',$q->fetchColumn());
        $this->assertSame('rejected',$this->em->getEntityById('RecordRelation',$old['id'])->get('status'));
    }

    public function testEmployeeCreationUsesOwnLinkedInAndDeletedMappingBlocksReplacement(): void
    {
        $purchase = $this->purchased(['linkedinUrl' => 'https://www.linkedin.com/in/fixture-person'],'employees');
        $input = $this->input($purchase); $result = $this->service(CandidateApplication::class)->apply($input);
        $this->assertSame('builtin:works_at',$this->em->getEntityById('RecordRelation',$result['relationIds'][0])->get('predicate'));
        $this->assertSame(1,$this->countRecords('contact_channel_identity'));
        $this->em->removeEntity($this->em->getEntityById('Contact',$result['contactId']));
        try { $this->service(CandidateApplication::class)->apply($input); $this->fail('Deleted mapping silently replaced.'); } catch (Conflict) {}
        $this->assertSame(0,$this->countRecords('contact'));
        $q = $this->em->getPDO()->prepare('SELECT erased_at FROM enrichment_purchase WHERE tenant_id=?'); $q->execute([$this->tenant]);
        $this->assertNotNull($q->fetchColumn());
    }

    public function testNormalPrivacyErasureScrubsUnappliedExactCandidateAndPreservesCharge(): void
    {
        $purchase = $this->purchased();
        $contact = $this->em->createEntity('Contact',['lastName' => 'Privacy subject','cpf' => '52998224725',
            'tenantId' => $this->tenant,'teamsIds' => [$this->team]]);
        $erasor = $this->service(\Espo\Tools\DataPrivacy\Erasor::class);
        $erasor->erase('Contact',$contact->getId(),['cpf']);
        $this->assertNull($this->em->getEntityById('Contact',$contact->getId())->get('cpf'));
        $read = $this->service(\Espo\Modules\FeatureCredits\Enrichment\PurchasedReview::class)->read($this->tenant,$purchase['id']);
        $this->assertTrue($read['erased']); $this->assertSame([],(array) $read['values']);
        $this->assertSame(array_diff_key($purchase,array_flip(['provider','policyId'])) + ['source' => 1],$read['receipt']);
        $q = $this->em->getPDO()->prepare('SELECT payload_ciphertext FROM enrichment_candidate_cache WHERE tenant_id=?'); $q->execute([$this->tenant]);
        $this->assertNull($q->fetchColumn());
        try { $this->service(CandidateApplication::class)->apply($this->input($purchase)); $this->fail('Erased candidate recreated.'); } catch (Conflict) {}
        $this->assertSame([], $this->service(\Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit::class)->inspect($this->tenant)['findings']);
    }

    public function testPrivacyFailureRollsBackNormalRecordAndPurchasedErasureTogether(): void
    {
        $purchase = $this->purchased(); $result = $this->service(CandidateApplication::class)->apply($this->input($purchase));
        $pdo = $this->em->getPDO();
        $pdo->exec("ALTER TABLE contact ADD CONSTRAINT injected_erasure CHECK (id<>'" . $result['contactId'] . "' OR cpf IS NOT NULL)");
        try { $this->service(\Espo\Tools\DataPrivacy\Erasor::class)->erase('Contact',$result['contactId'],['cpf']); $this->fail('Erasure failure ignored.'); }
        catch (PDOException $e) { $this->assertStringContainsString('injected_erasure',$e->getMessage()); }
        finally { $this->dropCheck($pdo,'contact','injected_erasure'); }
        $this->assertSame('52998224725',$this->em->getEntityById('Contact',$result['contactId'])->get('cpf'));
        $read = $this->service(\Espo\Modules\FeatureCredits\Enrichment\PurchasedReview::class)->read($this->tenant,$purchase['id']);
        $this->assertFalse($read['erased']); $this->assertSame('52998224725',$read['values']->cpf);
    }

    public function testApiActorCannotApplyAndUnmatchedCreationRequiresExplicitConsent(): void
    {
        $purchase = $this->purchased();
        try { $this->service(CandidateApplication::class)->apply($this->input($purchase,false)); $this->fail('Implicit Contact creation.'); } catch (\Espo\Core\Exceptions\BadRequest) {}
        $incomplete = new ApplicationInput((object) ['tenantId' => $this->tenant,'purchaseId' => $purchase['id'],'createIfUnmatched' => true]);
        try { $this->service(CandidateApplication::class)->apply($incomplete); $this->fail('Mandatory Contact name invented.'); }
        catch (\Espo\Core\Exceptions\BadRequest) {}
        $user = $this->app->getContainer()->get('user'); $user->set('type','api');
        try { $this->service(CandidateApplication::class)->apply($this->input($purchase)); $this->fail('API actor applied candidate.'); } catch (Forbidden) {}
        $this->assertSame(0,$this->countRecords('contact'));
    }

    public function testIndependentConcurrentApplicationCreatesOneContactLinkAndRelation(): void
    {
        $purchase = $this->purchased(); $input = $this->input($purchase); $pdo = $this->em->getPDO();
        $workers = []; $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT id FROM tenant WHERE id=? FOR UPDATE'); $q->execute([$this->tenant]);
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY,self::$source . '/tests/integration/Espo/Modules/FeatureCredits/application-worker.php',
                    self::$runtime,$this->actor,base64_encode(json_encode($input->data,JSON_THROW_ON_ERROR))],
                    [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],$pipes);
                $this->assertIsResource($process); $workers[] = [$process,$pipes]; stream_set_timeout($pipes[1],30);
                $this->assertSame("ready\n",fgets($pipes[1]));
            }
            foreach ($workers as [$process,$pipes]) {
                $read = [$pipes[1]]; $write = $except = [];
                $this->assertSame(0,stream_select($read,$write,$except,0,150000));
            }
            $pdo->commit(); $results = [];
            foreach ($workers as [$process,$pipes]) {
                $results[] = json_decode(fgets($pipes[1]),true,32,JSON_THROW_ON_ERROR);
                $this->assertSame('',stream_get_contents($pipes[2]));
            }
            $this->assertArrayHasKey('result',$results[0],json_encode($results)); $this->assertSame($results[0],$results[1]);
            $this->assertSame(1,$this->countRecords('contact')); $this->assertSame(1,$this->countRecords('record_relation'));
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($workers as [$process,$pipes]) {
                if (proc_get_status($process)['running']) proc_terminate($process);
                foreach ($pipes as $pipe) fclose($pipe); proc_close($process);
            }
        }
    }

    public function testInactiveCompatibilityLinkAndDifferentManualQualifiersAreReusedWithoutConflict(): void
    {
        $purchase = $this->purchased();
        $contact = $this->em->createEntity('Contact',['lastName' => 'Existing','cpf' => '52998224725','tenantId' => $this->tenant,
            'accountId' => $this->account,'teamsIds' => [$this->team]]);
        $pdo = $this->em->getPDO();
        $q = $pdo->prepare('UPDATE account_contact SET is_inactive=TRUE WHERE contact_id=? AND account_id=?'); $q->execute([$contact->getId(),$this->account]);
        $old = $this->service(Relations::class)->submit((object) ['tenantId' => $this->tenant,'subjectType' => 'Contact','subjectId' => $contact->getId(),
            'objectType' => 'Account','objectId' => $this->account,'predicate' => 'administers',
            'qualifiers' => (object) ['role' => 'Manual role'],'idempotencyKey' => 'manual-conflict'],true);
        $result = $this->service(CandidateApplication::class)->apply($this->input($purchase));
        $this->assertSame($contact->getId(),$result['contactId']);
        $this->assertSame([$old['id']],$result['relationIds']);
        $this->assertSame(1,$this->countRecords('record_relation'));
        $this->assertSame('Manual role',$this->em->getEntityById('RecordRelation',$old['id'])->get('qualifiers')->role);
        $q = $pdo->prepare('SELECT is_inactive FROM account_contact WHERE contact_id=? AND account_id=?');
        $q->execute([$contact->getId(),$this->account]); $this->assertTrue((bool) $q->fetchColumn());
        $q = $pdo->prepare('SELECT application_receipt FROM enrichment_purchase WHERE id=?'); $q->execute([$purchase['id']]); $this->assertNotNull($q->fetchColumn());
    }

    public function testPaidReviewDoesNotConferContactWritePermission(): void
    {
        $purchase = $this->purchased();
        $factory = $this->reviewOnlyFactory();
        $review = $factory->create(\Espo\Modules\FeatureCredits\Enrichment\PurchasedReview::class)->read($this->tenant,$purchase['id']);
        $this->assertSame(array_diff_key($purchase,array_flip(['provider','policyId'])) + ['source' => 1],$review['receipt']);
        $this->assertFalse($review['application']['available']);
        try { $factory->create(CandidateApplication::class)->apply($this->input($purchase)); $this->fail('Purchaser created Contact without permission.'); }
        catch (Forbidden) {}
        $this->assertSame(0,$this->countRecords('contact'));
    }

    public function testFieldHistoryAndReviewDoNotGrantWriteAccess(): void
    {
        $purchase = $this->purchased(['description' => 'Purchased description'],'company');
        $factory = $this->reviewOnlyFactory();
        $review = $factory->create(\Espo\Modules\FeatureCredits\Enrichment\FieldReview::class)->read($this->tenant,$purchase['id']);
        $this->assertSame($purchase,$review['receipt']); $this->assertFalse($review['application']['available']);
        $history = $factory->create(\Espo\Modules\FeatureCredits\Enrichment\PurchasedHistory::class)->read(
            ['tenantId' => $this->tenant,'targetType' => 'Account','targetId' => $this->account,'product' => 'company']);
        $this->assertSame($purchase,$history['list'][0]['receipt']);
        $input = (object) ['tenantId' => $this->tenant,'id' => $purchase['id'],'source' => 'field',
            'fields' => (object) ['description' => 'Purchased description'],'expected' => (object) ['description' => null]];
        try { $factory->create(\Espo\Modules\FeatureCredits\Enrichment\FieldApplication::class)->apply($input); $this->fail('Reviewer wrote a field.'); } catch (Forbidden) {}
        $this->assertNull($this->em->getEntityById('Account',$this->account)->get('description'));
        $this->service(\Espo\Modules\FeatureCredits\Enrichment\FieldApplication::class)->apply($input);
        $history = $factory->create(\Espo\Modules\FeatureCredits\Enrichment\PurchasedHistory::class)->read(
            ['tenantId' => $this->tenant,'targetType' => 'Account','targetId' => $this->account,'product' => 'company']);
        $this->assertTrue($history['list'][0]['application']['applied']);
        $this->assertSame($this->actor,$history['list'][0]['application']['appliedById']);
        $this->assertArrayNotHasKey('values',$history['list'][0]);
    }

    private function reviewOnlyFactory(): \Espo\Core\InjectableFactory
    {
        $role = $this->em->createEntity('Role',['name' => 'Review only','useEnrichmentCreditsPermission' => 'yes','data' => (object) [
            'Account' => (object) ['read' => 'all','create' => 'no','edit' => 'no','delete' => 'no'],
            'Contact' => (object) ['read' => 'all','create' => 'no','edit' => 'no','delete' => 'no']]]);
        $user = $this->em->createEntity('User',['emailAddress' => 'review-' . $this->tenant . '@example.test','type' => 'regular','isActive' => true]);
        $this->em->getRelation($user,'roles')->relate($role);
        $this->em->getRelation($this->em->getEntityById('Tenant',$this->tenant),'users')->relate($user);
        // Configure this disposable tenant's provisioned team roles as review-only too.
        foreach ($this->em->getRelation($user,'teams')->find() as $team) {
            foreach ($this->em->getRelation($team,'roles')->find() as $teamRole) $this->em->getRelation($team,'roles')->unrelate($teamRole);
            $this->em->getRelation($team,'roles')->relate($role);
        }
        $limited = new Application(new ApplicationParams(noErrorHandler: true));
        $limited->getContainer()->set('user',$this->em->getEntityById('User',$user->getId()));
        return $limited->getContainer()->get('injectableFactory');
    }

    public function testStrongIdentityBridgeAndChangedMappingPersistBlockingWithoutWrites(): void
    {
        $purchase = $this->purchased(['linkedinUrl' => 'https://www.linkedin.com/in/bridge']);
        $this->em->createEntity('Contact',['lastName' => 'CPF owner','cpf' => '52998224725','tenantId' => $this->tenant,'teamsIds' => [$this->team]]);
        $other = $this->em->createEntity('Contact',['lastName' => 'LinkedIn owner','tenantId' => $this->tenant,'teamsIds' => [$this->team],
            'channelIdentitiesData' => [(object) ['channelType' => 'linkedin','sourceId' => 'https://www.linkedin.com/in/bridge']]]);
        try { $this->service(CandidateApplication::class)->apply($this->input($purchase)); $this->fail('Two identity owners merged.'); } catch (Conflict) {}
        $this->assertSame(2,$this->countRecords('contact')); $this->assertSame(0,$this->countRecords('record_relation'));
        $review = $this->service(\Espo\Modules\FeatureCredits\Enrichment\PurchasedReview::class)->read($this->tenant,$purchase['id']);
        $this->assertSame('identity_conflict',$review['application']['reason']);
    }

    public function testExistingCompatibleManualRelationIsReusedAndChangedContactMappingIsNotReplaced(): void
    {
        $purchase = $this->purchased();
        $contact = $this->em->createEntity('Contact',['lastName' => 'Existing','cpf' => '52998224725','tenantId' => $this->tenant,'teamsIds' => [$this->team]]);
        $existing = $this->service(Relations::class)->submit((object) ['tenantId' => $this->tenant,'subjectType' => 'Contact','subjectId' => $contact->getId(),
            'objectType' => 'Account','objectId' => $this->account,'predicate' => 'administers',
            'qualifiers' => (object) ['role' => 'administrator'],'idempotencyKey' => 'compatible'],true);
        $input = $this->input($purchase,false); $result = $this->service(CandidateApplication::class)->apply($input);
        $this->assertSame([$existing['id']],$result['relationIds']); $this->assertSame(1,$this->countRecords('record_relation'));
        $contact = $this->em->getEntityById('Contact',$contact->getId());
        $contact->set('cpf','11144477735'); $this->em->saveEntity($contact);
        try { $this->service(CandidateApplication::class)->apply($input); $this->fail('Changed mapped Contact was silently accepted.'); } catch (Conflict) {}
        $this->assertSame(1,$this->countRecords('contact'));
        $review = $this->service(\Espo\Modules\FeatureCredits\Enrichment\PurchasedReview::class)->read($this->tenant,$purchase['id']);
        $this->assertSame('identity_conflict',$review['application']['reason']);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$source)) { chdir(self::$source); set_include_path(self::$source); }
    }
}
