<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Enrichment\Providers\Response;
use Espo\Modules\FeatureCredits\Verification\BounceBanCredentials;
use Espo\Modules\FeatureCredits\Verification\BounceBanLookup;
use Espo\Modules\FeatureCredits\Verification\BounceBanTransport;
use Espo\Modules\FeatureCredits\Verification\Kind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailVerificationUnitTest extends TestCase
{
    private static function completed(): array
    {
        return ['id' => 'task1', 'status' => 'success', 'email' => 'person@example.com', 'result' => 'deliverable',
            'score' => 99, 'is_accept_all' => true, 'is_disposable' => false, 'verify_at' => '2026-10-14T12:00:00.000Z',
            'credits_consumed' => 999, 'credits_remaining' => 123, 'smtp_provider' => 'private'];
    }

    public static function responses(): iterable
    {
        foreach (Kind::outcomes(Kind::EMAIL) as $outcome) {
            yield $outcome => [200, array_replace(self::completed(), ['result' => $outcome]), 'completed', $outcome];
        }
        foreach (['queue', 'verifying'] as $state) {
            yield $state => [200, ['id' => 'task1', 'status' => $state, 'try_again_at' => 1791979200], 'pending', null];
        }
        foreach ([0, 400, 401, 403, 405, 429, 500] as $status) {
            yield "HTTP $status" => [$status, self::completed(), 'failed', null];
        }
        foreach ([['id' => 'other'], ['email' => 'other@example.com'], ['result' => 'unexpected'], ['status' => 'finished'],
            ['score' => -1], ['score' => '99'], ['score' => 101], ['is_accept_all' => 'true'], ['mode' => 'deepverify'],
            ['verify_at' => 'invalid'], ['error' => 'error']] as $i => $change) {
            yield "malformed $i" => [200, array_replace(self::completed(), $change), 'failed', null];
        }
        foreach (['id', 'email', 'result', 'score', 'status'] as $field) {
            $data = self::completed();
            unset($data[$field]);
            yield "missing $field" => [200, $data, 'failed', null];
        }
    }

    #[DataProvider('responses')]
    public function testResponseClassificationAndTaskBinding(int $http, array $data, string $status, ?string $outcome): void
    {
        $result = BounceBanLookup::evaluate($http, $data, 'person@example.com', 'task1');
        self::assertSame($status, $result->status);
        self::assertSame($outcome, $result->outcome);
        if ($status === 'completed') {
            self::assertSame(['score', 'flags', 'verifiedAt'], array_keys($result->evidence));
            self::assertTrue($result->evidence['flags']['is_accept_all']);
            self::assertSame('2.0000', Kind::price(Kind::EMAIL));
        }
    }

    public function testSubmissionAndFreeStatusUseDifferentEndpointsWithoutResubmission(): void
    {
        $credentials = $this->createStub(BounceBanCredentials::class);
        $credentials->method('get')->willReturn(['apiKey' => 'test-key']);
        $transport = $this->createMock(BounceBanTransport::class);
        $requests = [];
        $transport->expects(self::exactly(2))->method('send')->willReturnCallback(
            function (string $path, array $query, string $key, int $timeout) use (&$requests): Response {
                $requests[] = [$path, $query];
                self::assertSame('test-key', $key);
                self::assertSame(1000, $timeout);
                return count($requests) === 1 ? new Response(200, ['id' => 'task1', 'status' => 'verifying']) : new Response(200, self::completed());
            },
        );
        $lookup = new BounceBanLookup($credentials, $transport);
        self::assertSame('pending', $lookup->verify('person@example.com', null, 1000)->status);
        self::assertSame('completed', $lookup->verify('person@example.com', 'task1', 1000)->status);
        self::assertSame([
            ['/v1/verify/single', ['email' => 'person@example.com', 'mode' => 'regular', 'disable_catchall_verify' => '0']],
            ['/v1/verify/single/status', ['id' => 'task1']],
        ], $requests);
    }

    public function testEmailNormalizationPreservesDistinctAddresses(): void
    {
        self::assertSame('person.name+tag@example.com', Kind::email(' Person.Name+Tag@Example.com '));
        self::assertNotSame(Kind::email('person.name@example.com'), Kind::email('personname@example.com'));
        self::assertNotSame(Kind::email('person+tag@example.com'), Kind::email('person@example.com'));
        foreach (['not-email', 'Name <person@example.com>', "person@example.com\r\nOther: value", 'a@b.com,c@d.com'] as $invalid) {
            self::assertNull(Kind::email($invalid));
        }
    }
}
