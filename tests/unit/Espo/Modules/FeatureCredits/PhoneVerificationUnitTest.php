<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Controllers\CreditPhoneVerificationConfiguration;
use Espo\Modules\FeatureCredits\Enrichment\Providers\Response as ProviderResponse;
use Espo\Modules\FeatureCredits\Verification\BrazilianPhone;
use Espo\Modules\FeatureCredits\Verification\CapabilityGate;
use Espo\Modules\FeatureCredits\Verification\CustomerProjection;
use Espo\Modules\FeatureCredits\Verification\Execution;
use Espo\Modules\FeatureCredits\Verification\IdentityInput;
use Espo\Modules\FeatureCredits\Verification\TwilioCredentials;
use Espo\Modules\FeatureCredits\Verification\TwilioLookup;
use Espo\Modules\FeatureCredits\Verification\TwilioTransport;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneVerificationUnitTest extends TestCase
{
    public function testBrazilianPhonePreservesSubscriberDigitsAndRejectsAmbiguousOrForeignNumbers(): void
    {
        self::assertSame(
            ['e164' => '+5511987654321', 'reason' => null],
            BrazilianPhone::normalize('+55 (11) 98765-4321'),
        );
        self::assertSame(
            ['e164' => '+5511987654321', 'reason' => null],
            BrazilianPhone::normalize('(11) 98765-4321'),
        );
        self::assertSame(
            ['e164' => '+5511987654321', 'reason' => null],
            BrazilianPhone::normalize('55 11 98765-4321'),
        );
        self::assertSame(
            ['e164' => '+551132224455', 'reason' => null],
            BrazilianPhone::normalize('11 3222-4455'),
        );
        // D22: Never infer Brazil's ninth digit on 8-digit subscriber numbers.
        self::assertSame(
            ['e164' => '+551187654321', 'reason' => null],
            BrazilianPhone::normalize('(11) 8765-4321'),
        );
        self::assertNotSame(
            BrazilianPhone::e164('(11) 8765-4321'),
            BrazilianPhone::e164('(11) 98765-4321'),
        );
        // DDD 55 (Santa Maria, RS) 10-digit landline is not confused with country code 55.
        self::assertSame(
            ['e164' => '+555532224455', 'reason' => null],
            BrazilianPhone::normalize('(55) 3222-4455'),
        );

        // Non-Brazil international numbers are rejected with unsupported_country (D9).
        self::assertSame(
            ['e164' => null, 'reason' => 'unsupported_country'],
            BrazilianPhone::normalize('+1 415 555 2671'),
        );
        self::assertSame(
            ['e164' => null, 'reason' => 'unsupported_country'],
            BrazilianPhone::normalize('+442079460958'),
        );

        // Ambiguous, trunk-prefixed, invalid DDD, masked, or non-phone identifiers are rejected.
        foreach ([
            null,
            '',
            '98765-4321',
            '3222-4455',
            '01198765432',
            '011987654321',
            '+5501987654321',
            '+5510987654321',
            '+551112345678',
            '+5511887654321',
            '+55119876543210',
            '272628082307301@lid',
            '+55119****4321',
            '+5511987654321 ext 2',
        ] as $invalid) {
            self::assertSame(
                ['e164' => null, 'reason' => 'invalid_phone'],
                BrazilianPhone::normalize($invalid),
                var_export($invalid, true),
            );
        }
    }

    public function testBrazilNameSplittingAndDateOfBirthValidation(): void
    {
        self::assertSame(
            ['firstName' => 'Maria', 'lastName' => 'Silva Santos', 'fullName' => 'Maria Silva Santos'],
            IdentityInput::splitBrazilName('Maria', 'Silva Santos'),
        );
        self::assertSame(
            ['firstName' => 'Ana', 'lastName' => 'Clara Oliveira', 'fullName' => 'Ana Clara Oliveira'],
            IdentityInput::splitBrazilName('  Ana   Clara ', ' Oliveira '),
        );
        self::assertSame(
            ['firstName' => 'Carlos', 'lastName' => 'Eduardo Souza', 'fullName' => 'Carlos Eduardo Souza'],
            IdentityInput::splitBrazilName('', 'Carlos Eduardo Souza'),
        );
        self::assertNull(IdentityInput::splitBrazilName('Maria', ''));
        self::assertNull(IdentityInput::splitBrazilName('', 'Maria'));
        self::assertNull(IdentityInput::splitBrazilName('', '', 'SingleWord'));

        self::assertNull(IdentityInput::validateDateOfBirth(null, '2026-10-14 12:00:00'));
        self::assertNull(IdentityInput::validateDateOfBirth('', '2026-10-14 12:00:00'));
        self::assertSame('1988-02-29', IdentityInput::validateDateOfBirth('1988-02-29', '2026-10-14 12:00:00'));
        self::assertSame('19880229', IdentityInput::twilioDateOfBirth('1988-02-29'));
        self::assertNull(IdentityInput::twilioDateOfBirth(null));

        foreach (['1989-02-29', '2026-02-30', '2026-10-15', '15/05/1990', '1899-12-31', 19900515] as $badDob) {
            try {
                IdentityInput::validateDateOfBirth($badDob, '2026-10-14 12:00:00');
                self::fail('Accepted invalid DOB: ' . var_export($badDob, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $key = IdentityInput::hmacKey('secret');
        $fp1 = IdentityInput::fingerprintHash($key, 't1', 'c1', '+5511987654321', 'Maria', 'Silva', null, 'p1');
        $fp2 = IdentityInput::fingerprintHash($key, 't1', 'c1', '+5511987654321', 'Maria', 'Silva', '1990-01-02', 'p1');
        $fpOtherContact = IdentityInput::fingerprintHash($key, 't1', 'c2', '+5511987654321', 'Maria', 'Silva', null, 'p1');
        self::assertNotSame($fp1, $fp2);
        self::assertNotSame($fp1, $fpOtherContact);
        self::assertStringNotContainsString('5511987654321', $fp1);
        self::assertStringNotContainsString('Maria', $fp1);
    }

    public static function truthTableCases(): iterable
    {
        yield 'exact first name only with contradictory other fields' => [
            true,
            ['first_name_match' => 'exact_match', 'last_name_match' => 'no_match', 'date_of_birth_match' => 'no_match', 'summary_score' => 10],
            true,
            'not_verified',
            ['firstName'],
            ['firstName' => 'exact_match', 'lastName' => 'no_match', 'dateOfBirth' => 'no_match'],
        ];
        yield 'exact last name only without DOB' => [
            false,
            ['first_name_match' => 'partial_match', 'last_name_match' => 'exact_match', 'date_of_birth_match' => null],
            true,
            'not_verified',
            ['lastName'],
            ['firstName' => 'partial_match', 'lastName' => 'exact_match', 'dateOfBirth' => 'not_checked'],
        ];
        yield 'exact DOB only with name mismatches' => [
            true,
            ['first_name_match' => 'no_match', 'last_name_match' => 'not_available', 'date_of_birth_match' => 'exact_match'],
            true,
            'not_verified',
            ['dateOfBirth'],
            ['firstName' => 'no_match', 'lastName' => 'not_available', 'dateOfBirth' => 'exact_match'],
        ];
        yield 'all three exact matches' => [
            true,
            ['first_name_match' => 'exact_match', 'last_name_match' => 'exact_match', 'date_of_birth_match' => 'exact_match'],
            true,
            'verified',
            ['firstName', 'lastName', 'dateOfBirth'],
            ['firstName' => 'exact_match', 'lastName' => 'exact_match', 'dateOfBirth' => 'exact_match'],
        ];
        yield 'partial and high_partial do not verify' => [
            true,
            ['first_name_match' => 'high_partial_match', 'last_name_match' => 'partial_match', 'date_of_birth_match' => 'not_available', 'summary_score' => 95],
            true,
            'not_verified',
            [],
            ['firstName' => 'high_partial_match', 'lastName' => 'partial_match', 'dateOfBirth' => 'not_available'],
        ];
        yield 'no_match with omitted DOB' => [
            false,
            ['first_name_match' => 'no_match', 'last_name_match' => 'not_available'],
            true,
            'not_verified',
            [],
            ['firstName' => 'no_match', 'lastName' => 'not_available', 'dateOfBirth' => 'not_checked'],
        ];
        yield 'insufficient data without DOB' => [
            false,
            ['first_name_match' => 'not_available', 'last_name_match' => null],
            true,
            'insufficient_data',
            [],
            ['firstName' => 'not_available', 'lastName' => 'not_available', 'dateOfBirth' => 'not_checked'],
        ];
        yield 'insufficient data with DOB' => [
            true,
            ['first_name_match' => null, 'last_name_match' => 'not_available', 'date_of_birth_match' => 'not_available'],
            true,
            'insufficient_data',
            [],
            ['firstName' => 'not_available', 'lastName' => 'not_available', 'dateOfBirth' => 'not_available'],
        ];
    }

    #[DataProvider('truthTableCases')]
    public function testTwilioLookupTruthTable(
        bool $dobSubmitted,
        array $identityMatch,
        bool $expectedBillable,
        string $expectedOutcome,
        array $expectedMatched,
        array $expectedFields,
    ): void {
        $captured = [];
        $creds = new class extends TwilioCredentials {
            public function get(): array
            {
                return ['accountSid' => 'AC_TEST', 'authToken' => 'secret_token'];
            }
        };
        $transport = new class($captured, $identityMatch) extends TwilioTransport {
            public function __construct(private array &$captured, private array $match) {}

            public function send(string $path, array $query = [], array $headers = [], int $timeoutMs = 30000): ProviderResponse
            {
                $this->captured = compact('path', 'query', 'headers', 'timeoutMs');
                return new ProviderResponse(200, [
                    'phone_number' => '+5511987654321',
                    'valid' => true,
                    'identity_match' => $this->match,
                ]);
            }
        };

        $lookup = new TwilioLookup($creds, $transport);
        $result = $lookup->verify([
            'phone' => '+5511987654321',
            'firstName' => 'Maria',
            'lastName' => 'Silva Santos',
            'dateOfBirth' => $dobSubmitted ? '1990-05-15' : null,
        ], 25000);

        self::assertSame('/v2/PhoneNumbers/%2B5511987654321', $captured['path']);
        self::assertSame('identity_match', $captured['query']['Fields']);
        self::assertSame('Maria', $captured['query']['FirstName']);
        self::assertSame('Silva Santos', $captured['query']['LastName']);
        if ($dobSubmitted) {
            self::assertSame('19900515', $captured['query']['DateOfBirth']);
        } else {
            self::assertArrayNotHasKey('DateOfBirth', $captured['query']);
        }
        self::assertSame(['Authorization: Basic ' . base64_encode('AC_TEST:secret_token')], $captured['headers']);
        self::assertSame($expectedBillable, $result->billable);
        self::assertSame($expectedOutcome, $result->outcome);
        self::assertSame($expectedMatched, $result->matchedFields);
        self::assertSame($expectedFields, $result->fieldOutcomes);
    }

    public static function nonBillableFailureResponses(): iterable
    {
        yield 'timeout status 0' => [0, null, false];
        yield 'http 429' => [429, ['message' => 'Too many requests'], false];
        yield 'http 500' => [500, ['message' => 'Internal error'], false];
        yield 'http 200 null body' => [200, null, false];
        yield 'valid false' => [200, ['phone_number' => '+5511987654321', 'valid' => false, 'identity_match' => ['first_name_match' => 'exact_match', 'last_name_match' => 'exact_match']], false];
        yield 'mismatched phone_number' => [200, ['phone_number' => '+5511999999999', 'valid' => true, 'identity_match' => ['first_name_match' => 'exact_match', 'last_name_match' => 'exact_match']], false];
        yield 'missing identity_match' => [200, ['phone_number' => '+5511987654321', 'valid' => true, 'identity_match' => null], false];
        yield 'package error_code' => [200, ['phone_number' => '+5511987654321', 'valid' => true, 'identity_match' => ['first_name_match' => 'exact_match', 'last_name_match' => 'exact_match', 'error_code' => 60600]], false];
        yield 'package error_message' => [200, ['phone_number' => '+5511987654321', 'valid' => true, 'identity_match' => ['first_name_match' => 'exact_match', 'last_name_match' => 'exact_match', 'error_message' => 'Upstream failure']], false];
        yield 'missing required match key' => [200, ['phone_number' => '+5511987654321', 'valid' => true, 'identity_match' => ['first_name_match' => 'exact_match']], false];
        yield 'missing dob match key when dob submitted' => [200, ['phone_number' => '+5511987654321', 'valid' => true, 'identity_match' => ['first_name_match' => 'exact_match', 'last_name_match' => 'no_match']], true];
        yield 'unsupported match value' => [200, ['phone_number' => '+5511987654321', 'valid' => true, 'identity_match' => ['first_name_match' => 'fuzzy_match', 'last_name_match' => 'no_match']], false];
    }

    #[DataProvider('nonBillableFailureResponses')]
    public function testTwilioFailuresAndMalformedPayloadsAreNeverBilledOrMarkedNotVerified(
        int $status,
        ?array $body,
        bool $dobSubmitted,
    ): void {
        $result = TwilioLookup::evaluate($status, $body, '+5511987654321', $dobSubmitted);
        self::assertFalse($result->billable);
        self::assertSame('failed', $result->outcome);
        self::assertNotSame('not_verified', $result->outcome);
        self::assertSame([], $result->matchedFields);
        self::assertNull($result->fieldOutcomes);
        self::assertNotNull($result->failureReason);
    }

    public function testOperatorCapabilityGateAndConfigurationController(): void
    {
        $stored = [];
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(static function (string $k) use (&$stored) {
            return $k === CapabilityGate::CONFIG_KEY ? $stored : null;
        });
        $writer = $this->createMock(ConfigWriter::class);
        $writer->method('set')->willReturnCallback(static function (string $k, mixed $v) use (&$stored): void {
            if ($k === CapabilityGate::CONFIG_KEY) {
                $stored = $v;
            }
        });
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn('2026-10-14 12:00:00');
        $creds = new class extends TwilioCredentials {
            public bool $configured = true;

            public function get(): array
            {
                return $this->configured ? ['accountSid' => 'AC_1', 'authToken' => 'tok_1'] : [];
            }
        };

        $gate = new CapabilityGate($config, $creds, $clock, $writer);
        self::assertFalse($gate->inspect('tenant')['available']);
        self::assertSame('verification_disabled', $gate->inspect('tenant')['reason']);

        // Cannot enable without Brazil minimal-input validation and releaseApprovalRef.
        try {
            $gate->publish((object) ['enabled' => true], 'admin');
            self::fail('Enabled without validation approval.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $gate->publish((object) [
            'enabled' => true,
            'brazilMinimalInputValidated' => true,
            'releaseApprovalRef' => 'ADR-2026-10-14-PILOT',
            'allowlistedTenantIds' => ['tenant'],
        ], 'admin');

        self::assertTrue($gate->inspect('tenant')['available']);
        self::assertFalse($gate->inspect('other-tenant')['available']);
        self::assertSame('tenant_not_allowlisted', $gate->inspect('other-tenant')['reason']);

        // Kill switch immediately blocks availability.
        $gate->publish((object) ['killSwitch' => true], 'admin');
        self::assertFalse($gate->inspect('tenant')['available']);
        self::assertSame('kill_switch', $gate->inspect('tenant')['reason']);

        // Non-admin user is forbidden from reading or publishing operator configuration.
        $nonAdmin = $this->createMock(User::class);
        $nonAdmin->method('isAdmin')->willReturn(false);
        $controller = new CreditPhoneVerificationConfiguration($nonAdmin, $gate, $this->createMock(Execution::class));
        try {
            $controller->getActionRead($this->createMock(Request::class), $this->createMock(Response::class));
            self::fail('Non-admin read allowed.');
        } catch (Forbidden) {
            $this->addToAssertionCount(1);
        }
        try {
            $controller->postActionPublish($this->createMock(Request::class), $this->createMock(Response::class));
            self::fail('Non-admin publish allowed.');
        } catch (Forbidden) {
            $this->addToAssertionCount(1);
        }

        $admin = $this->createMock(User::class);
        $admin->method('isAdmin')->willReturn(true);
        $admin->method('getId')->willReturn('admin');
        $exec = $this->createMock(Execution::class);
        $exec->method('diagnostics')->willReturn(['observedAt' => '2026-10-14 12:00:00', 'staleOpenCount' => 0]);
        $adminController = new CreditPhoneVerificationConfiguration($admin, $gate, $exec);
        $req = $this->createMock(Request::class);
        $req->method('getQueryParams')->willReturn(['unexpected' => '1']);
        try {
            $adminController->getActionRead($req, $this->createMock(Response::class));
            self::fail('Unexpected query accepted.');
        } catch (BadRequest) {
            $this->addToAssertionCount(1);
        }
    }

    public function testCustomerProjectionStripsInternalFieldsAndRemainsProviderNeutral(): void
    {
        $projected = CustomerProjection::status([
            'tenantId' => 'tenant',
            'contactId' => 'contact',
            'requestKey' => 'req-1',
            'resolution' => 'admitted',
            'provider' => 'twilio',
            'policyVersion' => 'secret-policy',
            'operation' => [
                'id' => 'op-1',
                'usageId' => 'usage-1',
                'reservationId' => 'res-1',
                'canonicalPhone' => '+5511987654321',
                'state' => 'completed',
                'outcome' => 'verified',
                'matchedFields' => ['firstName'],
                'fieldOutcomes' => [
                    'firstName' => 'exact_match',
                    'lastName' => 'no_match',
                    'dateOfBirth' => 'not_checked',
                    'summaryScore' => 100,
                ],
                'hasDateOfBirth' => false,
                'priceCredits' => '8.0000',
                'reservedCredits' => '0.0000',
                'settledCredits' => '8.0000',
                'admittedAt' => '2026-10-14 12:00:00',
                'completedAt' => '2026-10-14 12:00:02',
                'freshUntil' => '2026-11-13 12:00:02',
                'fresh' => true,
                'stale' => false,
                'erased' => false,
                'expired' => false,
                'identityChanged' => false,
                'claimToken' => 'secret-token',
                'failureReason' => 'http_500',
                'provider' => 'twilio',
            ],
        ]);
        $encoded = json_encode($projected, JSON_THROW_ON_ERROR);
        foreach (['twilio', 'secret-policy', 'secret-token', 'http_500', 'summaryScore'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
    }
}
