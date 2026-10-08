<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\AclManager;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\StreamAgentProgress;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Support/EntityDouble.php';

class StreamAgentProgressTest extends TestCase
{
    public function testAcknowledgementUsesTheAiIdentityAndPreservesTheThread(): void
    {
        $defs = ['attributes' => ['data' => ['type' => 'jsonObject'], 'isInternal' => ['type' => 'bool']]];
        foreach (['id', 'type', 'post', 'parentType', 'parentId', 'createdById', 'opportunityThreadRootId',
            'opportunityChatwootAccountId', 'opportunityStreamEventKey'] as $name) {
            $defs['attributes'][$name] = ['type' => 'varchar'];
        }
        $source = new Note('Note', $defs);
        $source->set(['id' => 'source', 'parentType' => 'Task', 'parentId' => 'task',
            'createdById' => 'human', 'opportunityThreadRootId' => 'thread', 'opportunityChatwootAccountId' => '6']);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('ai-user');
        $user->method('isActive')->willReturn(true);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type) => match ($type) {
            'ChatwootAccountUserMembership' => new EntityDouble(['chatwootUserId' => 'cw']),
            'ChatwootUser' => new EntityDouble(['assignedUserId' => 'ai-user']),
            'User' => $user,
        });
        $em->method('getNewEntity')->with('Note')->willReturnCallback(fn () => new Note('Note', $defs));
        $saved = [];
        $em->method('saveEntity')->willReturnCallback(function (Note $reply) use (&$saved): void {
            $reply->set('id', 'reply-' . count($saved));
            $saved[] = $reply;
        });
        $reactions = [];
        $jobs = [];
        $repo = $this->createMock(RDBRepository::class);
        $select = $this->createMock(RDBSelectBuilder::class);
        $repo->method('where')->willReturn($select);
        $select->method('findOne')->willReturnCallback(function () use (&$reactions) {
            return $reactions ? new EntityDouble([]) : null;
        });
        $em->method('getRDBRepository')->with('UserReaction')->willReturn($repo);
        $em->method('createEntity')->willReturnCallback(function ($type, $data) use (&$reactions, &$jobs) {
            if ($type === 'UserReaction') $reactions[] = $data;
            else $jobs[] = $data;
            return new EntityDouble([]);
        });
        $acl = $this->createMock(AclManager::class);
        $acl->method('check')->with($user, $this->isInstanceOf(Note::class), 'create')->willReturn(true);
        $service = new StreamAgentProgress($em, $acl);
        $service->queue($source, (object) ['aiAgentMembershipId' => 'ai']);
        self::assertSame('ai-user', $saved[0]->getCreatedById());
        self::assertSame('Task', $saved[0]->getParentType());
        self::assertSame('task', $saved[0]->getParentId());
        self::assertSame('thread', $saved[0]->get('opportunityThreadRootId'));
        self::assertTrue($saved[0]->isInternal());
        self::assertTrue(StreamAgentProgress::isPending($saved[0]));
        self::assertSame([['parentType' => 'Note', 'parentId' => 'source', 'userId' => 'ai-user', 'type' => '👀']], $reactions);
        self::assertSame('reply-0', $jobs[0]['data']->noteId);
        self::assertNull($jobs[0]['data']->workflowRunId);
        // Different AI profiles sharing a CRM user still produce just one eyes reaction.
        $service->queue($source, (object) ['aiAgentMembershipId' => 'other-ai']);
        self::assertCount(2, $saved);
        self::assertCount(1, $reactions);
    }
}
