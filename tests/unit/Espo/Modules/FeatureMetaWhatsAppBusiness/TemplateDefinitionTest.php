<?php

namespace tests\unit\Espo\Modules\FeatureMetaWhatsAppBusiness;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDefinition;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateParameters;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateSample;
use PHPUnit\Framework\TestCase;

class TemplateDefinitionTest extends TestCase
{
    public static function definition(): array
    {
        return ['name' => 'order_ready', 'language' => 'pt_BR', 'category' => 'UTILITY', 'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Hello {{1}}', 'example' => ['header_text' => ['Ana']]],
            ['type' => 'BODY', 'text' => 'Order {{1}} is ready', 'example' => ['body_text' => [['123']]]],
            ['type' => 'FOOTER', 'text' => 'Thank you'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Thanks'],
                ['type' => 'URL', 'text' => 'Website', 'url' => 'https://example.com/orders'],
                ['type' => 'PHONE_NUMBER', 'text' => 'Call', 'phone_number' => '+5511999999999'],
            ]],
        ]];
    }

    public function testSupportedDefinitionAndIndependentMappings(): void
    {
        $definition = (new TemplateDefinition())->validate(self::definition());
        TemplateParameters::validate($definition, ['1' => '{{order}}'], ['1' => '{{firstName}}'], null, null);
        $payload = TemplateParameters::processed(['2' => 'second', '1' => 'body'], ['1' => 'header'], null, null);
        $this->assertSame('{"body":{"1":"body","2":"second"},"header":{"1":"header"}}', json_encode($payload));
        $this->assertSame('{}', json_encode(TemplateParameters::processed([], [], null, null)));
        $this->assertSame('{"header":{"media_url":"https:\/\/example.com\/a.jpg","media_type":"image"}}',
            json_encode(TemplateParameters::processed([], [], 'https://example.com/a.jpg', 'image')));
    }

    /** @dataProvider invalidDefinitions */
    public function testInvalidAndAdvancedContentIsNeverReduced(array $definition): void
    {
        $validator = new TemplateDefinition();
        $this->assertFalse($validator->supported($definition));
        $this->expectException(BadRequest::class);
        $validator->validate($definition);
    }

    public static function invalidDefinitions(): array
    {
        $base = self::definition();
        $cases = [];
        foreach ([['name', 'UPPER'], ['name', str_repeat('x', 513)], ['language', 'xx'],
            ['category', 'AUTHENTICATION'], ['parameter_format', 'NAMED']] as [$key, $value]) {
            $cases[] = [array_replace($base, [$key => $value])];
        }
        foreach (['Hello {{2}}', 'Hello {{name}}', 'Hello {{0}}', str_repeat('á', 1025)] as $text) {
            $d = $base; $d['components'][1]['text'] = $text; $cases[] = [$d];
        }
        $d = $base; $d['components'][0]['text'] = '{{1}} {{2}}'; $cases[] = [$d];
        $d = $base; $d['components'][2]['text'] = 'Footer {{1}}'; $cases[] = [$d];
        $d = $base; $d['components'][] = ['type' => 'CAROUSEL', 'cards' => []]; $cases[] = [$d];
        $d = $base; $d['components'][0]['format'] = 'LOCATION'; $cases[] = [$d];
        $d = $base; $d['components'][3]['buttons'][1]['url'] .= '/{{1}}'; $cases[] = [$d];
        $d = $base; $d['components'][3]['buttons'][] = ['type' => 'QUICK_REPLY', 'text' => 'Mixed']; $cases[] = [$d];
        $d = $base; $d['components'][3]['buttons'][0]['type'] = 'FLOW'; $cases[] = [$d];
        $d = $base; $d['components'][1]['add_security_recommendation'] = true; $cases[] = [$d];
        return $cases;
    }

    public function testExamplesAreRequiredOnSubmissionButNotClassification(): void
    {
        $definition = self::definition();
        unset($definition['components'][0]['example']);
        $this->assertTrue((new TemplateDefinition())->supported($definition));
        $this->expectException(BadRequest::class);
        (new TemplateDefinition())->validate($definition);
    }

    public function testNoLegacyHeaderInference(): void
    {
        $this->expectException(Error::class);
        TemplateParameters::validate(self::definition(), ['1' => '{{firstName}}'], null, null, null);
    }

    public function testUnresolvedRecipientFailsBeforePayload(): void
    {
        $this->expectException(Error::class);
        TemplateParameters::processed(['1' => ''], [], null, null);
    }

    public function testTextAndMediaHeaderCannotMix(): void
    {
        $this->expectException(Error::class);
        TemplateParameters::processed([], ['1' => 'header'], 'https://example.com/a.jpg', 'image');
    }

    public function testCacheComparisonIgnoresExamplesButNotLanguageStatusOrOrder(): void
    {
        $live = self::definition() + ['status' => 'APPROVED'];
        $cached = $live;
        $cached['components'][0]['example'] = ['header_text' => ['different']];
        $cached['id'] = 'different-id';
        $this->assertSame(TemplateParameters::signature($live), TemplateParameters::signature($cached));
        foreach (['language' => 'en_US', 'status' => 'PAUSED', 'category' => 'MARKETING', 'parameter_format' => 'NAMED'] as $k => $v) {
            $this->assertNotSame(TemplateParameters::signature($live), TemplateParameters::signature(array_replace($cached, [$k => $v])));
        }
        $cached['components'] = array_reverse($cached['components']);
        $this->assertNotSame(TemplateParameters::signature($live), TemplateParameters::signature($cached));
    }

    /** @dataProvider sampleBoundaries */
    public function testSampleContentAndExactSizeBoundary(string $bytes, string $format, int $limit, string $mime): void
    {
        $path = tempnam(sys_get_temp_dir(), 'template-sample-');
        try {
            file_put_contents($path, $bytes . str_repeat(' ', $limit - strlen($bytes)));
            $sample = (new TemplateSample())->validate($path, $format);
            $this->assertSame($limit, $sample['size']);
            $this->assertSame($mime, $sample['mime']);
            file_put_contents($path, ' ', FILE_APPEND);
            clearstatcache(true, $path);
            $this->expectException(BadRequest::class);
            (new TemplateSample())->validate($path, $format);
        } finally { unlink($path); }
    }

    public static function sampleBoundaries(): array
    {
        return [
            'PNG' => [base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII='),
                'IMAGE', 5000000, 'image/png'],
            'MP4' => [pack('N', 24) . 'ftypmp42' . pack('N', 0) . 'mp42isom', 'VIDEO', 16000000, 'video/mp4'],
            'PDF' => ["%PDF-1.7\n", 'DOCUMENT', 16000000, 'application/pdf'],
        ];
    }

    /** @dataProvider badSamples */
    public function testEmptyOrSpoofedSamplesAreRejected(string $bytes, string $format): void
    {
        $path = tempnam(sys_get_temp_dir(), 'template-sample-');
        try {
            file_put_contents($path, $bytes);
            $this->expectException(BadRequest::class);
            (new TemplateSample())->validate($path, $format);
        } finally { unlink($path); }
    }

    public static function badSamples(): array
    {
        return [['', 'IMAGE'], ['not actually an image.jpg', 'IMAGE'], ['%PDF-1.7\nhello', 'VIDEO'], ['<?php echo 1;', 'DOCUMENT']];
    }
}
