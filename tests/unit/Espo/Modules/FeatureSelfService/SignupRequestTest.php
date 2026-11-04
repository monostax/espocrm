<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService;

use Espo\Modules\FeatureSelfService\Tools\Signup\SignupRejected;
use Espo\Modules\FeatureSelfService\Tools\Signup\SignupRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SignupRequestTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function phones(): array
    {
        return [
            'BR mobile with mask' => ['(11) 91234-5678', '+5511912345678'],
            'BR landline' => ['11 3333-4444', '+551133334444'],
            'BR with country code' => ['+55 11 91234-5678', '+5511912345678'],
            'international with 00' => ['0044 20 7946 0958', '+442079460958'],
            'US with plus' => ['+1 (415) 555-2671', '+14155552671'],
        ];
    }

    #[DataProvider('phones')]
    public function testPhonesBecomeE164(string $input, string $expected): void
    {
        $this->assertSame($expected, SignupRequest::phone($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badPhones(): array
    {
        return [
            'too short' => ['91234-5678'],
            'letters' => ['call me'],
            'leading zero country' => ['+0 11 91234-5678'],
            'too long' => ['+55 11 91234-5678 1234'],
        ];
    }

    #[DataProvider('badPhones')]
    public function testRejectsPhonesThatCannotBeDialed(string $input): void
    {
        $this->assertReason(SignupRejected::INVALID_PHONE, fn () => SignupRequest::phone($input));
    }

    public function testNormalizesTheForm(): void
    {
        $request = new SignupRequest('  Maria   Souza ', ' Maria@Example.COM ', '11912345678', ' Acme ', 'segredo-forte', true, 'fr_FR');

        $this->assertSame('Maria Souza', $request->name);
        $this->assertSame('maria@example.com', $request->email);
        $this->assertSame('+5511912345678', $request->phone);
        $this->assertSame('Acme', $request->company);
        $this->assertSame('pt_BR', $request->language);
    }

    /**
     * @return array<string, array{0: string, 1: array<int, mixed>}>
     */
    public static function invalidForms(): array
    {
        return [
            'name' => [SignupRejected::INVALID_NAME, ['M', 'm@example.com', '11912345678', 'Acme', 'segredo-forte', true]],
            'email' => [SignupRejected::INVALID_EMAIL, ['Maria', 'maria', '11912345678', 'Acme', 'segredo-forte', true]],
            'company' => [SignupRejected::INVALID_COMPANY, ['Maria', 'm@example.com', '11912345678', ' ', 'segredo-forte', true]],
            'password' => [SignupRejected::WEAK_PASSWORD, ['Maria', 'm@example.com', '11912345678', 'Acme', 'curta', true]],
            'blank password' => [SignupRejected::WEAK_PASSWORD, ['Maria', 'm@example.com', '11912345678', 'Acme', '          ', true]],
            'terms' => [SignupRejected::TERMS_NOT_ACCEPTED, ['Maria', 'm@example.com', '11912345678', 'Acme', 'segredo-forte', false]],
        ];
    }

    /**
     * @param array<int, mixed> $arguments
     */
    #[DataProvider('invalidForms')]
    public function testRejectsInvalidForms(string $reason, array $arguments): void
    {
        $this->assertReason($reason, fn () => new SignupRequest(...$arguments));
    }

    private function assertReason(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected {$reason}.");
        } catch (SignupRejected $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
