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

namespace tests\unit\Espo\Core\Utils;

use Espo\Core\Utils\Cache\DataCacheAccess;
use Espo\Core\Utils\Config\SystemConfig;
use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Json;
use PHPUnit\Framework\TestCase;
use tests\unit\ReflectionHelper;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\File\UnifierObj;
use Espo\Core\Utils\File\Unifier;
use Espo\Core\Utils\Module;
use Espo\Core\Utils\Module\PathProvider as ModulePathProvider;
use Espo\Core\Utils\Resource\Reader;
use Espo\Core\Utils\Resource\PathProvider;

class MetadataTest extends TestCase
{
    private ?Metadata $metadata = null;
    private $reflection;
    private ?FileManager $fileManager = null;

    private $customPath;

    protected function setUp(): void
    {
        $this->fileManager = new FileManager();

        $module = new Module($this->fileManager);

        $pathProvider = new PathProvider(new ModulePathProvider($module));

        $unifierObj = new UnifierObj($this->fileManager, $module, $pathProvider);
        $unifier = new Unifier($this->fileManager, $module, $pathProvider);

        $reader = new Reader($unifier, $unifierObj);

        $builderHelper = new Metadata\BuilderHelper();

        $builder = new Metadata\Builder($reader);

        $dataCacheAccess = new DataCacheAccess(
            dataCache: $this->createMock(DataCache::class),
            systemConfig: $this->createMock(SystemConfig::class),
            log: $this->createMock(Log::class),
        );

        $objectDataCacheAccess = new DataCacheAccess(
            dataCache: $this->createMock(DataCache::class),
            systemConfig: $this->createMock(SystemConfig::class),
            log: $this->createMock(Log::class),
        );

        $this->metadata = new Metadata(
            fileManager: $this->fileManager,
            module: $module,
            builder: $builder,
            builderHelper: $builderHelper,
            data: $dataCacheAccess,
            objectData: $objectDataCacheAccess,
        );

        $this->reflection = new ReflectionHelper($this->metadata);

        $this->customPath = 'tests/unit/testData/cache/metadata/custom';

        $this->reflection->setProperty('customPath', $this->customPath);
    }

    protected function tearDown() : void
    {
        $this->metadata->clearChanges();
        $this->metadata = null;
    }

    public function testGet(): void
    {
        $this->assertEquals('System', $this->metadata->get('app.adminPanel.system.label'));

        $this->assertArrayHasKey('fields', $this->metadata->get('entityDefs.User'));
    }

    public function testSet(): void
    {
        $data = [
          'fields' =>
          [
            'name' =>
            [
              'required' => false,
              'maxLength' => 150,
              'view' => 'Views.Test.Custom',
            ],
          ],
        ];

        $this->metadata->set('entityDefs', 'Attachment', $data);

        $this->assertEquals('Views.Test.Custom', $this->metadata->get('entityDefs.Attachment.fields.name.view'));
        $this->assertEquals(150, $this->metadata->get('entityDefs.Attachment.fields.name.maxLength'));

        $result = [
            'entityDefs' => [
                'Attachment' => $data
            ],
        ];
        $this->assertEquals($result, $this->reflection->getProperty('changedData'));

        $data = [
          'fields' =>
          [
            'name' =>
            [
              'maxLength' => 200,
            ],
          ],
        ];

        $this->metadata->set('entityDefs', 'Attachment', $data);
        $this->assertEquals(200, $this->metadata->get('entityDefs.Attachment.fields.name.maxLength'));
        $this->assertEquals('Views.Test.Custom', $this->metadata->get('entityDefs.Attachment.fields.name.view'));

        $result = [
            'entityDefs' => [
                'Attachment' => [
                  'fields' =>
                  [
                    'name' =>
                    [
                      'required' => false,
                      'maxLength' => 200,
                      'view' => 'Views.Test.Custom',
                    ],
                  ],
                ],
            ],
        ];
        $this->assertEquals($result, $this->reflection->getProperty('changedData'));

        $this->metadata->clearChanges();

        $this->assertEquals([], $this->reflection->getProperty('changedData'));
        $this->assertEquals(255, $this->metadata->get('entityDefs.Attachment.fields.name.maxLength'));
    }

