<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Modules\FeatureSelfService\Jobs\RunFirstRead;
use Espo\Modules\FeatureSelfService\Tools\Onboarding\FirstRead;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceTrial;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * The first read's scheduling and wiring against a real CRM database and a fake Chatwoot. The analysis itself
 * (Prefetch) is unit tested; here no conversation exists, so nothing is sent to the Em dia backend.
 * Same runtime as ProvisioningTest; see FeatureSelfService/index.md.
 */
class FirstReadTest extends TestCase
{
    private Application $app;
    private EntityManager $em;
    private string $fake;

    protected function setUp(): void
    {
        $database = getenv('SELF_SERVICE_TEST_DATABASE');
        $this->fake = (string) getenv('FAKE_CHATWOOT_URL');

        if (!$database || $this->fake === '') {
            self::markTestSkipped('Set SELF_SERVICE_TEST_DATABASE and FAKE_CHATWOOT_URL in an isolated CRM runtime.');
        }

        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        self::assertSame($database, $this->app->getContainer()->get('config')->get('database.dbname'));
        $this->app->setupSystemUser();
        $this->em = $this->app->getContainer()->get('entityManager');

        $curl = curl_init(rtrim($this->fake, '/') . '/__reset');
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true]);
        curl_exec($curl);
        curl_close($curl);
        putenv(SelfServicePolicy::ENV . '=true');
        $this->app->getInjectableFactory()->create(SelfServicePolicy::class)->resume();
    }

    public function testOpeningOnboardingSchedulesTheFirstReadOnce(): void
    {
        $tenant = $this->tenant('Primeira Leitura');
        self::assertSame([], $this->jobs($tenant), 'Nothing is read before the workspace connects.');

        $tenant->set('onboardingStep', WorkspaceTrial::STEP_IMPORT_HISTORY);
        $this->em->saveEntity($tenant, ['silent' => true]);
        self::assertSame([], $this->jobs($tenant), 'Still importing its newest history.');

        $tenant->set('onboardingStep', WorkspaceTrial::STEP_DONE);
        $this->em->saveEntity($tenant, ['silent' => true]);
        self::assertSame([0], $this->jobs($tenant));

        $tenant->set('name', 'Primeira Leitura Renomeada');
        $this->em->saveEntity($tenant);
        self::assertSame([0], $this->jobs($tenant), 'Onboarding opens once.');
    }

    public function testWaitsForTheConversationsAndTheConsoleReadsWhatIsThereAsTheOwner(): void
    {
        $tenant = $this->tenant('Leitura Vazia');
        $tenant->set('onboardingStep', WorkspaceTrial::STEP_DONE);
        $this->em->saveEntity($tenant, ['silent' => true, 'skipHooks' => true]);
        $firstRead = $this->app->getInjectableFactory()->create(FirstRead::class);

        // No conversation synced yet: the job looks again in 15 seconds.
        self::assertNull($firstRead->run($tenant->getId(), 3));
        self::assertSame([4], $this->jobs($tenant));

        // The console doesn't wait: it reads the owner's deck as it is (empty here), through Em dia's own feeds.
        self::assertSame(['picked' => 0, 'cards' => 0, 'quiet' => 0, 'pending' => 0, 'failed' => 0, 'waiting' => []],
            $firstRead->run($tenant->getId(), wait: false));
    }

    public function testNothingIsReadForAWorkspaceThatIsNotSelfService(): void
    {
        $tenant = $this->tenant('Sem Plano');
        $tenant->set(['plan' => null, 'onboardingStep' => WorkspaceTrial::STEP_DONE]);
        $this->em->saveEntity($tenant, ['silent' => true]);

        self::assertSame([], $this->jobs($tenant));
        self::assertNull($this->app->getInjectableFactory()->create(FirstRead::class)->run($tenant->getId(), wait: false));
    }

    private function tenant(string $name): Entity
    {
        $suffix = bin2hex(random_bytes(4));
        $owner = $this->em->createEntity('User', [
            'userName' => 'owner-' . $suffix, 'type' => 'regular', 'isActive' => true,
            'lastName' => 'Owner ' . $suffix, 'emailAddress' => "owner-{$suffix}@example.com",
        ]);
        $provisioner = $this->app->getInjectableFactory()->create(Provisioner::class);
        $done = $provisioner->run($provisioner->start(new ProvisioningRequest('signup:read-' . $suffix, (string) $owner->getId(), $name))->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));

        return $this->em->getEntityById('Tenant', $done->get('tenantId'));
    }

    /** @return list<int> the attempt of each pending first read of this tenant */
    private function jobs(Entity $tenant): array
    {
        $attempts = [];
        foreach ($this->em->getRDBRepository('Job')->where(['className' => RunFirstRead::class, 'status' => 'Pending'])->find() as $job) {
            $data = (array) $job->get('data');
            if (($data['tenantId'] ?? null) === $tenant->getId()) $attempts[] = (int) $data['attempt'];
        }
        sort($attempts);

        return $attempts;
    }
}
