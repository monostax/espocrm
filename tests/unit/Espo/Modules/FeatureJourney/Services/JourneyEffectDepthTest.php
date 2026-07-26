<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureJourney\Services\JourneyEffectDepth;
use PHPUnit\Framework\TestCase;

class JourneyEffectDepthTest extends TestCase
{
    protected function setUp(): void
    {
        JourneyEffectDepth::reset();
    }

    protected function tearDown(): void
    {
        JourneyEffectDepth::reset();
    }

    public function testAllowsUpToMaxDepth(): void
    {
        $hits = 0;

        JourneyEffectDepth::run(function () use (&$hits) {
            $hits++;
            JourneyEffectDepth::run(function () use (&$hits) {
                $hits++;
                JourneyEffectDepth::run(function () use (&$hits) {
                    $hits++;
                    $this->assertSame(3, JourneyEffectDepth::current());
                });
            });
        });

        $this->assertSame(3, $hits);
        $this->assertSame(0, JourneyEffectDepth::current());
    }

    public function testThrowsWhenExceedingMaxDepth(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/max nested/');

        JourneyEffectDepth::run(function () {
            JourneyEffectDepth::run(function () {
                JourneyEffectDepth::run(function () {
                    JourneyEffectDepth::run(function () {
                        return null;
                    });
                });
            });
        });
    }

    public function testResetsAfterException(): void
    {
        try {
            JourneyEffectDepth::run(function () {
                throw new Error('boom');
            });
        } catch (Error) {
            // expected
        }

        $this->assertSame(0, JourneyEffectDepth::current());
    }
}
