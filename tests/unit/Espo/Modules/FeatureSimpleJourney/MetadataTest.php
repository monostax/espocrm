<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSimpleJourney;

use Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourneyRecord\ValidateProgress;
use Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourneyRecordParent\ValidateParent;

class MetadataTest extends TestCase
{
    private function json(string $path): array
    {
        $root = dirname(__DIR__, 5) . '/custom/Espo/Modules/FeatureSimpleJourney/Resources/';
        return json_decode(file_get_contents($root . $path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEveryEntityHasTenantLinkAndTeamAcl(): void
    {
        foreach (['SimpleJourney', 'SimpleJourneyStage', 'SimpleJourneyRecord', 'SimpleJourneyRecordParent'] as $type) {
            $defs = $this->json("metadata/entityDefs/$type.json");
            $this->assertSame('link', $defs['fields']['tenant']['type']);
            $this->assertSame('Tenant', $defs['links']['tenant']['entity']);
            $this->assertSame('team', $this->json("metadata/scopes/$type.json")['acl']);
            $this->assertArrayHasKey('mandatory', $this->json("metadata/selectDefs/$type.json")['accessControlFilterClassNameMap']);
            foreach ($this->json("metadata/aclDefs/$type.json") as $class) {
                $this->assertTrue(class_exists($class), $class);
            }
        }
    }

    public function testStatusesAndPortugueseCopy(): void
    {
        $defs = $this->json('metadata/entityDefs/SimpleJourneyRecord.json');
        $this->assertSame(ValidateProgress::STATUSES, $defs['fields']['status']['options']);
        $this->assertTrue($defs['fields']['stage']['audited']);
        $this->assertTrue($defs['fields']['status']['audited']);
        $this->assertSame([
            'On Hold' => 'Pausado', 'To Do' => 'Pendente', 'Doing' => 'Em Andamento', 'Done' => 'Concluído',
        ], $this->json('i18n/pt_BR/SimpleJourneyRecord.json')['options']['status']);
    }

    public function testBothAdminPanelsHaveTranslatedEntries(): void
    {
        foreach (['adminPanel' => 'Admin', 'adminForUserPanel' => 'Configurations'] as $panel => $scope) {
            $items = $this->json("metadata/app/$panel.json")['simpleJourneys']['itemList'];
            $this->assertSame(['#SimpleJourney', '#SimpleJourneyRecord'], array_column($items, 'url'));
            foreach (['en_US', 'pt_BR'] as $locale) {
                $copy = $this->json("i18n/$locale/$scope.json");
                foreach ($items as $item) {
                    $this->assertNotEmpty($copy['labels'][$item['label']]);
                    $this->assertNotEmpty($copy['descriptions'][$item['description']]);
                }
            }
        }
    }

    public function testFrontendUsesExistingTenantPrefillAndServerDefaultsRunBeforeValidation(): void
    {
        $this->assertSame('global:handlers/tenant/defaults-preparator',
            $this->json('metadata/clientDefs/SimpleJourney.json')['modelDefaultsPreparator']);
        foreach (['SimpleJourney', 'SimpleJourneyStage', 'SimpleJourneyRecord', 'SimpleJourneyRecordParent'] as $type) {
            foreach ($this->json("metadata/recordDefs/$type.json")['earlyBeforeCreateHookClassNameList'] as $class) {
                $this->assertTrue(class_exists($class));
            }
        }
    }

    public function testValidationMessagesHaveMatchingPortugueseTranslations(): void
    {
        $english = $this->json('i18n/en_US/SimpleJourney.json')['messages'];
        $portuguese = $this->json('i18n/pt_BR/SimpleJourney.json')['messages'];
        $this->assertSame(array_keys($english), array_keys($portuguese));
        foreach ($portuguese as $message) {
            $this->assertNotEmpty($message);
        }
    }

    public function testManyPolymorphicParentsUseLinkRowsNotSingularTarget(): void
    {
        $record = $this->json('metadata/entityDefs/SimpleJourneyRecord.json');
        $parent = $this->json('metadata/entityDefs/SimpleJourneyRecordParent.json');
        $this->assertArrayNotHasKey('target', $record['fields']);
        $this->assertSame('hasMany', $record['links']['parents']['type']);
        $this->assertSame('SimpleJourneyRecordParent', $record['links']['parents']['entity']);
        $this->assertSame(ValidateParent::PARENT_TYPES, $parent['fields']['parent']['entityList']);
        $this->assertSame('belongsToParent', $parent['links']['parent']['type']);
        $this->assertSame(['recordId', 'parentType', 'parentId', 'deleteId'], $parent['indexes']['uniqueParent']['columns']);
        $this->assertSame(['parents'], $record['cascadeDelete']['links']);
    }
}
