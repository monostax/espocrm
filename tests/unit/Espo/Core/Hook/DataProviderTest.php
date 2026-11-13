<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace tests\unit\Espo\Core\Hook;

use Espo\Core\Hook\DataProvider;
use PHPUnit\Framework\TestCase;
use tests\unit\ReflectionHelper;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Module\PathProvider;

class DataProviderTest extends TestCase
{
    private string $filesPath = 'tests/unit/testData/Hooks';

    private ?Config\SystemConfig $systemConfig = null;
    private ?PathProvider $pathProvider = null;
    private ?ReflectionHelper $reflectionHelper = null;
    private ?Metadata $metadata = null;

    protected function setUp(): void
    {
        $this->metadata = $this->createMock(Metadata::class);
            $this->getMockBuilder(Metadata::class)->disableOriginalConstructor()->getMock();

        $this->systemConfig = $this->createMock(Config\SystemConfig::class);

        $dataCache = $this->createMock(DataCache::class);
        $fileManager = new FileManager();
        $this->pathProvider = $this->createMock(PathProvider::class);

        $dataProvider = new DataProvider(
            systemConfig: $this->systemConfig,
            fileManager: $fileManager,
            pathProvider: $this->pathProvider,
            dataCache: $dataCache,
            metadata: $this->metadata,
        );

        $this->reflectionHelper = new ReflectionHelper($dataProvider);
    }

    private function initPathProvider(string $folder): void
    {
        $this->pathProvider
            ->method('getCustom')
            ->willReturn($this->filesPath . '/' . $folder . '/custom/Espo/Custom/');

        $this->pathProvider
            ->method('getCore')
            ->willReturn($this->filesPath . '/' . $folder . '/application/Espo/');

        $this->pathProvider
            ->method('getModule')
            ->willReturnCallback(
                function (?string $moduleName) use ($folder): string {
                    $path = $this->filesPath . '/' . $folder . '/application/Espo/Modules/{*}/';

                    if ($moduleName === null) {
                        return $path;
                    }

                    return str_replace('{*}', $moduleName, $path);
                }
            );
    }

    public function testHookExists(): void
    {
        $data = [
          [
            'className' => 'Espo\\Hooks\\Note\\Stream',
            'order' => 8,
          ],
          [
            'className' => 'Espo\\Hooks\\Note\\Mentions',
            'order' => 9,
          ],
          [
            'className' => 'Espo\\Hooks\\Note\\Notifications',
            'order' => 14,
          ],
        ];

        $this->assertTrue(
            $this->reflectionHelper->invokeMethod('hookExists', ['Espo\\Hooks\\Note\\Mentions', $data])
        );
        $this->assertTrue(
            $this->reflectionHelper->invokeMethod('hookExists', ['Espo\\Modules\\Crm\\Hooks\\Note\\Mentions', $data])
        );
        $this->assertTrue(
            $this->reflectionHelper->invokeMethod('hookExists', ['Espo\\Modules\\Test\\Hooks\\Note\\Mentions', $data])
        );
        $this->assertTrue(
            $this->reflectionHelper->invokeMethod('hookExists', ['Espo\\Modules\\Test\\Hooks\\Common\\Stream', $data])
        );
        $this->assertFalse(
            $this->reflectionHelper->invokeMethod('hookExists', ['Espo\\Hooks\\Note\\TestHook', $data])
        );
    }

