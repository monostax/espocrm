<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Classes\FieldProcessing\Common;

use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\FieldProcessing\Common\GlobalSharingTeamsFilterLoader;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Globally shared records (Report, SidenavConfig, OAuthProvider) show a user
 * only their own teams. The filter must run on output, never as a read
 * loader: Record\Service::update() runs read loaders on the entity it saves,
 * and a filtered `teamsIds` there unlinks every team the editor is not in.
 */
class GlobalSharingTeamsFilterLoaderTest extends TestCase
{
    private const CLASS_NAME = GlobalSharingTeamsFilterLoader::class;

    /** @var array<string, mixed> */
    private array $values = [];

    private function sharedReport(): Entity
    {
        $this->values = [
            'isGloballyShared' => true,
            'teamsIds' => ['team-nowle', 'team-ifv'],
            'teamsNames' => (object) ['team-nowle' => 'Nowle', 'team-ifv' => 'Instituto Ferraz Veloso'],
        ];

        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Report');
        $entity->method('get')->willReturnCallback(fn (string $name) => $this->values[$name] ?? null);
        $entity->method('set')->willReturnCallback(function ($name, $value = null) use ($entity) {
            $this->values[$name] = $value;

            return $entity;
        });

        return $entity;
    }

    private function filter(): GlobalSharingTeamsFilterLoader
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('getTeamIdList')->willReturn(['team-nowle']);

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(fn (array $path) => match ($path[3] ?? null) {
            'isGloballyShared' => ['type' => 'bool'],
            'teams' => ['type' => 'hasMany'],
            default => null,
        });

        return new GlobalSharingTeamsFilterLoader($user, $metadata);
    }

    public function testOutputShowsOnlyTheViewersTeams(): void
    {
        $this->filter()->filter($this->sharedReport());

        $this->assertSame(['team-nowle'], $this->values['teamsIds']);
        $this->assertEquals((object) ['team-nowle' => 'Nowle'], $this->values['teamsNames']);
    }

    public function testListLoadingFiltersTheSameWay(): void
    {
        $this->filter()->process($this->sharedReport(), Params::create());

        $this->assertSame(['team-nowle'], $this->values['teamsIds']);
    }

    public function testRegisteredOnOutputAndListsButNeverAsReadLoader(): void
    {
        $root = dirname(__DIR__, 8) . '/custom/Espo/Modules';
        $readLoaders = [];

        foreach (glob($root . '/*/Resources/metadata/app/fieldProcessing.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true) ?? [];
            array_push($readLoaders, ...($data['readLoaderClassNameList'] ?? []));
        }

        foreach (glob($root . '/*/Resources/metadata/recordDefs/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true) ?? [];
            array_push($readLoaders, ...($data['readLoaderClassNameList'] ?? []));
        }

        $fieldProcessing = json_decode((string) file_get_contents($root . '/Global/Resources/metadata/app/fieldProcessing.json'), true);
        $record = json_decode((string) file_get_contents($root . '/Global/Resources/metadata/app/record.json'), true);

        $this->assertNotContains(self::CLASS_NAME, $readLoaders);
        $this->assertContains(self::CLASS_NAME, $fieldProcessing['listLoaderClassNameList']);
        $this->assertContains(self::CLASS_NAME, $record['outputFilterClassNameList']);
    }
}
