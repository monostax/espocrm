<?php

namespace tests\unit\Espo\Modules\Global\Rebuild;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\ORM\Helper;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Global\Hooks\User\EmailIdentity;
use Espo\Modules\Global\Rebuild\BackfillUserEmailIdentity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class BackfillUserEmailIdentityTest extends TestCase
{
    public function testMigratesOnceAndReportsConflictsWithoutChangingAccess(): void
    {
        $em = $this->createMock(EntityManager::class);
        $make = function (string $id, string $email, string $username) use ($em) {
            $user = new User('User', ['attributes' => array_fill_keys([
                'id', 'type', 'userName', 'emailAddress', 'password', 'isActive',
            ], ['type' => 'varchar'])], $em, $this->createMock(Helper::class));
            $user->set(['id' => $id, 'type' => 'regular', 'emailAddress' => $email,
                'userName' => $username, 'password' => 'unchanged-hash', 'isActive' => false]);
            $user->setAsFetched();
            return $user;
        };
        $valid = $make('valid', 'valid@example.com', 'legacy');
        $conflict = $make('conflict', 'duplicate@example.com', 'legacy2');
        $empty = $make('no-email', '', 'bootstrap');
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('find')->willReturn(new EntityCollection([$valid, $conflict, $empty]));
        $repo = $this->createMock(RDBRepository::class);
        $repo->method('where')->with(['type!=' => ['api', 'system']])->willReturn($select);
        $em->method('getRDBRepository')->with('User')->willReturn($repo);
        $identity = $this->createMock(EmailIdentity::class);
        $identity->method('process')->willReturnCallback(function ($user) use ($conflict) {
            if ($user === $conflict) {
                throw new Conflict('userNameExists');
            }
            $user->set('userName', $user->get('emailAddress'));
        });
        $em->expects($this->once())->method('saveEntity')->with($valid, [
            'silent' => true, 'skipChatwootProvisioning' => true,
        ]);
        $log = $this->createMock(Log::class);
        $log->expects($this->exactly(2))->method('warning')->with($this->stringContains('conflict'));
        $action = new BackfillUserEmailIdentity($em, $identity, $log);
        $action->process();
        $action->process();
        $this->assertSame('valid@example.com', $valid->get('userName'));
        $this->assertSame('unchanged-hash', $valid->get('password'));
        $this->assertFalse($valid->isActive());
        $this->assertSame('legacy2', $conflict->get('userName'));
    }
}
