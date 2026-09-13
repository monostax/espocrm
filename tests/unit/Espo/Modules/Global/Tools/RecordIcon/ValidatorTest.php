<?php

namespace tests\unit\Espo\Modules\Global\Tools\RecordIcon;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Tools\RecordIcon\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    private function validator(): Validator
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(static function ($key, $default = null) {
            return match ($key) {
                'app.clientIcons.classList' => ['ti ti-rocket', 'ti ti-star-filled'],
                'app.recordIcons.fontAwesomeClassList' => ['fas fa-rocket', 'far fa-star'],
                default => $default,
            };
        });
        return new Validator($metadata);
    }

    public function testSupportedEmojiSequencesAndFamilies(): void
    {
        $validator = $this->validator();
        $validator->validate(null);
        foreach (['🚀', '🇧🇷', '👍🏽', '👩🏽‍💻', '1️⃣', '❤️'] as $value) {
            $validator->validate((object) ['type' => 'emoji', 'value' => $value]);
        }
        foreach (['ti ti-rocket', 'ti ti-star-filled', 'fas fa-rocket', 'far fa-star'] as $value) {
            $validator->validate((object) ['type' => 'icon', 'value' => $value]);
        }
        $this->addToAssertionCount(11);
    }

    public function testRejectsMalformedUnregisteredAndMultiEmojiValues(): void
    {
        $validator = $this->validator();
        $invalid = [
            '🚀', [], (object) [],
            (object) ['type' => 'emoji', 'value' => 'hello'],
            (object) ['type' => 'emoji', 'value' => '🚀🚀'],
            (object) ['type' => 'emoji', 'value' => '<img src=x onerror=alert(1)>🚀'],
            (object) ['type' => 'emoji', 'value' => str_repeat('🚀', 100)],
            (object) ['type' => 'emoji', 'value' => '🚀', 'extra' => true],
            (object) ['type' => 'icon', 'value' => 'ti ti-not-an-icon'],
            (object) ['type' => 'icon', 'value' => 'fas fa-rocket fa-spin'],
            (object) ['type' => 'icon', 'value' => 'ti ti-rocket" onclick="alert(1)'],
            (object) ['type' => 'image', 'value' => 'https://example.com/icon.svg'],
        ];
        foreach ($invalid as $icon) {
            try {
                $validator->validate($icon);
                $this->fail('Expected icon to be rejected: ' . json_encode($icon));
            } catch (BadRequest $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function testAllPickerVariantsAreAcceptedByServer(): void
    {
        $validator = $this->validator();
        foreach (['en', 'pt'] as $locale) {
            $catalog = json_decode(file_get_contents(
                "client/custom/modules/global/res/record-icons/$locale.json"
            ), true, 512, JSON_THROW_ON_ERROR);
            foreach ($catalog['items'] as $item) {
                foreach (array_merge([$item], $item['skins']) as $variant) {
                    $validator->validate((object) ['type' => 'emoji', 'value' => $variant['value']]);
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}
