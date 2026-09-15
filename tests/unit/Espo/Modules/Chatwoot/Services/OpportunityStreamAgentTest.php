<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Hooks\Note\KeepOpportunityEventsInternal;
use Espo\Modules\Chatwoot\Services\OpportunityStreamAgent;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Select;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use Espo\Tools\Stream\NoteUtil;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Support/EntityDouble.php';

class OpportunityStreamAgentTest extends TestCase
{
    private OpportunityStreamAgent $service;
    private Note $source;
    private string $postHash;
    private array $records;
    private array $replies = [];
    private bool $canRead = true;
    private bool $canCreate = true;
    private bool $locked = false;

    private function note(array $values = []): Note
    {
        $defs = ['attributes' => []];
        foreach (['id', 'type', 'post', 'parentType', 'parentId', 'createdById', 'createdByName', 'createdAt',
            'opportunityChatwootAccountId', 'opportunityStreamEventKey', 'number'] as $field) {
            $defs['attributes'][$field] = ['type' => 'varchar'];
        }
        $defs['attributes']['data'] = ['type' => 'jsonObject'];
        $defs['attributes']['isInternal'] = ['type' => 'bool'];
        $defs['attributes']['deleted'] = ['type' => 'bool'];
        $note = new Note('Note', $defs);
        $note->set($values);
        return $note;
    }

    protected function setUp(): void
    {
        $this->source = $this->note([
            'id' => 'source', 'type' => 'Post', 'parentType' => 'Opportunity', 'parentId' => 'opp',
            'post' => '[@Assistant](mention://user/7/Assistant) Summarize this deal',
            'number' => '10', 'createdById' => 'human', 'createdByName' => 'Human', 'createdAt' => '2026-09-15 12:00:00',
            'opportunityChatwootAccountId' => 1,
            'data' => (object) ['opportunityAiMentionTargets' => [(object) [
                'aiAgentMembershipId' => 'ai', 'chatwootAccountCrmId' => 'account', 'crmTenantId' => 'tenant',
            ]]],
        ]);
        $this->postHash = hash('sha256', $this->source->getPost());
        $this->records = [
            'Note/source' => $this->source,
            'ChatwootAccountUserMembership/ai' => new EntityDouble([
                'id' => 'ai', 'isAI' => true, 'chatwootUserId' => 'cw-user', 'chatwootAccountId' => 'account',
            ]),
            'ChatwootUser/cw-user' => new EntityDouble(['assignedUserId' => 'ai-user', 'platformId' => 'platform']),
            'ChatwootAccount/account' => new EntityDouble(['tenantId' => 'tenant', 'platformId' => 'platform']),
            'Opportunity/opp' => new EntityDouble(['tenantId' => 'tenant']),
        ];
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $this->records["$type/$id"] ?? null);
        $em->method('getNewEntity')->with('Note')->willReturnCallback(fn () => $this->note());
        $em->method('saveEntity')->willReturnCallback(function (Note $note): void {
            self::assertTrue($this->locked, 'Publication must hold the source row lock.');
            (new KeepOpportunityEventsInternal())->beforeSave($note, []);
            $note->set('id', 'reply-' . count($this->replies));
            $this->replies[$note->get('opportunityStreamEventKey')] = $note;
        });
        $transaction = $this->createMock(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(function ($fn) {
            try {
                return $fn();
            } finally {
                $this->locked = false;
            }
        });
        $em->method('getTransactionManager')->willReturn($transaction);
        $repo = $this->createMock(RDBRepository::class);
        $select = $this->createMock(RDBSelectBuilder::class);
        $repo->method('where')->with(['id' => 'source'])->willReturn($select);
        $select->method('forUpdate')->willReturnCallback(function () use ($select) {
            $this->locked = true;
            return $select;
        });
        $select->method('findOne')->willReturnCallback(fn () => $this->records['Note/source'] ?? null);
        $repo->method('clone')->willReturnCallback(function (Select $query) {
            self::assertTrue($query->getRaw()['withDeleted'], 'Deleted replies must also deduplicate.');
            $key = $query->getWhere()->getRaw()['opportunityStreamEventKey'];
            $select = $this->createMock(RDBSelectBuilder::class);
            $select->method('findOne')->willReturn($this->replies[$key] ?? null);
            return $select;
        });
        $em->method('getRDBRepository')->with('Note')->willReturn($repo);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('ai-user');
        $user->method('isActive')->willReturn(true);
        $access = $this->createMock(OpportunityAccess::class);
        $access->method('canReadNote')->willReturnCallback(fn () => $this->canRead);
        $acl = $this->createMock(Acl::class);
        $acl->method('check')->willReturnCallback(fn ($entity, $action) => $action === 'create' ? $this->canCreate : $this->canRead);
        $this->service = new OpportunityStreamAgent($em, $user, $acl, $access, $this->createMock(NoteUtil::class));
    }