    public function testDelete(): void
    {
        $this->metadata->delete('entityDefs', 'Attachment', [
            'fields.name.type',
        ]);

        $this->assertNull($this->metadata->get('entityDefs.Attachment.fields.name.type'));

        $this->assertEquals([
            'entityDefs' => [
                'Attachment' => [
                    'fields.name.type',
                ],
            ],
        ], $this->reflection->getProperty('deletedData'));

        $this->metadata->delete('entityDefs', 'Attachment', [
            'fields.name.required',
        ]);

        $this->assertNull($this->metadata->get('entityDefs.Attachment.fields.name.required'));

        $this->assertEquals([
            'entityDefs' => [
                'Attachment' => [
                    'fields.name.type',
                    'fields.name.required',
                ],
            ],
        ], $this->reflection->getProperty('deletedData'));

        $this->metadata->init();

        $this->assertNotNull($this->metadata->get('entityDefs.Attachment.fields.name.type'));
        $this->assertNotNull($this->metadata->get('entityDefs.Attachment.fields.name.required'));

        $this->metadata->clearChanges();
        $this->assertEquals([], $this->reflection->getProperty('deletedData'));
    }

    public function testUndelete(): void
    {
        $this->metadata->delete('entityDefs', 'Attachment', [
            'fields.name.type',
            'fields.name.required',
        ]);

        $this->assertNull($this->metadata->get('entityDefs.Attachment.fields.name.type'));


        $this->metadata->set('entityDefs', 'Attachment', [
            'fields' => [
                'name' => [
                    'type' => 'enum',
                ],
            ],
        ]);

        $this->assertEquals('enum', $this->metadata->get('entityDefs.Attachment.fields.name.type'));

        $this->assertEquals([
            'entityDefs' => [
                'Attachment' => [
                    1 => 'fields.name.required',
                ],
            ],
        ], $this->reflection->getProperty('deletedData'));

        $this->metadata->set('entityDefs', 'Attachment', [
            'fields' => [
                'name' => [
                    'required' => true,
                ],
            ],
        ]);

        $this->assertEquals(true, $this->metadata->get('entityDefs.Attachment.fields.name.required'));

        $this->assertEquals([
            'entityDefs' => [
                'Attachment' => [],
            ],
        ], $this->reflection->getProperty('deletedData'));
    }

    public function testGetCustom(): void
    {
        $this->assertNull($this->metadata->getCustom('entityDefs', 'Lead'));

        $customData = $this->metadata->getCustom('entityDefs', 'Lead', (object) []);

        $this->assertTrue(is_object($customData));

        $data = (object) [
          'fields' => (object) [
            'status' => (object) [
              "type" => "enum",
              "options" => ["__APPEND__", "Test1", "Test2"],
            ],
          ],
        ];

        $this->metadata->saveCustom('entityDefs', 'Lead', $data);

        $this->assertEquals($data, $this->metadata->getCustom('entityDefs', 'Lead'));

        unlink($this->customPath . '/entityDefs/Lead.json');
    }

    public function testSaveCustom1(): void
    {
        $data = (object) [
          'fields' => (object) [
            'status' => (object) [
              "type" => "enum",
              "options" => ["__APPEND__", "Test1", "Test2"],
            ],
          ],
        ];

        $this->metadata->saveCustom('entityDefs', 'Lead', $data);

        $savedFile = $this->customPath . '/entityDefs/Lead.json';
        $fileContent = $this->fileManager->getContents($savedFile);

        $savedData = Json::decode($fileContent);

        $this->assertEquals($data, $savedData);

        unlink($savedFile);
    }

    public function testSaveCustom2(): void
    {
        $initData = (object) [
          'fields' => (object) [
            'status' => (object) [
              "type" => "enum",
              "options" => ["__APPEND__", "Test1", "Test2"],
            ],
          ],
        ];

        $this->metadata->saveCustom('entityDefs', 'Lead', $initData);

        $customData = $this->metadata->getCustom('entityDefs', 'Lead');

        unset($customData->fields->status->type);
        $customData->fields->status->options = ["__APPEND__", "Test1"];
        $this->metadata->saveCustom('entityDefs', 'Lead', $customData);

        $savedFile = $this->customPath . '/entityDefs/Lead.json';

        $fileContent = $this->fileManager->getContents($savedFile);

        $savedData = Json::decode($fileContent);

        $expectedData = (object) [
          'fields' => (object) [
            'status' => (object) [
              "options" => ["__APPEND__", "Test1"],
            ],
          ],
        ];

        $this->assertEquals($expectedData, $savedData);

        unlink($savedFile);
    }

    public function testGetObjects(): void
    {
        $this->assertEquals('System', $this->metadata->getObjects('app.adminPanel.system.label'));
        $this->assertObjectHasProperty('fields', $this->metadata->getObjects('entityDefs.User'));
        $this->assertObjectHasProperty('type', $this->metadata->getObjects('entityDefs.User.fields.name'));
    }
}
