<?php

namespace tests\unit\Espo\Modules\Global\Hooks\Funnel;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Entity;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Hooks\Funnel\ValidateIcon;
use Espo\Modules\Global\Tools\RecordIcon\Validator;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

class ValidateIconTest extends TestCase
{
    private function entity(?object $icon): Entity
    {
        $entity = new Entity('Funnel', ['attributes' => [
            'id' => ['type' => 'varchar'],
            'name' => ['type' => 'varchar'],
            'icon' => ['type' => 'jsonObject'],
        ]]);
        $entity->set(['name' => 'Sales', 'icon' => $icon]);
        return $entity;
    }

    private function hook(): ValidateIcon
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(static fn ($key, $default = null) =>
            $key === 'app.clientIcons.classList' ? ['ti ti-rocket'] : $default);
        return new ValidateIcon(new Validator($metadata));
    }

    public function testCreateReplaceAndRemoveWithRealOrmJsonAttributes(): void
    {
        $hook = $this->hook();
        $options = SaveOptions::fromAssoc([]);
        $entity = $this->entity((object) ['type' => 'emoji', 'value' => '🚀']);
        $hook->beforeSave($entity, $options);
        $this->assertSame('🚀', $entity->get('icon')->value);
        $entity->setAsFetched();
        $entity->set('icon', (object) ['type' => 'icon', 'value' => 'ti ti-rocket']);
        $hook->beforeSave($entity, $options);
        $this->assertSame('ti ti-rocket', $entity->get('icon')->value);
        $entity->setAsFetched();
        $entity->set('icon', null);
        $hook->beforeSave($entity, $options);
        $this->assertNull($entity->get('icon'));
    }

    public function testRejectsInvalidIconOnCreation(): void
    {
        $this->expectException(BadRequest::class);
        $this->hook()->beforeSave($this->entity((object) ['type' => 'emoji', 'value' => 'invalid']),
            SaveOptions::fromAssoc([]));
    }

    public function testUnrelatedUpdateDoesNotRevalidateAnUnchangedLegacyValue(): void
    {
        $entity = $this->entity((object) ['type' => 'icon', 'value' => 'old icon']);
        $entity->setAsFetched();
        $entity->set('name', 'Renamed');
        $this->hook()->beforeSave($entity, SaveOptions::fromAssoc([]));
        $this->assertSame('Renamed', $entity->get('name'));
    }
}
