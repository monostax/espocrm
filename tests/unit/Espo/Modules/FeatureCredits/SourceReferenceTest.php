<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\SourceReference;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourceReferenceTest extends TestCase
{
    public function testHashPreservesLegacyAndBindsAttribution(): void
    {
        $make = fn ($source = null) => new ReservationInput('tenant', 'ai', 'op', 'execution', 'rate', '1', $source);
        $this->assertSame(hash('sha256', json_encode(['reservation-v1', 'tenant', 'ai', 'op', 'execution', 'rate', '1.0000', '1.0000'])), $make()->hash);
        $source = new SourceReference('Opportunity', 'source');
        $this->assertSame($make($source)->hash, $make(new SourceReference('Opportunity', 'source'))->hash);
        $this->assertNotSame($make()->hash, $make($source)->hash);
        $this->assertNotSame($make($source)->hash, $make(new SourceReference('Opportunity', 'other'))->hash);
        $this->assertNotSame($make($source)->hash, $make(new SourceReference('ChatwootAiAgentRun', 'source'))->hash);
    }

    public static function invalidSources(): array
    {
        return [['User', 'source'], ['opportunity', 'source'], ['Opportunity', ''], ['Opportunity', '../id'], ['Opportunity', str_repeat('a', 25)]];
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSource(string $scope, string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SourceReference($scope, $id);
    }
}
