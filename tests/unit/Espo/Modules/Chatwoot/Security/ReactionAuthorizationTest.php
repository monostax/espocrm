<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Security;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\EntityProvider;
use Espo\Entities\Note;
use Espo\ORM\Repository\RDBRepository;
use Espo\Tools\Stream\Api\DeleteMyReactions;
use Espo\Tools\Stream\Api\PostMyReactions;
use Espo\Tools\Stream\MyReactionsService;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/SecurityTestCase.php';

class ReactionAuthorizationTest extends SecurityTestCase
{
    public static function handlers(): iterable
    {
        foreach ([PostMyReactions::class => 'react', DeleteMyReactions::class => 'unReact'] as $handler => $mutation) {
            foreach ([false, true] as $portal) {
                foreach (['foreign', 'revoked', 'no-read', 'no-stream', 'allowed', 'global-admin'] as $case) {
                    if ($portal && $case === 'global-admin') {
                        continue;
                    }
                    yield "$mutation " . ($portal ? 'portal ' : 'internal ') . $case => [$handler, $mutation, $portal, $case];
                }
            }
        }
    }

    #[DataProvider('handlers')]
    public function testRealEntityProviderAuthorizesBeforeReactionMutation(string $handler, string $mutation, bool $portal, string $case): void
    {
        $this->portal = $portal;
        $note = $this->note(['parentId' => in_array($case, ['foreign', 'global-admin'], true) ? 'opp-b' : 'opp-a']);
        if ($case === 'revoked') {
            self::assertTrue($this->parents->canReadNote($this->user, $note));
            $this->explicitTenants = [];
        }
        $this->readAllowed = $case !== 'no-read';
        $this->streamAllowed = $case !== 'no-stream';
        $this->admin = $case === 'global-admin';
        $repository = $this->createMock(RDBRepository::class);
        $repository->expects($this->once())->method('getById')->with('note')->willReturn($note);
        $this->em->expects($this->once())->method('getRDBRepositoryByClass')->with(Note::class)->willReturn($repository);
        $this->em->expects($this->never())->method('saveEntity');
        $provider = new EntityProvider($this->em, new Acl($this->acl, $this->user));
        $service = $this->createMock(MyReactionsService::class);
        $allowed = in_array($case, ['allowed', 'global-admin'], true);
        $service->expects($allowed ? $this->once() : $this->never())->method($mutation)->with($note, 'Like');
        $service->expects($this->never())->method($mutation === 'react' ? 'unReact' : 'react');
        $request = $this->createMock(Request::class);
        $request->method('getRouteParam')->willReturnMap([['id', 'note'], ['type', 'Like']]);
        if (!$allowed) {
            $this->expectException(Forbidden::class);
        }
        (new $handler($provider, $service))->process($request);
    }
}
