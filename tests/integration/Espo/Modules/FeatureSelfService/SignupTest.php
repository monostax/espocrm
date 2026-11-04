<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use Espo\Core\Api\RequestNull;
use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Authentication\Login\Data;
use Espo\Core\Utils\PasswordHash;
use Espo\Entities\User;
use Espo\Modules\FeatureSelfService\Classes\Authentication\TicketLogin;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServiceUnavailable;
use Espo\Modules\FeatureSelfService\Tools\Signup\SignupRejected;
use Espo\Modules\FeatureSelfService\Tools\Signup\SignupRequest;
use Espo\Modules\FeatureSelfService\Tools\Signup\Signups;
use Espo\Modules\FeatureSelfService\Tools\Signup\VerificationMailer;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Email-and-password signup end to end, with the verification email captured
 * instead of sent. Same isolated runtime as ProvisioningTest.
 */
class SignupTest extends TestCase
{
    private Application $app;
    private EntityManager $em;

    /** @var array<string, string> email => last code */
    private array $codes = [];

    protected function setUp(): void
    {
        $database = getenv('SELF_SERVICE_TEST_DATABASE');
        $fake = (string) getenv('FAKE_CHATWOOT_URL');

        if (!$database || $fake === '') {
            self::markTestSkipped('Set SELF_SERVICE_TEST_DATABASE and FAKE_CHATWOOT_URL in an isolated CRM runtime.');
        }

        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        self::assertSame($database, $this->app->getContainer()->get('config')->get('database.dbname'));
        $this->app->setupSystemUser();
        $this->em = $this->app->getContainer()->get('entityManager');

        putenv(SelfServicePolicy::ENV . '=true');
        $this->app->getInjectableFactory()->create(SelfServicePolicy::class)->resume();

        if (!$this->em->getRDBRepository('ChatwootPlatform')->findOne()) {
            $this->em->createEntity('ChatwootPlatform', [
                'name' => 'Fake Chatwoot', 'backendUrl' => $fake, 'frontendUrl' => $fake, 'accessToken' => 'platform-token',
            ], ['skipHooks' => true]);
        }
    }

    public function testSignupCreatesTheOwnerAndWorkspaceAndLogsInOnce(): void
    {
        $email = $this->email();
        $started = $this->signups()->begin($this->request($email, ' (11) 91234-5678 '), $this->ip());

        $result = $this->signups()->verify($started['signupId'], $started['token'], $this->codes[$email]);

        self::assertSame('ready', $result['status']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['ticket']);

        /** @var User $user */
        $user = $this->em->getRDBRepository(User::ENTITY_TYPE)->where(['userName' => $email])->findOne();
        self::assertSame(User::TYPE_REGULAR, $user->getType());
        self::assertSame('+5511912345678', $user->get('phoneNumber'));
        self::assertSame('Maria Souza', $user->getName());
        self::assertTrue($this->app->getInjectableFactory()->create(PasswordHash::class)
            ->verify('segredo-forte', (string) $user->get('password')));

        $signup = $this->em->getEntityById('SelfServiceSignup', $started['signupId']);
        $provisioning = $this->em->getEntityById('SelfServiceProvisioning', $signup->get('provisioningId'));
        self::assertSame('completed', $provisioning->get('status'));
        self::assertSame($user->getId(), $provisioning->get('ownerId'));
        self::assertSame('Clínica Signup', $this->em->getEntityById('Tenant', $provisioning->get('tenantId'))->get('name'));

        // The owner proved the email, so Chatwoot must let them straight in.
        $chatwootUser = $this->em->getRDBRepository('ChatwootUser')->where(['assignedUserId' => $user->getId()])->findOne();
        $remote = json_decode((string) file_get_contents(rtrim((string) getenv('FAKE_CHATWOOT_URL'), '/') . '/__users'), true);
        self::assertTrue($remote[(string) $chatwootUser->get('chatwootUserId')]['confirmed']);

        $login = $this->app->getInjectableFactory()->createWith(TicketLogin::class, ['isPortal' => false]);
        $first = $login->login(new Data(TicketLogin::USERNAME, $result['ticket']), new RequestNull());
        self::assertTrue($first->isSuccess());
        self::assertSame($user->getId(), $first->getUser()->getId());
        self::assertTrue($login->login(new Data(TicketLogin::USERNAME, $result['ticket']), new RequestNull())->isFail(),
            'A ticket logs in once.');

        // The owner's own password still works through the same login method.
        self::assertTrue($login->login(new Data($email, 'segredo-forte'), new RequestNull())->isSuccess());

        // Verifying again only reports progress, with a fresh ticket.
        $again = $this->signups()->status($started['signupId'], $started['token']);
        self::assertSame('ready', $again['status']);
        self::assertNotSame($result['ticket'], $again['ticket']);
    }

    public function testWrongCodesAreCountedThenLocked(): void
    {
        $email = $this->email();
        $started = $this->signups()->begin($this->request($email), $this->ip());

        for ($i = 0; $i < 5; $i++) {
            $this->assertRejected(SignupRejected::INVALID_CODE,
                fn () => $this->signups()->verify($started['signupId'], $started['token'], '000000' === $this->codes[$email] ? '111111' : '000000'));
        }

        $this->assertRejected(SignupRejected::TOO_MANY_ATTEMPTS,
            fn () => $this->signups()->verify($started['signupId'], $started['token'], $this->codes[$email]));
        $this->assertRejected(SignupRejected::RESEND_TOO_SOON,
            fn () => $this->signups()->resend($started['signupId'], $started['token']));
        self::assertNull($this->em->getRDBRepository(User::ENTITY_TYPE)->where(['userName' => $email])->findOne());
    }

