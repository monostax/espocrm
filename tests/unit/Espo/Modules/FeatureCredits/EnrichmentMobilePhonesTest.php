<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Enrichment\MobilePhones;
use Espo\Modules\FeatureCredits\Enrichment\Providers\{CpfCnpj,Credentials,Result,Transport};
use PHPUnit\Framework\TestCase;

class EnrichmentMobilePhonesTest extends TestCase
{
    public function testBrazilKeepsOnlyNumbersInTheMobilePlan(): void
    {
        // Mobile, landline, an old eight-digit mobile, and a malformed area code.
        $result = (new MobilePhones())->apply(new Result('match', ['+5511987654321', '+551123456789', '+551187654321',
            '+5501987654321'], dateOfBirth: '1990-05-15', retentionSeconds: 3600), 'Contact', 'phone');
        $this->assertSame('match', $result->state);
        $this->assertSame(['+5511987654321'], $result->values);
        $this->assertSame('1990-05-15', $result->dateOfBirth);
        $this->assertSame(3600, $result->retentionSeconds);
    }

    public function testOtherCountriesFollowLibphonenumber(): void
    {
        // UK mobile, UK landline, US number (fixed line or mobile), garbage.
        $result = (new MobilePhones())->apply(new Result('match', ['+447400123456', '+442071838750', '+14155550164',
            '+999123']), 'Contact', 'phone');
        $this->assertSame(['+447400123456', '+14155550164'], $result->values);

        $none = (new MobilePhones())->apply(new Result('match', ['+442071838750']), 'Contact', 'phone');
        $this->assertSame('no_match', $none->state);
        $this->assertSame([], $none->values);
    }

    public function testSiblingPhonesAreFilteredAndEverythingElsePassesThrough(): void
    {
        $phones = new MobilePhones();
        $email = $phones->apply(new Result('match', ['a@example.test'],
            hidden: ['phone' => ['+5511987654321', '+551123456789']]), 'Contact', 'email');
        $this->assertSame(['a@example.test'], $email->values);
        $this->assertSame(['phone' => ['+5511987654321']], $email->hidden);

        $none = $phones->apply(new Result('no_match', hidden: ['phone' => ['+551123456789']]), 'Contact', 'email');
        $this->assertSame('no_match', $none->state);
        $this->assertSame([], $none->hidden);

        $kept = $phones->apply(new Result('match', ['+5511987654321'], hidden: ['email' => ['b@example.test']]), 'Contact', 'phone');
        $this->assertSame(['email' => ['b@example.test']], $kept->hidden);

        $company = new Result('match', ['+551123456789']);
        $this->assertSame($company, $phones->apply($company, 'Account', 'phone'), 'Company phones are outside the rule.');
        $pending = new Result('pending', requestId: '1', subjectId: 'person');
        $this->assertSame($pending, $phones->apply($pending, 'Contact', 'phone'));
        $ambiguous = new Result('ambiguous');
        $this->assertSame($ambiguous, $phones->apply($ambiguous, 'Contact', 'phone'));
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
