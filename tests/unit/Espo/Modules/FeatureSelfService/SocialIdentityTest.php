<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService;

use Espo\Modules\FeatureSelfService\Tools\Signup\SignupRejected;
use Espo\Modules\FeatureSelfService\Tools\Signup\SocialIdentity;
use PHPUnit\Framework\TestCase;

class SocialIdentityTest extends TestCase
{
    private const CLIENT = 'client-123';
    private const NOW = 1_800_000_000;
    private const CONSUMER = '9188040d-6c67-4c5b-b112-36a304b66dad';
    private const WORK = '11111111-2222-3333-4444-555555555555';

    public function testGoogleVerifiedEmail(): void
    {
        $identity = SocialIdentity::fromIdToken(SocialIdentity::GOOGLE, $this->token([
            'iss' => 'https://accounts.google.com', 'email' => 'Ana@Example.com', 'email_verified' => true, 'name' => 'Ana Lima',
        ]), self::CLIENT, self::NOW);

        $this->assertSame('ana@example.com', $identity->email);
        $this->assertSame('Ana Lima', $identity->name);
    }

    public function testGoogleUnverifiedEmailIsRefused(): void
    {
        $this->assertReason(SignupRejected::SOCIAL_EMAIL_UNVERIFIED, SocialIdentity::GOOGLE, [
            'iss' => 'https://accounts.google.com', 'email' => 'ana@example.com', 'email_verified' => false,
        ]);
    }

    public function testAnotherAppsTokenIsRefused(): void
    {
        $this->assertReason(SignupRejected::SOCIAL_FAILED, SocialIdentity::GOOGLE, [
            'iss' => 'https://accounts.google.com', 'aud' => 'someone-else', 'email' => 'ana@example.com', 'email_verified' => true,
        ]);
    }

    public function testExpiredTokenIsRefused(): void
    {
        $this->assertReason(SignupRejected::SOCIAL_FAILED, SocialIdentity::GOOGLE, [
            'iss' => 'https://accounts.google.com', 'exp' => self::NOW - 600, 'email' => 'ana@example.com', 'email_verified' => true,
        ]);
    }

    public function testMicrosoftPersonalAccountIsTrusted(): void
    {
        $identity = SocialIdentity::fromIdToken(SocialIdentity::MICROSOFT, $this->token([
            'iss' => 'https://login.microsoftonline.com/' . self::CONSUMER . '/v2.0', 'tid' => self::CONSUMER,
            'email' => 'joao@outlook.com', 'given_name' => 'João', 'family_name' => 'Silva',
        ]), self::CLIENT, self::NOW);

        $this->assertSame('joao@outlook.com', $identity->email);
        $this->assertSame('João Silva', $identity->name);
    }

    /**
     * Any tenant admin can put any address in a work account's email claim.
     */
    public function testMicrosoftWorkAccountWithoutDomainVerificationIsRefused(): void
    {
        $this->assertReason(SignupRejected::SOCIAL_EMAIL_UNVERIFIED, SocialIdentity::MICROSOFT, [
            'iss' => 'https://login.microsoftonline.com/' . self::WORK . '/v2.0', 'tid' => self::WORK, 'email' => 'ceo@victim.com',
        ]);
    }

    public function testMicrosoftWorkAccountWithVerifiedDomainIsTrusted(): void
    {
        $identity = SocialIdentity::fromIdToken(SocialIdentity::MICROSOFT, $this->token([
            'iss' => 'https://login.microsoftonline.com/' . self::WORK . '/v2.0', 'tid' => self::WORK,
            'email' => 'maria@acme.com', 'xms_edov' => true,
        ]), self::CLIENT, self::NOW);

        $this->assertSame('maria@acme.com', $identity->email);
        $this->assertSame('maria', $identity->name);
    }

    public function testMicrosoftIssuerMustMatchTheTenant(): void
    {
        $this->assertReason(SignupRejected::SOCIAL_EMAIL_UNVERIFIED, SocialIdentity::MICROSOFT, [
            'iss' => 'https://login.microsoftonline.com/' . self::WORK . '/v2.0', 'tid' => self::CONSUMER, 'email' => 'x@outlook.com',
        ]);
    }

    public function testMalformedTokenIsRefused(): void
    {
        try {
            SocialIdentity::fromIdToken(SocialIdentity::GOOGLE, 'not-a-jwt', self::CLIENT, self::NOW);
            $this->fail('Expected SOCIAL_FAILED.');
        } catch (SignupRejected $e) {
            $this->assertSame(SignupRejected::SOCIAL_FAILED, $e->reason);
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertReason(string $reason, string $provider, array $claims): void
    {
        try {
            SocialIdentity::fromIdToken($provider, $this->token($claims), self::CLIENT, self::NOW);
            $this->fail("Expected {$reason}.");
        } catch (SignupRejected $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function token(array $claims): string
    {
        $encode = fn (array $data) => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256']) . '.' . $encode([
            'aud' => self::CLIENT, 'exp' => self::NOW + 3600, ...$claims,
        ]) . '.signature';
    }
}
