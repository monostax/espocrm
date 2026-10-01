<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\TransitionEvaluator;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class TransitionEvaluatorTest extends TestCase
{
    /** @dataProvider replies */
    public function testEnrollmentReplyEvidence(?string $replyAt, bool $expected): void
    {
        $reflection = new ReflectionClass(TransitionEvaluator::class);
        $evaluator = $reflection->newInstanceWithoutConstructor();
        $record = $this->createStub(Entity::class);
        $record->method('get')->willReturnCallback(static fn ($key) => [
            'targetType' => 'Opportunity', 'createdAt' => '2026-10-01 15:00:00',
            'whatsAppRepliedAt' => $replyAt,
        ][$key] ?? null);
        $method = $reflection->getMethod('evalEventHistory');
        $this->assertSame($expected, $method->invoke($evaluator, [
            'code' => 'whatsapp_replied', 'sinceEnrollment' => true,
        ], $record));
    }

    public static function replies(): array
    {
        return [[null, false], ['2026-10-01 14:59:59', false], ['2026-10-01 15:00:00', true],
            ['2026-10-02 16:00:00', true]];
    }

    public function testCompareSupportsEveryPayloadOperatorFromTheRulesBuilder(): void
    {
        $reflection = new ReflectionClass(TransitionEvaluator::class);
        $evaluator = $reflection->newInstanceWithoutConstructor();
        $compare = $reflection->getMethod('compare');

        $this->assertTrue($compare->invoke($evaluator, 'hello', 'notContains', 'bye'));
        $this->assertTrue($compare->invoke($evaluator, null, 'notExists', null));
        $this->assertTrue($compare->invoke($evaluator, '', 'notExists', null));
        $this->assertTrue($compare->invoke($evaluator, 10, 'greaterThanOrEquals', '10'));
        $this->assertTrue($compare->invoke($evaluator, 10, 'lessThanOrEquals', '10'));
        $this->assertTrue($compare->invoke($evaluator, 'pro', 'in', 'free, pro'));
        $this->assertTrue($compare->invoke($evaluator, 'pro', 'in', '["free", "pro"]'));
    }

    public function testMissingCustomEvaluatorFailsClosed(): void
    {
        $reflection = new ReflectionClass(TransitionEvaluator::class);
        $evaluator = $reflection->newInstanceWithoutConstructor();
        $log = $this->createMock(Log::class);
        $log->expects($this->once())->method('warning');
        $reflection->getProperty('log')->setValue($evaluator, $log);

        $record = $this->createStub(Entity::class);
        $transition = $this->createStub(Entity::class);
        $transition->method('get')->willReturnCallback(
            static fn (string $name): mixed => $name === 'evaluatorClassName'
                ? 'Missing\\JourneyEvaluator'
                : null,
        );

        $this->assertFalse($evaluator->evaluate($record, $transition));
    }
}
