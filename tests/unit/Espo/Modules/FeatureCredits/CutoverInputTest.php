<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\CutoverInput;
use Espo\Modules\FeatureCredits\Accounting\ExecutionIdentity;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CutoverInputTest extends TestCase
{
    public function testCanonicalDetachedCutoverEvidence(): void
    {
        $evidence = (object) ['owner' => 'operator', 'approval' => (object) ['ticket' => 'approved']];
        $input = new CutoverInput('tenant', '2026-10-10 12:00:00', $evidence);
        $evidence->approval->ticket = 'changed';
        $same = new CutoverInput('tenant', '2026-10-10 12:00:00', (object) ['approval' => (object) ['ticket' => 'approved'], 'owner' => 'operator']);
        $this->assertSame($input->hash, $same->hash);
        $this->assertSame($input->evidenceJson, $same->evidenceJson);
    }

    public static function invalidCutovers(): iterable
    {
        yield ['tenant', '2026-02-30 12:00:00', (object) ['approved' => true]];
        yield ['tenant', '2026-10-10T12:00:00Z', (object) ['approved' => true]];
        yield ['tenant', '2026-10-10 12:00:00', (object) []];
        yield ['', '2026-10-10 12:00:00', (object) ['approved' => true]];
    }

    #[DataProvider('invalidCutovers')]
    public function testRejectsInvalidCutovers(string $tenant, string $time, object $evidence): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CutoverInput($tenant, $time, $evidence);
    }

    public function testExecutionBindingIsCanonicalAndCannotBeRemovedOnReplay(): void
    {
        $identity = new ExecutionIdentity('aaaaaaaaaaaaaaaaa', 'workflow', '00000000-0000-4000-8000-000000000000');
        $input = new ReservationInput('tenant', 'ai', $identity->operationKey(), $identity->executionId, 'rate', '1', execution: $identity);
        $changed = new ExecutionIdentity($identity->runId, 'other-workflow', $identity->executionId);
        $this->assertNotSame($input->hash, (new ReservationInput('tenant', 'ai', $changed->operationKey(), $changed->executionId, 'rate', '1', execution: $changed))->hash);
        $this->expectException(InvalidArgumentException::class);
        new ReservationInput('tenant', 'ai', $identity->operationKey(), $identity->executionId, 'rate', '1');
    }

    public function testCannotBindAnUnrelatedOperation(): void
    {
        $identity = new ExecutionIdentity('aaaaaaaaaaaaaaaaa', 'workflow', '00000000-0000-4000-8000-000000000000');
        $this->expectException(InvalidArgumentException::class);
        new ReservationInput('tenant', 'ai', 'different-operation', $identity->executionId, 'rate', '1', execution: $identity);
    }
}