    public function testSortHooks(): void
    {
        $data = [
            'Common' =>
            [
              'afterSave' =>
              [
                [
                    'className' => 'Espo\\Hooks\\Common\\AssignmentEmailNotification',
                    'order' => 9,
                ],
                [
                    'className' => 'Espo\\Hooks\\Common\\Notifications',
                    'order' => 10,
                ],
                [
                    'className' => 'Espo\\Hooks\\Common\\Stream',
                    'order' => 9,
                ],
              ],
              'beforeSave' =>
              [
                [
                    'className' => 'Espo\\Hooks\\Common\\Formula',
                    'order' => 5,
                ],
                [
                    'className' => 'Espo\\Hooks\\Common\\NextNumber',
                    'order' => 10,
                ],
                [
                    'className' => 'Espo\\Hooks\\Common\\CurrencyConverted',
                    'order' => 1,
                ],
              ],
            ],
            'Note' =>
            [
              'beforeSave' =>
              [
                [
                    'className' => 'Espo\\Hooks\\Note\\Mentions',
                    'order' => 9,
                ],
              ],
              'afterSave' =>
              [
                [
                    'className' => 'Espo\\Hooks\\Note\\Notifications',
                    'order' => 14,
                ],
              ],
            ],
        ];

        $result = [
          'Common' =>
          [
            'afterSave' =>
            [
                [
                    'className' => 'Espo\\Hooks\\Common\\AssignmentEmailNotification',
                    'order' => 9,
                ],
                [
                    'className' => 'Espo\\Hooks\\Common\\Stream',
                    'order' => 9,
                ],
                [
                    'className' => 'Espo\\Hooks\\Common\\Notifications',
                    'order' => 10,
                ],
            ],
            'beforeSave' =>
            [
                [
                    'className' => 'Espo\\Hooks\\Common\\CurrencyConverted',
                    'order' => 1,
                ],
                [
                    'className' => 'Espo\\Hooks\\Common\\Formula',
                    'order' => 5,
                ],
                [
                    'className' => 'Espo\\Hooks\\Common\\NextNumber',
                    'order' => 10,
                ],
            ],
          ],
          'Note' =>
          [
            'beforeSave' =>
            [
                [
                    'className' => 'Espo\\Hooks\\Note\\Mentions',
                    'order' => 9,
                ],
            ],
            'afterSave' =>
            [
                [
                    'className' => 'Espo\\Hooks\\Note\\Notifications',
                    'order' => 14,
                ],
            ],
          ],
        ];

        $this->assertEquals($result, $this->reflectionHelper->invokeMethod('sortHooks', [$data]));
    }

    public function testCase1CustomHook(): void
    {
        $this->initPathProvider('testCase1');

        $this->systemConfig
            ->expects($this->exactly(2))
            ->method('useCache')
            ->willReturn(false);

        $this->metadata
            ->expects($this->once())
            ->method('getModuleList')
            ->willReturn([
                'Crm',
                'Test',
            ]);

        $this->reflectionHelper->invokeMethod('load');

        $result = [
          'Note' =>
          [
            'beforeSave' =>
            [
                [
                    'className' => 'tests\\unit\\testData\\Hooks\\testCase1\\custom\\Espo\\Custom\\Hooks\\Note\\Mentions',
                    'order' => 7,
                ],
            ],
          ],
        ];

        $this->assertEquals($result, $this->reflectionHelper->getProperty('data'));
    }

    public function testCase2ModuleHook1(): void
    {
        $this->initPathProvider('testCase2');

        $this->systemConfig
            ->expects($this->exactly(2))
            ->method('useCache')
            ->willReturn(false);

        $this->metadata
            ->expects($this->once())
            ->method('getModuleList')
            ->willReturn([
                'Crm',
                'Test',
            ]);

        $this->reflectionHelper->invokeMethod('load');

        $result = [
          'Note' =>
          [
            'beforeSave' =>
            [
                [
                    'className' =>
                    'tests\\unit\\testData\\Hooks\\testCase2\\application\\Espo\\Modules\\Crm\\Hooks\\Note\\Mentions',
                    'order' => 9,
                ],
            ],
          ],
        ];

        $this->assertEquals($result, $this->reflectionHelper->getProperty('data'));
    }

    public function testCase2ModuleHookReverseModuleOrder(): void
    {
        $this->initPathProvider('testCase2');

        $this->systemConfig
            ->expects($this->exactly(2))
            ->method('useCache')
            ->willReturn(false);

        $this->metadata
            ->expects($this->once())
            ->method('getModuleList')
            ->willReturn([
                'Test',
                'Crm',
            ]);

        $this->reflectionHelper->invokeMethod('load');

        $result = [
          'Note' =>
          [
            'beforeSave' =>
            [
                [
                    'className' =>
                        'tests\\unit\\testData\\Hooks\\testCase2\\application\\Espo\\Modules\\Test\\Hooks\\Note\\Mentions',
                    'order' => 9,
                ],
            ],
          ],
        ];

        $this->assertEquals($result, $this->reflectionHelper->getProperty('data'));
    }

    public function testCase3CoreHook()
    {
        $this->initPathProvider('testCase3');

        $this->systemConfig
            ->expects($this->exactly(2))
            ->method('useCache')
            ->willReturn(false);

        $this->metadata
            ->expects($this->once())
            ->method('getModuleList')
            ->willReturn([]);

        $this->reflectionHelper->invokeMethod('load');

        $result = [
          'Note' =>
          [
            'beforeSave' =>
            [
                [
                    'className' => 'tests\\unit\\testData\\Hooks\\testCase3\\application\\Espo\\Hooks\\Note\\Mentions',
                    'order' => 9,
                ],
            ],
          ],
        ];

        $this->assertEquals($result, $this->reflectionHelper->getProperty('data'));
    }
}
