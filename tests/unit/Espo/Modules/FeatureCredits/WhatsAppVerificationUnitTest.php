<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Enrichment\Providers\Response;
use Espo\Modules\FeatureCredits\Verification\{CapabilityGate, CustomerProjection, Kind, TwilioCredentials, WahaCredentials, WahaLookup, WahaTransport, WhatsAppPhone};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhatsAppVerificationUnitTest extends TestCase
{
    public function testCustomerErrorsExposeOnlyAllowlistedVerificationReasons(): void
    {
        $middleware = new \Espo\Modules\FeatureCredits\Classes\Api\CustomerResponse($this->createStub(\Espo\Core\Utils\Log::class));
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                throw \Espo\Core\Exceptions\Conflict::createWithBody('private provider diagnostic',
                    '{"reason":"context_changed","session":"private","number":"private"}');
            }
        };
        foreach (['CreditWhatsAppVerification', 'CreditPhoneVerification', 'CreditEmailVerification'] as $controller) {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/v1/' . $controller);
            $response = $middleware->process($request, $handler);
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
            $expected = ['error' => 'request_conflict'];
            if ($controller !== 'CreditEmailVerification') $expected['reason'] = 'context_changed';
            self::assertSame($expected, json_decode((string) $response->getBody(), true));
        }
    }

    public static function numbers(): iterable
    {
        foreach (['+1 (415) 555-2671' => '+14155552671', '+44 20 7946 0018' => '+442079460018',
            '(11) 98765-4321' => '+5511987654321', '55 11 8765-4321' => '+551187654321',
            '+55 (11) 3222-4455' => '+551132224455'] as $raw => $canonical) yield [$raw, $canonical];
        foreach (['', '+0123456789', '442079460018', '+14155552671 ext 2', '+14155552671;123',
            '123456789@lid', '123456789@g.us', '123456789@c.us', '+1234567890123456', '+1+4155552671', '01198765432'] as $raw) yield [$raw, null];
    }

    #[DataProvider('numbers')]
    public function testNormalization(string $raw, ?string $expected): void
    {
        self::assertSame($expected, WhatsAppPhone::e164($raw));
    }

    public static function responses(): iterable
    {
        yield [200, ['numberExists' => true, 'chatId' => 'private@lid'], 'registered'];
        yield [200, ['numberExists' => false], 'not_registered'];
        foreach ([null, [], ['numberExists' => 'true'], ['numberExists' => 0], ['numberExists' => null]] as $data) yield [200, $data, null];
        foreach ([0, 301, 400, 401, 404, 429, 500] as $status) yield [$status, ['numberExists' => true], null];
    }

    #[DataProvider('responses')]
    public function testOnlyDefinitiveBooleansAreBillable(int $status, ?array $data, ?string $outcome): void
    {
        $result = WahaLookup::evaluate($status, $data);
        self::assertSame($outcome, $result->outcome);
        self::assertStringNotContainsString('private', json_encode($result));
    }

    public function testOneAttemptUsesOnlyInternationalDigitsAndConfiguredSession(): void
    {
        $connection = ['url' => 'http://waha.internal:3000', 'apiKey' => 'test-key', 'session' => 'verification'];
        $credentials = $this->createStub(WahaCredentials::class);
        $credentials->method('enabled')->willReturn(true);
        $credentials->method('get')->willReturn($connection);
        $transport = $this->createMock(WahaTransport::class);
        $transport->expects(self::once())->method('send')->with($connection, '14155552671', 1000)
            ->willReturn(new Response(200, ['numberExists' => false, 'chatId' => 'ignored']));
        self::assertSame('not_registered', (new WahaLookup($credentials, $transport))->verify('+14155552671', 1000)->outcome);
    }

    public function testExplicitActivationIndependentOfPhoneAllowlistAndStablePolicy(): void
    {
        $names = ['VERIFICATION_WHATSAPP_ENABLED', 'VERIFICATION_WHATSAPP_SESSION', 'VERIFICATION_WHATSAPP_SESSIONS', 'WAHA_PLATFORM_BACKEND_URL', 'WAHA_PLATFORM_API_KEY', 'VERIFICATION_KILL_SWITCH'];
        $saved = array_combine($names, array_map(getenv(...), $names));
        try {
            foreach ($names as $name) putenv($name);
            $config = $this->createStub(Config::class);
            $config->method('get')->willReturn(null);
            $clock = $this->createStub(Clock::class);
            $gate = new CapabilityGate($config, $this->createStub(TwilioCredentials::class), $clock);
            $policy = $gate->policyVersion(Kind::WHATSAPP);
            self::assertFalse($gate->inspect('tenant', Kind::WHATSAPP)['available']);
            putenv('WAHA_PLATFORM_BACKEND_URL=http://waha.internal:3000');
            putenv('WAHA_PLATFORM_API_KEY=test-key');
            putenv('VERIFICATION_WHATSAPP_SESSION=verification');
            self::assertFalse($gate->inspect('tenant', Kind::WHATSAPP)['available']);
            putenv('VERIFICATION_WHATSAPP_ENABLED=true');
            foreach (['tenant', 'other'] as $tenant) {
                self::assertTrue($gate->inspect($tenant, Kind::WHATSAPP)['available']);
                self::assertFalse($gate->inspect($tenant)['available']);
            }
            self::assertSame($policy, $gate->policyVersion(Kind::WHATSAPP));
            putenv('VERIFICATION_WHATSAPP_SESSION');
            putenv('VERIFICATION_WHATSAPP_SESSIONS=["shared-a","shared-b","shared-a"]');
            self::assertSame(['shared-a', 'shared-b'], (new WahaCredentials())->get()['sessions']);
            self::assertTrue($gate->inspect('other', Kind::WHATSAPP)['available']);
            self::assertSame($policy, $gate->policyVersion(Kind::WHATSAPP));
            // Invalid explicit pool never silently falls back to the single session.
            putenv('VERIFICATION_WHATSAPP_SESSION=verification');
            foreach (['[]', '{}', 'null', '"shared-a"', '["shared-a",123]', '["bad/name"]', 'not-json'] as $invalid) {
                putenv('VERIFICATION_WHATSAPP_SESSIONS=' . $invalid);
                self::assertSame('provider_unavailable', $gate->inspect('tenant', Kind::WHATSAPP)['reason']);
            }
            putenv('VERIFICATION_WHATSAPP_SESSIONS=["shared-a"]');
            putenv('VERIFICATION_KILL_SWITCH=true');
            self::assertFalse($gate->inspect('tenant', Kind::WHATSAPP)['available']);
        } finally {
            foreach ($saved as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testPoolSelectsOnlyApprovedWorkingSessionsAndNeverRetriesFailedLookup(): void
    {
        $connection = ['url' => 'http://waha.internal:3000', 'apiKey' => 'test-key',
            'sessions' => ['working-a', 'working-b', 'failed', 'stopped']];
        $credentials = $this->createStub(WahaCredentials::class);
        $credentials->method('enabled')->willReturn(true);
        $credentials->method('get')->willReturn($connection);
        $transport = $this->createMock(WahaTransport::class);
        $transport->expects(self::once())->method('sessions')->with($connection, 5000)->willReturn(new Response(200, [
            ['name' => 'unapproved', 'status' => 'WORKING'],
            ['name' => 'failed', 'status' => 'FAILED'],
            ['name' => 'stopped', 'status' => 'STOPPED'],
            ['name' => 'working-a', 'status' => 'WORKING'],
            ['name' => 'working-b', 'status' => 'WORKING'],
        ]));
        $transport->expects(self::once())->method('send')->willReturnCallback(
            static function (array $selected, string $phone, int $timeout): Response {
                self::assertContains($selected['session'], ['working-a', 'working-b']);
                self::assertArrayNotHasKey('sessions', $selected);
                self::assertSame('14155552671', $phone);
                self::assertGreaterThan(0, $timeout);
                self::assertLessThan(30000, $timeout);
                return new Response(500, null);
            }
        );
        self::assertNull((new WahaLookup($credentials, $transport))->verify('+14155552671')->outcome);
    }

    public static function unavailablePools(): iterable
    {
        yield [200, []];
        yield [200, [['name' => 'approved', 'status' => 'FAILED'], ['name' => 'other', 'status' => 'WORKING']]];
        yield [200, ['error' => 'malformed']];
        yield [200, [null, 'invalid', ['name' => 1, 'status' => 'WORKING']]];
        yield [200, null];
        yield [503, [['name' => 'approved', 'status' => 'WORKING']]];
        yield [0, null];
    }

    #[DataProvider('unavailablePools')]
    public function testUnavailablePoolNeverLooksUpPhone(int $status, ?array $data): void
    {
        $credentials = $this->createStub(WahaCredentials::class);
        $credentials->method('enabled')->willReturn(true);
        $credentials->method('get')->willReturn(['url' => 'http://waha.internal:3000', 'apiKey' => 'test-key', 'sessions' => ['approved']]);
        $transport = $this->createMock(WahaTransport::class);
        $transport->expects(self::once())->method('sessions')->willReturn(new Response($status, $data));
        $transport->expects(self::never())->method('send');
        self::assertNull((new WahaLookup($credentials, $transport))->verify('+14155552671')->outcome);
    }

    public function testDiscoveryCannotExtendTheDispatchDeadline(): void
    {
        $credentials = $this->createStub(WahaCredentials::class);
        $credentials->method('enabled')->willReturn(true);
        $credentials->method('get')->willReturn(['url' => 'http://waha.internal:3000', 'apiKey' => 'test-key', 'sessions' => ['approved']]);
        $transport = $this->createMock(WahaTransport::class);
        $transport->expects(self::once())->method('sessions')->with(self::anything(), 1)->willReturnCallback(static function (): Response {
            usleep(2000);
            return new Response(200, [['name' => 'approved', 'status' => 'WORKING']]);
        });
        $transport->expects(self::never())->method('send');
        self::assertNull((new WahaLookup($credentials, $transport))->verify('+14155552671', 1)->outcome);
    }

    public function testRegistrationIdentityDoesNotDependOnNamesOrDob(): void
    {
        $a = ['name' => ['firstName' => 'Maria', 'lastName' => 'Silva'], 'dateOfBirth' => '1990-01-01'];
        $b = ['name' => null, 'dateOfBirth' => null];
        self::assertSame(Kind::fingerprint('key', 't', 'c', '+14155552671', $a, 'policy', Kind::WHATSAPP),
            Kind::fingerprint('key', 't', 'c', '+14155552671', $b, 'policy', Kind::WHATSAPP));
        self::assertSame(Kind::revision('t', 'c', '+14155552671', $a, 'policy', Kind::WHATSAPP),
            Kind::revision('t', 'c', '+14155552671', $b, 'policy', Kind::WHATSAPP));
        $public = CustomerProjection::operation(['verificationKind' => Kind::WHATSAPP, 'outcome' => 'registered',
            'firstName' => 'hidden', 'fieldOutcomes' => ['firstName' => 'exact_match'], 'chatId' => 'hidden', 'session' => 'hidden']);
        self::assertSame(['verificationKind' => Kind::WHATSAPP, 'outcome' => 'registered'], $public);
    }
}
