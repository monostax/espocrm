<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureTaskRecurrence;

use Espo\Core\Utils\FieldUtil;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureTaskRecurrence\Tools\Template;
use PHPUnit\Framework\TestCase;

class TemplateTest extends TestCase
{
    private function template(array $fields = []): Template
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturn($fields);
        $fieldUtil = $this->createMock(FieldUtil::class);
        $fieldUtil->method('getAttributeList')->willReturnCallback(fn ($type, $field) => [$field]);
        return new Template($metadata, $fieldUtil);
    }

    public function testCopyPolicyExcludesIdentitiesOwnedFilesAndUnvalidatedCustomRelationships(): void
    {
        $template = $this->template([
            'name' => ['type' => 'varchar'], 'reminders' => ['type' => 'jsonArray', 'duplicateIgnore' => true, 'notStorable' => true],
            'id' => ['type' => 'id'], 'status' => ['type' => 'enum'],
            'aiTaskKey' => ['type' => 'varchar', 'isCustom' => true, 'unique' => true],
            'cCounter' => ['type' => 'int', 'isCustom' => true], 'cAudit' => ['type' => 'varchar', 'isCustom' => true, 'readOnly' => true],
            'cCourseName' => ['type' => 'varchar', 'isCustom' => true],
            'cPrivate' => ['type' => 'varchar', 'isCustom' => true, 'recurrenceCopyDisabled' => true],
            'attachments' => ['type' => 'attachmentMultiple'], 'cFile' => ['type' => 'file', 'isCustom' => true],
            'cReusable' => ['type' => 'file', 'isCustom' => true, 'recurrenceReusable' => true],
            'cContact' => ['type' => 'link', 'isCustom' => true],
        ]);
        self::assertSame(['name', 'reminders', 'cCounter', 'cCourseName', 'cReusable'], $template->attributes());
        self::assertEquals((object) ['name' => 'Inherited', 'cCounter' => 4], $template->patch((object) [
            'name' => 'Inherited', 'cCounter' => 4, 'aiTaskKey' => 'replay', 'status' => 'Completed', 'cFile' => 'owned-file',
        ]));
    }

    public function testTimedStartOffsetKeepsCivilDurationAcrossDst(): void
    {
        $dates = $this->template()->dates((object) ['dateEnd' => '2026-03-07 14:00:00', 'dateStart' => '2026-03-07 13:00:00'],
            '2026-03-08 13:00:00', (object) ['timezone' => 'America/New_York', 'dateOnly' => false]);
        self::assertSame('2026-03-08 12:00:00', $dates->dateStart);
        self::assertSame('2026-03-08 13:00:00', $dates->dateEnd);
        self::assertNull($dates->dateStartDate);
    }

    public function testDateOnlyAndMixedStartsKeepTheirNativeTypes(): void
    {
        $definition = (object) ['timezone' => 'America/New_York', 'dateOnly' => true];
        $dates = $this->template()->dates((object) ['dateEndDate' => '2026-03-07', 'dateStartDate' => '2026-03-05'], '2026-03-08', $definition);
        self::assertSame('2026-03-06', $dates->dateStartDate);
        self::assertNull($dates->dateStart);
        self::assertNull($dates->dateEnd);
        $definition->dateOnly = false;
        $mixed = $this->template()->dates((object) ['dateEnd' => '2026-03-07 14:00:00', 'dateStartDate' => '2026-03-06'], '2026-03-08 13:00:00', $definition);
        self::assertSame('2026-03-07', $mixed->dateStartDate);
        self::assertNull($mixed->dateStart);
        $definition->dateOnly = true;
        $timedStart = $this->template()->dates((object) ['dateEndDate' => '2026-03-07', 'dateStart' => '2026-03-06 14:00:00'], '2026-03-09', $definition);
        self::assertSame('2026-03-08 13:00:00', $timedStart->dateStart);
        self::assertNull($timedStart->dateStartDate);
    }
}
