<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Stream;

use Espo\Core\AclManager;
use Espo\Core\Utils\Acl\UserAclManagerProvider;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Stream\TenantNoteAccessControl;
use Espo\Modules\Global\Tools\Tenant\TenantVisibility;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * User.teams is audited: every workspace a staff member joins is written into
 * an Update note on their User record with all team names, before and after.
 */
class TenantNoteAccessControlTest extends TestCase
{
    private stdClass $data;

    private function control(array $hidden): TenantNoteAccessControl
    {
        $aclManager = $this->createMock(AclManager::class);
        $aclManager->method('getScopeForbiddenFieldList')->willReturn([]);
        $aclManager->method('getScopeForbiddenAttributeList')->willReturn([]);

        $provider = $this->createMock(UserAclManagerProvider::class);
        $provider->method('get')->willReturn($aclManager);

        $visibility = $this->createMock(TenantVisibility::class);
        $visibility->method('teamsHiddenFrom')->willReturn($hidden);

        return new TenantNoteAccessControl($provider, $this->createMock(Metadata::class), $visibility);
    }

    private function note(string $parentType, array $fields): Note
    {
        $this->data = (object) [
            'fields' => $fields,
            'attributes' => (object) [
                'was' => (object) [
                    'teamsIds' => ['team-nowle'],
                    'teamsNames' => (object) ['team-nowle' => 'Nowle'],
                    'isActive' => false,
                ],
                'became' => (object) [
                    'teamsIds' => ['team-nowle', 'team-ifv'],
                    'teamsNames' => (object) ['team-nowle' => 'Nowle', 'team-ifv' => 'Instituto Ferraz Veloso'],
                    'isActive' => true,
                ],
            ],
        ];

        $note = $this->createMock(Note::class);
        $note->method('getType')->willReturn(Note::TYPE_UPDATE);
        $note->method('getParentType')->willReturn($parentType);
        $note->method('getParentId')->willReturn('staff');
        $note->method('getData')->willReturnCallback(fn () => $this->data);
        $note->method('setData')->willReturnCallback(function ($data) use ($note) {
            $this->data = $data;

            return $note;
        });

        return $note;
    }

    public function testTeamChangesOfUncoveredUserAreRemoved(): void
    {
        $note = $this->note('User', ['teams', 'isActive']);

        $this->control(['team-nowle', 'team-ifv'])->apply($note, $this->createMock(User::class));

        $this->assertSame(['isActive'], $this->data->fields);
        $this->assertObjectNotHasProperty('teamsNames', $this->data->attributes->became);
        $this->assertObjectNotHasProperty('teamsIds', $this->data->attributes->was);
        $this->assertTrue($this->data->attributes->became->isActive);
    }

    public function testCoveredUserNoteIsUnchanged(): void
    {
        $note = $this->note('User', ['teams']);

        $this->control([])->apply($note, $this->createMock(User::class));

        $this->assertSame(['teams'], $this->data->fields);
        $this->assertSame('Instituto Ferraz Veloso', $this->data->attributes->became->teamsNames->{'team-ifv'});
    }

    public function testNotesOnOtherEntitiesAreNotTouched(): void
    {
        $note = $this->note('Opportunity', ['teams']);

        $this->control(['team-ifv'])->apply($note, $this->createMock(User::class));

        $this->assertSame(['teams'], $this->data->fields);
    }
}