    /**
     * A 6-digit code must not be ground down: a resend gives no new guesses,
     * and an email gets at most 10 guesses a day across all its signups.
     */
    public function testGuessesSurviveResendsAndTheEmailLocksForTheDay(): void
    {
        $email = $this->email();
        $first = $this->signups()->begin($this->request($email), $this->ip());

        for ($i = 0; $i < 4; $i++) {
            $this->assertRejected(SignupRejected::INVALID_CODE, fn () => $this->verifyWrong($first, $email));
        }

        $this->expireResendCooldown($first['signupId']);
        $this->signups()->resend($first['signupId'], $first['token']);
        $this->assertRejected(SignupRejected::INVALID_CODE, fn () => $this->verifyWrong($first, $email));
        $this->assertRejected(SignupRejected::TOO_MANY_ATTEMPTS,
            fn () => $this->signups()->verify($first['signupId'], $first['token'], $this->codes[$email]));

        $second = $this->signups()->begin($this->request($email), $this->ip());

        for ($i = 0; $i < 5; $i++) {
            $this->assertRejected(SignupRejected::INVALID_CODE, fn () => $this->verifyWrong($second, $email));
        }

        $this->assertRejected(SignupRejected::RATE_LIMITED,
            fn () => $this->signups()->begin($this->request($email), $this->ip()));
    }

    /**
     * A Chatwoot user already holding the email (e.g. left by a deleted CRM
     * user) is never adopted or confirmed by a signup.
     */
    public function testAVerifiedSignupDoesNotAdoptAnExistingChatwootUser(): void
    {
        $email = $this->email();
        $existing = $this->fake('POST', '/platform/api/v1/users', ['name' => 'Orphan', 'email' => $email, 'password' => 'x']);
        $started = $this->signups()->begin($this->request($email), $this->ip());

        $result = $this->signups()->verify($started['signupId'], $started['token'], $this->codes[$email]);

        self::assertSame('failed', $result['status']);
        self::assertArrayNotHasKey('ticket', $result);
        self::assertFalse($this->fake('GET', '/__users')[(string) $existing['id']]['confirmed']);

        $signup = $this->em->getEntityById('SelfServiceSignup', $started['signupId']);
        $provisioning = $this->em->getEntityById('SelfServiceProvisioning', $signup->get('provisioningId'));
        self::assertNull($provisioning->get('nextAttemptAt'), 'Retrying cannot fix it.');
        self::assertStringContainsString('not adopted', (string) $provisioning->get('lastError'));
    }

    public function testTheSignupTokenIsRequired(): void
    {
        $email = $this->email();
        $started = $this->signups()->begin($this->request($email), $this->ip());

        $this->assertRejected(SignupRejected::NOT_FOUND,
            fn () => $this->signups()->verify($started['signupId'], str_repeat('0', 64), $this->codes[$email]));
    }

    public function testAnEmailThatAlreadyLogsInCannotSignUpAgain(): void
    {
        $email = $this->email();
        $this->em->createEntity(User::ENTITY_TYPE, ['lastName' => 'Existing', 'emailAddress' => $email]);

        $this->assertRejected(SignupRejected::EMAIL_IN_USE,
            fn () => $this->signups()->begin($this->request($email), $this->ip()));
        self::assertArrayNotHasKey($email, $this->codes, 'No code goes to an address that already has an account.');
    }

    public function testOneAddressCanStartOnlySoManySignupsAnHour(): void
    {
        $ip = $this->ip();

        for ($i = 0; $i < 10; $i++) {
            $this->signups()->begin($this->request($this->email()), $ip);
        }

        $this->assertRejected(SignupRejected::RATE_LIMITED,
            fn () => $this->signups()->begin($this->request($this->email()), $ip));
    }

    public function testSignupIsRefusedWhileSelfServiceIsOff(): void
    {
        putenv(SelfServicePolicy::ENV);

        $this->expectException(SelfServiceUnavailable::class);
        $this->signups()->begin($this->request($this->email()), $this->ip());
    }

    private function signups(): Signups
    {
        $mailer = $this->createMock(VerificationMailer::class);
        $mailer->method('send')->willReturnCallback(function (string $to, string $name, string $code): void {
            $this->codes[$to] = $code;
        });

        return $this->app->getInjectableFactory()->createWith(Signups::class, ['mailer' => $mailer]);
    }

    private function request(string $email, string $phone = '+55 11 98888-7777'): SignupRequest
    {
        return new SignupRequest('Maria  Souza', $email, $phone, 'Clínica Signup', 'segredo-forte', true);
    }

    private function email(): string
    {
        return 'signup-' . bin2hex(random_bytes(4)) . '@example.test';
    }

    private function ip(): string
    {
        return '203.0.113.' . random_int(1, 254) . '-' . bin2hex(random_bytes(2));
    }

    /**
     * @param array{signupId: string, token: string} $signup
     */
    private function verifyWrong(array $signup, string $email): array
    {
        return $this->signups()->verify($signup['signupId'], $signup['token'], $this->codes[$email] === '000000' ? '111111' : '000000');
    }

    private function expireResendCooldown(string $signupId): void
    {
        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in('SelfServiceSignup')
                ->set(['codeSentAt' => gmdate('Y-m-d H:i:s', time() - 120)])
                ->where(['id' => $signupId])
                ->build()
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    private function fake(string $method, string $path, array $body = []): array
    {
        $curl = curl_init(rtrim((string) getenv('FAKE_CHATWOOT_URL'), '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $method === 'GET' ? null : json_encode($body),
        ]);
        $response = (string) curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true) ?: [];
    }

    private function assertRejected(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail("Expected {$reason}.");
        } catch (SignupRejected $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
