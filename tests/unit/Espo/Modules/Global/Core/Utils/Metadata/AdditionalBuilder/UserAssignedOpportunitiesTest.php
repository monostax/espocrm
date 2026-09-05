<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Core\Utils\Metadata\AdditionalBuilder;

use Espo\Core\Utils\Json;
use Espo\Core\Utils\Metadata\Builder;
use Espo\Core\Utils\Resource\Reader;
use Espo\Modules\Global\Core\Utils\Metadata\AdditionalBuilder\UserAssignedOpportunities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserAssignedOpportunitiesTest extends TestCase
{
    public function testBuilderIsRegistered(): void
    {
        $metadata = Json::decode(file_get_contents(
            'custom/Espo/Modules/Global/Resources/metadata/app/metadata.json'
        ));

        $this->assertContains(UserAssignedOpportunities::class, $metadata->additionalBuilderClassNameList);
    }

    public static function linkStates(): array
    {
        return [
            'missing on clean installs' => [null],
            'disabled by persisted Custom metadata' => [true],
            'already enabled' => [false],
        ];
    }

    #[DataProvider('linkStates')]
    public function testEnablesReverseLinkAfterMetadataMerge(?bool $disabled): void
    {
        $data = Json::decode('{
            "entityDefs": {
                "User": {
                    "links": {
                        "unrelated": {"disabled": true}
                    }
                }
            }
        }');
        $data->app = (object) [
            'metadata' => (object) [
                'additionalBuilderClassNameList' => [UserAssignedOpportunities::class],
            ],
        ];

        if ($disabled !== null) {
            $data->entityDefs->User->links->assignedOpportunities = (object) [
                'type' => 'hasMany',
                'entity' => 'Opportunity',
                'foreign' => 'assignedUser',
                'disabled' => $disabled,
                'readOnly' => true,
            ];
        }

        $reader = $this->createMock(Reader::class);
        $reader->method('read')->with('metadata')->willReturn($data);

        $builder = new Builder($reader);
        $built = $builder->build();
        $link = $built->entityDefs->User->links->assignedOpportunities;

        $this->assertSame('hasMany', $link->type);
        $this->assertSame('Opportunity', $link->entity);
        $this->assertSame('assignedUser', $link->foreign);
        $this->assertFalse($link->disabled);
        $this->assertTrue($link->utility);
        $this->assertTrue($built->entityDefs->User->links->unrelated->disabled);

        if ($disabled !== null) {
            $this->assertTrue($link->readOnly);
        }

        $snapshot = Json::encode($built);
        $this->assertSame($snapshot, Json::encode($builder->build()));
    }
}