    public function testLostResponseAndDeletedReplyDoNotCreateAnotherPost(): void
    {
        self::assertTrue($this->service->context('source', 'ai', $this->postHash)->shouldRespond);
        $first = $this->service->reply('source', 'ai', $this->postHash, 'A summary', 'run-1');
        $retry = $this->service->reply('source', 'ai', $this->postHash, 'A second summary', 'run-2');
        self::assertTrue($first->published);
        self::assertFalse($retry->published);
        self::assertSame($first->noteId, $retry->noteId);
        self::assertCount(1, $this->replies);
        $reply = array_values($this->replies)[0];
        self::assertSame('ai-user', $reply->getCreatedById());
        self::assertSame('opp', $reply->getParentId());
        self::assertTrue($reply->isInternal());
        self::assertSame('source', $reply->getData()->opportunityStreamAgent->sourceNoteId);
        $reply->set('deleted', true);
        self::assertFalse($this->service->context('source', 'ai', $this->postHash)->shouldRespond);
        self::assertFalse($this->service->reply('source', 'ai', $this->postHash, 'Retry', 'run-3')->published);
    }

    public function testEditingOrDeletingSourceBeforePublicationInvalidatesTheAnswer(): void
    {
        $this->source->setPost('Changed request');
        self::assertFalse($this->service->reply('source', 'ai', $this->postHash, 'Stale', 'run')->published);
        unset($this->records['Note/source']);
        self::assertFalse($this->service->context('source', 'ai', $this->postHash)->shouldRespond);
        self::assertFalse($this->service->reply('source', 'ai', $this->postHash, 'Stale', 'run')->published);
        self::assertSame([], $this->replies);
    }

    public function testTenantMoveOrDisabledMembershipStopsTheRun(): void
    {
        $this->records['Opportunity/opp']->set('tenantId', 'other-tenant');
        self::assertFalse($this->service->context('source', 'ai', $this->postHash)->shouldRespond);
        $this->records['Opportunity/opp']->set('tenantId', 'tenant');
        $this->records['ChatwootAccountUserMembership/ai']->set('isAI', false);
        self::assertFalse($this->service->reply('source', 'ai', $this->postHash, 'Answer', 'run')->published);
    }

    public function testUserMustBeTheCurrentAiIdentity(): void
    {
        $this->records['ChatwootUser/cw-user']->set('assignedUserId', 'another-user');
        $this->expectException(Forbidden::class);
        $this->service->context('source', 'ai', $this->postHash);
    }

    public function testReadPermissionIsRecheckedAtPublication(): void
    {
        self::assertTrue($this->service->context('source', 'ai', $this->postHash)->shouldRespond);
        $this->canRead = false;
        $this->expectException(Forbidden::class);
        $this->service->reply('source', 'ai', $this->postHash, 'Answer', 'run');
    }

    public function testPostingPermissionIsEnforced(): void
    {
        $this->canCreate = false;
        $this->expectException(Forbidden::class);
        $this->service->reply('source', 'ai', $this->postHash, 'Answer', 'run');
    }

    public function testAnAiReplyCannotTriggerAnotherAi(): void
    {
        $data = $this->source->getData();
        $data->opportunityStreamAgent = (object) ['sourceNoteId' => 'previous'];
        $this->source->setData($data);
        self::assertFalse($this->service->context('source', 'ai', $this->postHash)->shouldRespond);
        self::assertSame([], $this->replies);
    }
}
