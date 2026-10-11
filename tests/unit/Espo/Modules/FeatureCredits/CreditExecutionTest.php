<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

require_once __DIR__ . '/ExecutionFixtures.php';

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureCredits\Accounting\TerminalInput;
use Espo\Modules\FeatureCredits\Controllers\CreditExecution;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CreditExecutionTest extends TestCase
{
    public static function command(): object
    {
        return ExecutionFixtures::outcome();
    }

    public function testExactOutcomeAndExplicitUnknowns(): void
    {
        $input = self::command();
        $known = new TerminalInput($input);
        $this->assertTrue($known->outcome->measured());
        $this->assertSame(1, $known->outcome->outputTokens);
        $input->outcome = 'cancelled';
        $input->inputTokens = $input->cachedInputTokens = $input->outputTokens = null;
        $unknown = new TerminalInput($input);
        $this->assertFalse($unknown->outcome->measured());
        $this->assertNull($unknown->outcome->outputTokens);
        $input->evidence->source = 'changed';
        $this->assertStringNotContainsString('changed', $unknown->outcome->json);
        foreach (['settle', 'release'] as $operation) {
            $terminal = self::command();
            foreach (['requestId', 'outcome', 'inputTokens', 'cachedInputTokens', 'outputTokens', 'evidence'] as $field) unset($terminal->$field);
            $terminal->operation = $operation;
            $this->assertNull((new TerminalInput($terminal))->outcome);
        }
    }

    public static function invalidCommands(): iterable
    {
        foreach (['billingRegime' => 'legacy-engagement-v1', 'operation' => 'admit', 'tenantId' => '../tenant',
            'runId' => 'wrong', 'workflowRunId' => '', 'executionId' => 'wrong', 'usageId' => 123,
            'billable' => true, 'credits' => '0', 'reasoningTokens' => 4, 'requestId' => [], 'outcome' => 'failed',
            'providerRequestId' => 123, 'evidence' => [], 'inputTokens' => '0', 'cachedInputTokens' => 1,
            'outputTokens' => 9007199254740992] as $field => $value) {
            $input = self::command(); $input->$field = $value;
            yield $field => [$input];
        }
        foreach (['inputTokens', 'cachedInputTokens', 'outputTokens', 'billingRegime'] as $field) {
            $input = self::command(); unset($input->$field); yield 'missing ' . $field => [$input];
        }
        foreach ([-1, 1.5, true, (object) []] as $i => $value) {
            $input = self::command(); $input->outputTokens = $value; yield 'token ' . $i => [$input];
        }
        $input = self::command(); $input->operation = 'settle'; yield 'settlement with metering' => [$input];
        $input = self::command(); $input->evidence = (object) []; yield 'empty evidence' => [$input];
    }

    #[DataProvider('invalidCommands')]
    public function testInvalidCommandsFailBeforeAccounting(object $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TerminalInput($input);
    }

    public function testSignedTransportFailsClosedAndSeparatesLegacyProtocol(): void
    {
        $previous = getenv('AI_CREDIT_EXECUTION_SECRET');
        putenv('AI_CREDIT_EXECUTION_SECRET=test-only-secret');
        try {
            $controller = (new ReflectionClass(CreditExecution::class))->newInstanceWithoutConstructor();
            $raw = json_encode(self::command(), JSON_THROW_ON_ERROR);
            $timestamp = (string) time();
            $sign = static fn (string $body, string $time, string $domain = 'credit-execution-v1') =>
                'sha256=' . hash_hmac('sha256', "$domain.$time.$body", 'test-only-secret');
            foreach ([['', ''], [$timestamp, 'wrong'], [(string) (time() - 600), $sign($raw, (string) (time() - 600))],
                [(string) (time() + 600), $sign($raw, (string) (time() + 600))],
                [$timestamp, $sign($raw, $timestamp, 'ai-budget-v1')], [$timestamp, $sign($raw . ' ', $timestamp)],
                ['999999999999999999999', 'wrong']] as [$time, $signature]) {
                try { $controller->postActionExecute($this->wire($raw, $time, $signature)); $this->fail('Invalid signature accepted.'); }
                catch (Forbidden) { $this->addToAssertionCount(1); }
            }
            foreach (['{', '[]', '{}', str_repeat(' ', 65537), str_replace('"outputTokens":1', '"outputTokens":"1"', $raw)] as $body) {
                try { $controller->postActionExecute($this->wire($body, $timestamp, $sign($body, $timestamp))); $this->fail('Invalid command accepted.'); }
                catch (BadRequest) { $this->addToAssertionCount(1); }
            }
            putenv('AI_CREDIT_EXECUTION_SECRET');
            $this->expectException(Forbidden::class);
            $controller->postActionExecute($this->wire($raw, $timestamp, $sign($raw, $timestamp)));
        } finally {
            putenv($previous === false ? 'AI_CREDIT_EXECUTION_SECRET' : 'AI_CREDIT_EXECUTION_SECRET=' . $previous);
        }
    }

    private function wire(string $raw, string $timestamp, string $signature): Request
    {
        $request = $this->createStub(Request::class);
        $request->method('getBodyContents')->willReturn($raw);
        $request->method('getHeader')->willReturnCallback(static fn ($header) => match ($header) {
            'X-Credit-Execution-Timestamp' => $timestamp, 'X-Credit-Execution-Signature' => $signature, default => null,
        });
        return $request;
    }

    public function testServiceOnlyRoute(): void
    {
        $routes = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/routes.json'), true, 512, JSON_THROW_ON_ERROR);
        $route = array_values(array_filter($routes, static fn ($r) => $r['route'] === '/CreditExecution'));
        $this->assertCount(1, $route);
        $this->assertSame('post', $route[0]['method']);
        $this->assertTrue($route[0]['noAuth']); // HMAC authentication is mandatory in the controller.
        $this->assertSame(['controller' => 'CreditExecution', 'action' => 'execute'], $route[0]['params']);
    }
}
