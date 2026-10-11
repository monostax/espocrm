<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\DispatchInput;
use Espo\Modules\FeatureCredits\Accounting\DispatchStore;
use Espo\Modules\FeatureCredits\Accounting\Outcomes;
use Espo\Modules\FeatureCredits\Accounting\WalletLock;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\Settlements;
use Espo\Modules\FeatureCredits\Controllers\CreditDispatch;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CreditDispatchTest extends TestCase
{
    public static function invalidSignatures(): iterable
    {
        yield 'unsigned' => ['', '', '', '{}'];
        yield 'terminal signature cannot cross endpoint' => ['test-secret', 'credit-execution-v1', 'now', '{}'];
        yield 'expired' => ['test-secret', 'credit-dispatch-v1', 'expired', '{}'];
        yield 'future' => ['test-secret', 'credit-dispatch-v1', 'future', '{}'];
        yield 'unconfigured' => ['', 'credit-dispatch-v1', 'now', '{}'];
    }

    #[DataProvider('invalidSignatures')]
    public function testSignatureDeniedBeforeStorage(string $secret, string $prefix, string $when, string $raw): void
    {
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        $clock = new Clock(); $lock = new WalletLock($manager, $clock); $funding = new Funding($lock);
        $store = new DispatchStore($manager, $clock, new Outcomes($lock), new Reservations($lock, $funding), new Settlements($lock, $funding));
        $timestamp = (string) (time() + ($when === 'expired' ? -400 : ($when === 'future' ? 400 : 0)));
        $request = $this->createStub(Request::class); $request->method('getBodyContents')->willReturn($raw);
        $request->method('getHeader')->willReturnCallback(static fn ($header) => match ($header) {
            'X-Credit-Execution-Timestamp' => $timestamp,
            'X-Credit-Execution-Signature' => 'sha256=' . hash_hmac('sha256', "$prefix.$timestamp.$raw", $secret),
            default => null,
        });
        $previous = getenv('AI_CREDIT_EXECUTION_SECRET'); putenv('AI_CREDIT_EXECUTION_SECRET=' . $secret);
        try { $this->expectException(Forbidden::class); (new CreditDispatch($store))->postActionExecute($request); }
        finally { putenv($previous === false ? 'AI_CREDIT_EXECUTION_SECRET' : 'AI_CREDIT_EXECUTION_SECRET=' . $previous); }
    }

    public static function malformed(): iterable
    {
        yield 'unknown command' => [(object) ['operation' => 'delete']];
        yield 'caller receipt' => [(object) ['operation' => 'deliver', 'tenantId' => 'tenant', 'leaseMs' => 1000, 'retryMs' => 1000, 'receipt' => (object) []]];
        yield 'caller rate' => [(object) ['operation' => 'deliver', 'tenantId' => 'tenant', 'leaseMs' => 1000, 'retryMs' => 1000, 'credits' => '0']];
        yield 'missing tenant' => [(object) ['operation' => 'deliver', 'leaseMs' => 1000, 'retryMs' => 1000]];
        yield 'invalid timing' => [(object) ['operation' => 'deliver', 'tenantId' => 'tenant', 'leaseMs' => 1, 'retryMs' => 1000]];
        yield 'missing intent' => [(object) ['operation' => 'claimIntent', 'intent' => null]];
        yield 'invalid scope' => [(object) ['operation' => 'load', 'scope' => (object) [], 'requestKey' => 'first']];
        yield 'seal missing scope' => [(object) ['operation' => 'seal']];
        yield 'recover caller facts' => [(object) ['operation' => 'recover', 'scope' => (object) [], 'facts' => (object) []]];
        yield 'recovery missing timing' => [(object) ['operation' => 'recoverOne', 'tenantId' => 'tenant', 'leaseMs' => 1000]];
    }

    #[DataProvider('malformed')]
    public function testStrictCommandsRejectUnexpectedOrInvalidFields(object $input): void
    {
        $this->expectException(InvalidArgumentException::class); new DispatchInput($input);
    }

    public function testSignedMalformedBodyRejectedBeforeDatabase(): void
    {
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        $clock = new Clock(); $lock = new WalletLock($manager, $clock); $funding = new Funding($lock);
        $controller = new CreditDispatch(new DispatchStore($manager, $clock, new Outcomes($lock), new Reservations($lock, $funding), new Settlements($lock, $funding)));
        $previous = getenv('AI_CREDIT_EXECUTION_SECRET'); putenv('AI_CREDIT_EXECUTION_SECRET=test-secret');
        try {
            foreach (['[]', '{', str_repeat(' ', 65537)] as $raw) {
                $timestamp = (string) time(); $request = $this->createStub(Request::class);
                $request->method('getBodyContents')->willReturn($raw);
                $request->method('getHeader')->willReturnCallback(static fn ($header) => $header === 'X-Credit-Execution-Timestamp' ? $timestamp :
                    'sha256=' . hash_hmac('sha256', "credit-dispatch-v1.$timestamp.$raw", 'test-secret'));
                try { $controller->postActionExecute($request); $this->fail('Malformed signed body accepted.'); } catch (BadRequest) { $this->addToAssertionCount(1); }
            }
        } finally { putenv($previous === false ? 'AI_CREDIT_EXECUTION_SECRET' : 'AI_CREDIT_EXECUTION_SECRET=' . $previous); }
    }
}
