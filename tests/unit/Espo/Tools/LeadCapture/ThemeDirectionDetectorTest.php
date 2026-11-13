<?php

namespace tests\unit\Espo\Tools\LeadCapture;

use Espo\Core\Utils\Language;
use Espo\Core\Utils\Theme\Direction;
use Espo\Entities\LeadCapture;
use Espo\Tools\LeadCapture\ThemeDirectionDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThemeDirectionDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Direction}>
     */
    public static function directionProvider(): iterable
    {
        yield ['ar_AR', Direction::Rtl];
        yield ['fa_IR', Direction::Rtl];
        yield ['he_IL', Direction::Rtl];
        yield ['ur_IN', Direction::Rtl];
        yield ['en_US', Direction::Ltr];
        yield ['tr_TR', Direction::Ltr];
    }

    #[DataProvider('directionProvider')]
    public function testDirectionFromFormLanguage(string $language, Direction $expected): void
    {
        $leadCapture = $this->createMock(LeadCapture::class);
        $leadCapture
            ->method('getFormLanguage')
            ->willReturn($language);

        $language = $this->createMock(Language::class);
        $language
            ->method('getLanguage')
            ->willReturn('en_US');

        $detector = new ThemeDirectionDetector(
            language: $language,
        );

        $this->assertEquals($expected, $detector->detect($leadCapture));
    }
}
