<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Enrichment\MobilePhones;
use Espo\Modules\FeatureCredits\Enrichment\Providers\{CpfCnpj,Credentials,Response,Result,Transport};
use Espo\Modules\FeatureCredits\Verification\{TwilioCredentials,TwilioTransport};
use PHPUnit\Framework\TestCase;

class EnrichmentMobilePhonesTest extends TestCase
{
    private array $calls = [];
    /** @var array<string, Response> */
    private array $answers = [];
    private array $secrets = ['accountSid' => 'AC-fixture', 'authToken' => 'fixture-token'];

    private function phones(): MobilePhones
    {
        $credentials = $this->createStub(TwilioCredentials::class);
        $credentials->method('get')->willReturnCallback(fn () => $this->secrets);
        $transport = $this->createStub(TwilioTransport::class);
        $transport->method('send')->willReturnCallback(function (string $path, array $query, array $headers, int $timeout): Response {
            $this->calls[] = [rawurldecode(substr($path, strlen('/v2/PhoneNumbers/'))), $query, $headers, $timeout];
            return $this->answers[$this->calls[array_key_last($this->calls)][0]] ?? new Response(0, null);
        });
        return new MobilePhones($credentials, $transport);
    }

    private static function line(?string $type, bool $valid = true, ?int $error = null): Response
    {
        return new Response(200, ['valid' => $valid, 'line_type_intelligence' => $valid ? ['type' => $type, 'error_code' => $error] : null]);
    }

    public function testTwilioKeepsOnlyValidMobilesForContactPhones(): void
    {
        $this->answers = [
            '+5511987654321' => self::line('mobile'),
            '+5511912345678' => self::line('landline'),
            '+5511955554444' => self::line(null, false),
            '+5511944443333' => new Response(404, ['code' => 20404]),
            '+14155550164' => self::line('mobile'),
            '+14155550100' => self::line('nonFixedVoip'),
        ];
        $result = $this->phones()->apply(new Result('match', array_keys($this->answers), dateOfBirth: '1990-05-15',
            retentionSeconds: 3600), 'Contact', 'phone', 20000);
        $this->assertSame('match', $result->state);
        $this->assertSame(['+5511987654321', '+14155550164'], $result->values);
        $this->assertSame('1990-05-15', $result->dateOfBirth);
        $this->assertSame(3600, $result->retentionSeconds);
        $this->assertSame(['Fields' => 'line_type_intelligence'], $this->calls[0][1]);
        $this->assertSame(['Authorization: Basic ' . base64_encode('AC-fixture:fixture-token')], $this->calls[0][2]);
        $this->assertLessThanOrEqual(5000, $this->calls[0][3]);
    }

    public function testBrazilianNumbersOutsideTheMobilePlanAreRefusedWithoutALookup(): void
    {
        // Landline, an old eight-digit mobile, and a malformed area code.
        $result = $this->phones()->apply(new Result('match', ['+551123456789', '+551187654321', '+5501987654321']),
            'Contact', 'phone', 20000);
        $this->assertSame('no_match', $result->state);
        $this->assertSame([], $result->values);
        $this->assertSame([], $this->calls);
    }

    public function testWithoutATwilioAnswerTheBrazilianPlanDecidesAndForeignNumbersAreDropped(): void
    {
        $this->answers = [
            '+5511987654321' => new Response(401, ['code' => 20003]),
            '+5521987654321' => new Response(0, null),
            '+5531987654321' => self::line(null, true, 60601),
            '+14155550164' => new Response(503, null),
        ];
        $result = $this->phones()->apply(new Result('match', array_keys($this->answers)), 'Contact', 'phone', 20000);
        $this->assertSame(['+5511987654321', '+5521987654321', '+5531987654321'], $result->values);

        $this->calls = []; $this->secrets = [];
        $result = $this->phones()->apply(new Result('match', ['+5511987654321', '+14155550164']), 'Contact', 'phone', 20000);
        $this->assertSame(['+5511987654321'], $result->values);
        $this->assertSame([], $this->calls, 'Missing credentials never reach Twilio.');

        $this->secrets = ['accountSid' => 'AC-fixture', 'authToken' => 'fixture-token'];
        $result = $this->phones()->apply(new Result('match', ['+5511987654321']), 'Contact', 'phone', 100);
        $this->assertSame(['+5511987654321'], $result->values);
        $this->assertSame([], $this->calls, 'An exhausted lease skips the lookup.');
    }

    public function testSiblingPhonesAreFilteredAndEverythingElsePassesThrough(): void
    {
        $this->answers = ['+5511987654321' => self::line('mobile'), '+5511912345678' => self::line('landline')];
        $phones = $this->phones();
        $email = $phones->apply(new Result('match', ['a@example.test'],
            hidden: ['phone' => ['+5511987654321', '+5511912345678', '+551123456789']]), 'Contact', 'email', 20000);
        $this->assertSame(['a@example.test'], $email->values);
        $this->assertSame(['phone' => ['+5511987654321']], $email->hidden);

        $none = $phones->apply(new Result('no_match', hidden: ['phone' => ['+551123456789']]), 'Contact', 'email', 20000);
        $this->assertSame('no_match', $none->state);
        $this->assertSame([], $none->hidden);

        $kept = $phones->apply(new Result('match', ['+5511987654321'], hidden: ['email' => ['b@example.test']]), 'Contact', 'phone', 20000);
        $this->assertSame(['email' => ['b@example.test']], $kept->hidden);

        $this->calls = [];
        $company = new Result('match', ['+551123456789']);
        $this->assertSame($company, $phones->apply($company, 'Account', 'phone', 20000), 'Company phones are outside the rule.');
        $pending = new Result('pending', requestId: '1', subjectId: 'person');
        $this->assertSame($pending, $phones->apply($pending, 'Contact', 'phone', 20000));
        $ambiguous = new Result('ambiguous');
        $this->assertSame($ambiguous, $phones->apply($ambiguous, 'Contact', 'phone', 20000));
        $this->assertSame([], $this->calls);
    }

    public function testContactPhoneRevisionCarriesTheMobileRule(): void
    {
        $credentials = $this->createStub(Credentials::class);
        $credentials->method('get')->willReturn(['token' => 'fixture-token']);
        $supplier = new CpfCnpj($credentials, $this->createStub(Transport::class));
        $legacy = static fn (string $type, string $product): string => hash('sha256', json_encode(['supplier-contract-2026-10-11-v1',
            'retention-reuse-approved-max90d', 'cpfcnpj', 'tenant', $type, $product, ['token' => 'fixture-token']], JSON_THROW_ON_ERROR));
        $this->assertNotSame($legacy('Contact', 'phone'), $supplier->revision('tenant', 'Contact', 'phone', ['cpf' => '52998224725']));
        $this->assertSame($legacy('Account', 'phone'), $supplier->revision('tenant', 'Account', 'phone', ['cnpj' => '11222333000181']));
    }
}
