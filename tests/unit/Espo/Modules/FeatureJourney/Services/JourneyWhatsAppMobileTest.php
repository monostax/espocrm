<?php

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * WhatsApp sends skip landlines: enrichment stores company landlines next to
 * mobiles, often labelled Mobile or Office regardless of what they are.
 */
class JourneyWhatsAppMobileTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function numbers(): array
    {
        return [
            'BR mobile (São Paulo)' => ['+5511947050717', true],
            'BR landline (São Paulo)' => ['+551147050717', false],
            'BR mobile (other DDD)' => ['+5531991234567', true],
            'BR landline (other DDD)' => ['+553132345678', false],
            'US (fixed line or mobile)' => ['+12025550123', true],
            'PT mobile' => ['+351912345678', true],
            'PT landline' => ['+351212345678', false],
            'garbage' => ['not-a-number', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('numbers')]
    public function testOnlyMobileLinesQualify(string $number, bool $mobile): void
    {
        $outbound = (new ReflectionClass(JourneyWhatsAppOutbound::class))->newInstanceWithoutConstructor();

        $this->assertSame($mobile, $outbound->isMobile($number));
    }
}
