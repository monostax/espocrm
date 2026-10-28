<?php

namespace tests\unit\Espo\Modules\FeatureMetaWhatsAppBusiness;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateParameters;
use PHPUnit\Framework\TestCase;

class TemplateParametersTest extends TestCase
{
    public static function namedTemplate(): array
    {
        return ['name' => 'order_ready', 'language' => 'pt_BR', 'category' => 'UTILITY',
            'status' => 'APPROVED', 'parameter_format' => 'NAMED', 'components' => [
                ['type' => 'BODY', 'text' => 'Hello {{tenant_name}}, order {{order_id}} for {{tenant_name}} is ready.'],
            ]];
    }

    /** @dataProvider namedMappings */
    public function testNamedBodyMappingMatchesLiveNamesIndependentlyOfOrder(mixed $mapping): void
    {
        TemplateParameters::validate(self::namedTemplate(), $mapping, null, null, null);
        $this->assertSame(['order_id' => '{{order}}', 'tenant_name' => '{{name}}'],
            TemplateParameters::mapping($mapping));
    }

    public static function namedMappings(): array
    {
        $mapping = ['tenant_name' => '{{name}}', 'order_id' => '{{order}}'];
        return [[$mapping], [(object) $mapping], [json_encode($mapping)]];
    }

    public function testNamedValuesRemainNamedJsonObjectsInTheChatwootPayload(): void
    {
        $payload = TemplateParameters::processed(['tenant_name' => 'Monostax', 'order_id' => '123'], [], null, null);
        $this->assertSame('{"body":{"order_id":"123","tenant_name":"Monostax"}}', json_encode($payload));
    }

    public function testNamedBodyCanUseAnIndependentMediaHeader(): void
    {
        $template = self::namedTemplate();
        $template['components'][] = ['type' => 'HEADER', 'format' => 'IMAGE'];
        $body = ['order_id' => '123', 'tenant_name' => 'Monostax'];
        TemplateParameters::validate($template, $body, [], 'https://example.com/a.jpg', 'image');
        $payload = TemplateParameters::processed($body, [], 'https://example.com/a.jpg', 'image');
        $this->assertSame(['media_url' => 'https://example.com/a.jpg', 'media_type' => 'image'], $payload->header);
    }

    /** @dataProvider mismatchedMappings */
    public function testNamedMappingMustMatchEveryLiveVariableExactly(array $body): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Repair the BODY parameter mapping to match the live template.');
        TemplateParameters::validate(self::namedTemplate(), $body, [], null, null);
    }

    public static function mismatchedMappings(): array
    {
        return [
            [[]],
            [['tenant_name' => 'Monostax']],
            [['tenant_name' => 'Monostax', 'order_id' => '123', 'extra' => 'unexpected']],
            [['tenant_name' => 'Monostax', 'order_number' => '123']],
            [[1 => 'Monostax', 2 => '123']],
        ];
    }

    public function testPositionalTemplatesStillRejectNamedMappings(): void
    {
        $this->expectException(Error::class);
        TemplateParameters::validate(TemplateDefinitionTest::definition(),
            ['order_id' => '123'], [1 => 'Ana'], null, null);
    }

    /** @dataProvider invalidMappings */
    public function testInvalidOrMixedMappingKeysAndNonStringValuesAreRejected(array $mapping): void
    {
        $this->expectException(Error::class);
        TemplateParameters::mapping($mapping);
    }

    public static function invalidMappings(): array
    {
        return [
            [['tenant_name' => 'Monostax', 1 => 'mixed']],
            [['TenantName' => 'Monostax']],
            [['tenant.name' => 'Monostax']],
            [['tenant-name' => 'Monostax']],
            [['{{tenant_name}}' => 'Monostax']],
            [['' => 'Monostax']],
            [[0 => 'zero']],
            [['01' => 'leading zero']],
            [['tenant_name' => 123]],
            [['tenant_name' => null]],
        ];
    }

    /** @dataProvider invalidNamedText */
    public function testMalformedOrMixedNamedTemplateVariablesFailClosed(string $text): void
    {
        $template = self::namedTemplate();
        $template['components'][0]['text'] = $text;
        $this->expectException(Error::class);
        TemplateParameters::validate($template, ['tenant_name' => 'Monostax'], [], null, null);
    }

    public static function invalidNamedText(): array
    {
        return [['Hello {{tenant_name}} {{1}}'], ['Hello {{tenant-name}}'], ['Hello {{tenant_name}']];
    }

    public function testNamedBodyDoesNotEnableUnsupportedNamedTextHeaders(): void
    {
        $template = self::namedTemplate();
        $template['components'][] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Hello {{first_name}}'];
        $this->expectException(BadRequest::class);
        TemplateParameters::validate($template, ['tenant_name' => 'Monostax', 'order_id' => '123'],
            ['first_name' => 'Ana'], null, null);
    }

    public function testUnknownParameterFormatIsRejected(): void
    {
        $template = self::namedTemplate();
        $template['parameter_format'] = 'UNKNOWN';
        $this->expectException(Error::class);
        TemplateParameters::validate($template, [], [], null, null);
    }

    public function testUnresolvedNamedValuesStillFailBeforeSending(): void
    {
        $this->expectException(Error::class);
        TemplateParameters::processed(['tenant_name' => '{{name}}', 'order_id' => '123'], [], null, null);
    }
}
