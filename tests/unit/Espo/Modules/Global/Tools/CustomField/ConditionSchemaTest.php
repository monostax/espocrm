<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\CustomField;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Tools\CustomField\ConditionSchema;
use PHPUnit\Framework\TestCase;

class ConditionSchemaTest extends TestCase
{
    private function schema(): ConditionSchema
    {
        $root = dirname(__DIR__, 7);
        $defs = [];
        foreach (['Account', 'Contact', 'Lead', 'Opportunity', 'Funnel', 'OpportunityStage'] as $scope) {
            foreach (['application/Espo/Modules/Crm', 'custom/Espo/Modules/Global'] as $module) {
                $file = "$root/$module/Resources/metadata/entityDefs/$scope.json";
                if (is_file($file)) {
                    $defs[$scope] = array_replace_recursive($defs[$scope] ?? [], json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
                }
            }
        }
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(function ($path) use ($defs) {
            $value = ['entityDefs' => $defs];
            foreach ($path as $key) $value = $value[$key] ?? null;
            return $value;
        });
        return new ConditionSchema($metadata);
    }

    public function testRealAccountAndContactMetadataProducesUsefulTypedChoices(): void
    {
        $schema = $this->schema();
        $account = $schema->getAttributes('Account');
        $contact = $schema->getAttributes('Contact');
        $this->assertContains('Customer', $account['type']['options']);
        $this->assertContains('Healthcare', $account['industry']['options']);
        $this->assertSame(['isEmpty', 'isFilled'], $account['website']['operators']);
        $this->assertContains('Campaign', $contact['source']['options']);
        $this->assertTrue($contact['tags']['allowCustomOptions']);
        $this->assertContains('containsAll', $contact['tags']['operators']);
        $this->assertSame('Account', $contact['accountId']['entity']);
        $this->assertTrue($contact['accountId']['tenantScoped']);
        $this->assertContains('isFalse', $contact['doNotCall']['operators']);
        $this->assertArrayNotHasKey('customFields', $contact);
        $this->assertArrayNotHasKey('accountsIds', $contact);
        $this->assertArrayNotHasKey('accountRole', $contact);
        $this->assertArrayNotHasKey('account.industry', $contact);
        $this->assertSame($account['industry']['options'], $schema->getAttributes('Lead')['industry']['options']);
        $this->assertSame('Funnel', $schema->getAttributes('Opportunity')['funnelId']['entity']);
    }

    public function testSchemaRejectsUnknownAttributesWrongOperatorsAndUnknownEnumOptions(): void
    {
        $schema = $this->schema();
        $attributes = $schema->getAttributes('Account');
        foreach ([
            ['attribute' => 'unknown', 'operator' => 'isFilled'],
            ['attribute' => 'type', 'operator' => 'containsAny', 'value' => ['Customer']],
            ['attribute' => 'type', 'operator' => 'equals', 'value' => 'not-a-type'],
            ['attribute' => 'website', 'operator' => 'equals', 'value' => 'example.org'],
        ] as $leaf) {
            try {
                $schema->validateLeaf($leaf, $attributes);
                $this->fail('Invalid metadata condition was accepted.');
            } catch (BadRequest) {
                $this->addToAssertionCount(1);
            }
        }
        $tags = $schema->validateLeaf(['attribute' => 'tags', 'operator' => 'containsAny', 'value' => ['custom-tag']],
            $schema->getAttributes('Contact'));
        $this->assertSame('multiEnum', $tags['type']);
    }
}
