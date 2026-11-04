<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Utils\Crypt;
use Espo\Entities\User;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Signup\LoginTickets;
use Espo\Modules\FeatureSelfService\Tools\Signup\SignupRejected;
use Espo\Modules\FeatureSelfService\Tools\Signup\SocialSignIn;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Google sign-in against the existing OAuthProvider record, with
 * fake-chatwoot.php standing in for Google's token endpoint.
 */
class SocialSignInTest extends TestCase
{
    private const CLIENT_ID = 'test-google-client';

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

        putenv(SelfServicePolicy::ENV . '=true');
        $this->app->getInjectableFactory()->create(SelfServicePolicy::class)->resume();

        if (!$this->em->getRDBRepository('ChatwootPlatform')->findOne()) {
            $this->em->createEntity('ChatwootPlatform', [
                'name' => 'Fake Chatwoot', 'backendUrl' => $this->fake, 'frontendUrl' => $this->fake, 'accessToken' => 'platform-token',
            ], ['skipHooks' => true]);
        }

        // Rebuild seeds the record without credentials, as before an admin sets them.
        $google = $this->em->getRDBRepository('OAuthProvider')->where(['provider' => 'google-gmail'])->findOne()
            ?? $this->em->getNewEntity('OAuthProvider');
        $google->set([
            'name' => 'Google Gmail',
            'provider' => 'google-gmail',
            'isActive' => true,
            'clientId' => self::CLIENT_ID,
            'clientSecret' => $this->app->getInjectableFactory()->create(Crypt::class)->encrypt('test-secret'),
            'authorizationEndpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'tokenEndpoint' => rtrim($this->fake, '/') . '/oauth/token',
        ]);
        $this->em->saveEntity($google, ['skipHooks' => true]);
    }

    public function testANewGoogleUserCreatesTheWorkspaceWithoutAnEmailCode(): void
    {
        $email = $this->email();
        $nonce = $this->nonce();
        $state = $this->state($this->social()->start('google', $nonce)['url']);
        $this->idToken(['email' => $email, 'email_verified' => true, 'name' => 'Bia Google']);

        $finished = $this->social()->finish('google', 'auth-code', $state, $nonce);
        self::assertSame('needsDetails', $finished['status']);
        self::assertSame($email, $finished['email']);

        $started = $this->social()->complete($finished['identity'], '(21) 98765-4321', 'Studio Bia', true, 'pt_BR', $this->ip());
        self::assertSame('ready', $started['status'], json_encode($started));

        $user = $this->app->getInjectableFactory()->create(LoginTickets::class)->redeem($started['ticket']);
        self::assertSame($email, $user->getUserName());
        self::assertSame('Bia Google', $user->getName());
        self::assertSame('+5521987654321', $user->get('phoneNumber'));
    }

    public function testAnExistingUserSignsInWithAOneTimeTicket(): void
    {
        $email = $this->email();
        $user = $this->em->createEntity(User::ENTITY_TYPE, ['lastName' => 'Existing', 'emailAddress' => $email]);
        $nonce = $this->nonce();
        $state = $this->state($this->social()->start('google', $nonce)['url']);
        $this->idToken(['email' => $email, 'email_verified' => true, 'name' => 'Existing']);

        $finished = $this->social()->finish('google', 'auth-code', $state, $nonce);

        self::assertSame('ready', $finished['status']);
        self::assertSame($user->getId(), $this->app->getInjectableFactory()->create(LoginTickets::class)
            ->redeem($finished['ticket'])->getId());
    }

    public function testAStateFromAnotherTabOrTamperedIsRefused(): void
    {
        $state = $this->state($this->social()->start('google', $this->nonce())['url']);
        $this->idToken(['email' => $this->email(), 'email_verified' => true]);

        $this->assertRejected(SignupRejected::SOCIAL_FAILED,
            fn () => $this->social()->finish('google', 'auth-code', $state, $this->nonce()));
        $this->assertRejected(SignupRejected::SOCIAL_FAILED,
            fn () => $this->social()->finish('google', 'auth-code', $state . 'x', $this->nonce()));
    }

    public function testAnUnverifiedEmailCannotSignIn(): void
    {
        $nonce = $this->nonce();
        $state = $this->state($this->social()->start('google', $nonce)['url']);
        $this->idToken(['email' => $this->email(), 'email_verified' => false]);

        $this->assertRejected(SignupRejected::SOCIAL_EMAIL_UNVERIFIED,
            fn () => $this->social()->finish('google', 'auth-code', $state, $nonce));
    }

    public function testAnInstanceAdministratorCannotSignInWithGoogle(): void
    {
        $email = $this->email();
        $this->em->createEntity(User::ENTITY_TYPE, ['lastName' => 'Admin', 'emailAddress' => $email, 'type' => User::TYPE_ADMIN]);
        $nonce = $this->nonce();
        $state = $this->state($this->social()->start('google', $nonce)['url']);
        $this->idToken(['email' => $email, 'email_verified' => true]);

        $this->assertRejected(SignupRejected::EMAIL_IN_USE,
            fn () => $this->social()->finish('google', 'auth-code', $state, $nonce));
    }

    public function testTheAuthorizationUrlAsksOnlyForTheBasicProfile(): void
    {
        $url = $this->social()->start('google', $this->nonce())['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        self::assertSame('openid email profile', $query['scope']);
        self::assertSame(self::CLIENT_ID, $query['client_id']);
        self::assertStringEndsWith('/oauth/callback/', $query['redirect_uri']);
        self::assertContains('google', $this->social()->availableProviders());
    }

    private function social(): SocialSignIn
    {
        return $this->app->getInjectableFactory()->create(SocialSignIn::class);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function idToken(array $claims): void
    {
        $this->http('POST', '/__idtoken', ['claims' => [
            'iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'exp' => time() + 3600, ...$claims,
        ]]);
    }

    private function state(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function nonce(): string
    {
        return bin2hex(random_bytes(24));
    }

    private function email(): string
    {
        return 'social-' . bin2hex(random_bytes(4)) . '@example.test';
    }

    private function ip(): string
    {
        return '198.51.100.' . random_int(1, 254) . '-' . bin2hex(random_bytes(2));
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

    /**
     * @param array<string, mixed> $body
     */
    private function http(string $method, string $path, array $body = []): void
    {
        $curl = curl_init(rtrim($this->fake, '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        curl_exec($curl);
        curl_close($curl);
    }
}
