<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\Core\ApplicationState;
use Espo\Core\Repositories\Database;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Job\Job\Data;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Hooks\Note\KeepOpportunityEventsInternal;
use Espo\Modules\Chatwoot\Jobs\ExpireStreamAgentReply;
use Espo\Modules\Chatwoot\Services\OpportunityStreamAgent;
use Espo\Modules\Chatwoot\Services\StreamAgentProgress;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\Modules\Chatwoot\Tools\Activities\Access as ActivityAccess;
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
    private EntityManager $em;
    private array $sourceSaveOptions = [];
    private bool $humanAccess = true;
    private bool $humanTenant = true;
    private bool $attachmentFields = true;
    private array $attachments = [];
    private \Espo\Core\FileStorage\Manager $files;

    private function note(array $values = []): Note
    {
        $defs = ['attributes' => []];
        foreach (['id', 'type', 'post', 'parentType', 'parentId', 'createdById', 'createdByName', 'createdAt',
            'opportunityChatwootAccountId', 'opportunityStreamEventKey', 'opportunityThreadRootId', 'number', 'modifiedAt', 'modifiedById'] as $field) {
            $defs['attributes'][$field] = ['type' => 'varchar'];
        }
        $defs['attributes']['data'] = ['type' => 'jsonObject'];
        $defs['attributes']['isInternal'] = ['type' => 'bool'];
        $defs['attributes']['deleted'] = ['type' => 'bool'];
        $defs['attributes']['opportunityPostDeleted'] = ['type' => 'bool'];
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
        $em = $this->em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $this->records["$type/$id"] ?? null);
        $em->method('getNewEntity')->with('Note')->willReturnCallback(fn () => $this->note());
        $em->method('saveEntity')->willReturnCallback(function (Note $note, array $options = []): void {
            self::assertTrue($this->locked, 'Publication must hold the source row lock.');
            if ($note === $this->source) {
                $this->sourceSaveOptions = $options;
                return; // Persisting the execution claim, not a reply.
            }
            (new KeepOpportunityEventsInternal())->beforeSave($note, []);
            if (!$note->get('id')) $note->set('id', 'reply-' . count($this->replies));
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
        $repo->method('getRelation')->willReturnCallback(function ($note, $name) {
            self::assertSame('attachments', $name);
            $relation = $this->createMock(\Espo\ORM\Repository\RDBRelation::class);
            $relation->method('find')->willReturn(new \Espo\ORM\EntityCollection($this->attachments[$note->getId()] ?? []));
            return $relation;
        });
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('ai-user');
        $user->method('isActive')->willReturn(true);
        $access = $this->createMock(OpportunityAccess::class);
        $access->method('canReadNote')->willReturnCallback(fn () => $this->canRead);
        $acl = $this->createMock(Acl::class);
        $acl->method('check')->willReturnCallback(fn ($entity, $action) => $action === 'create' ? $this->canCreate : $this->canRead);
        $activities = $this->createMock(ActivityAccess::class);
        $activities->method('record')->willReturnCallback(function ($type, $id, $tenant, $stream) {
            self::assertTrue($stream);
            if (!$this->canRead || ($this->records["$type/$id"]->get('tenantId') !== $tenant->getId())) throw new Forbidden();
            return $this->records["$type/$id"];
        });
        $this->records['Tenant/tenant'] = new EntityDouble(['id' => 'tenant']);
        $aclManager = $this->createMock(AclManager::class);
        $aclManager->method('checkEntityRead')->willReturnCallback(fn () => $this->humanAccess);
        $aclManager->method('checkEntityStream')->willReturnCallback(fn () => $this->humanAccess);
        $aclManager->method('checkField')->willReturnCallback(fn () => $this->attachmentFields);
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('canActForTenant')->willReturnCallback(fn () => $this->humanTenant);
        $this->service = new OpportunityStreamAgent($em, $user, $acl, $access, $this->createMock(NoteUtil::class), $activities,
            $this->createMock(StreamAgentProgress::class), $aclManager, $tenants, $this->createMock(\Espo\Modules\FeatureAiSession\Services\Execution::class),
            $this->files = $this->createMock(\Espo\Core\FileStorage\Manager::class));
    }

    private function mediaAttachment(string $postId = 'source'): void
    {
        $attachment = $this->createMock(\Espo\Entities\Attachment::class);
        $attachment->method('getId')->willReturn('image');
        $attachment->method('get')->willReturnCallback(fn ($field) => ['name' => 'image.png', 'type' => 'image/png', 'size' => 5][$field] ?? null);
        $this->attachments[$postId] = [$attachment];
    }

    public function testAttachmentTransportRequiresClaimAndDoesNotReturnUnrelatedIds(): void
    {
        $this->mediaAttachment();
        $this->files->expects(self::never())->method('getStream');
        try {
            $this->service->attachments('source', 'ai', $this->postHash, 'run', 'source', null);
            self::fail('Unclaimed runs must not read attachments.');
        } catch (Forbidden) {}
        $this->service->claim('source', 'ai', $this->postHash, 'run');
        $this->expectException(Forbidden::class);
        $this->service->attachments('source', 'ai', $this->postHash, 'run', 'source', 'foreign-file');
    }

    public function testAttachmentManifestAndBoundedBytesUseTheNoteRelation(): void
    {
        $this->mediaAttachment();
        $this->service->claim('source', 'ai', $this->postHash, 'run');
        $manifest = $this->service->attachments('source', 'ai', $this->postHash, 'run', 'source', null);
        self::assertSame('image', $manifest->list[0]->id);
        self::assertFalse(isset($manifest->list[0]->data));
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $stream->method('read')->willReturn('bytes');
        $this->files->expects(self::once())->method('getStream')->willReturn($stream);
        $result = $this->service->attachments('source', 'ai', $this->postHash, 'run', 'source', 'image');
        self::assertSame(base64_encode('bytes'), $result->data);
        self::assertSame(hash('sha256', 'bytes'), $result->revision);
    }

    public function testHistoricalThreadRootIsReadableButOtherThreadsAreNot(): void
    {
        $this->source->set('opportunityThreadRootId', 'root');
        $this->records['Note/root'] = $this->note(['id' => 'root', 'type' => 'Post', 'parentType' => 'Opportunity', 'parentId' => 'opp', 'number' => '5']);
        $this->mediaAttachment('root');
        $this->service->claim('source', 'ai', $this->postHash, 'run');
        self::assertCount(1, $this->service->attachments('source', 'ai', $this->postHash, 'run', 'root', null)->list);
        $this->records['Note/other'] = $this->note(['id' => 'other', 'type' => 'Post', 'parentType' => 'Opportunity', 'parentId' => 'opp', 'number' => '6']);
        $this->expectException(Forbidden::class);
        $this->service->attachments('source', 'ai', $this->postHash, 'run', 'other', null);
    }

    public function testAttachmentReadsRecheckHumanFieldAccess(): void
    {
        $this->mediaAttachment();
        $this->service->claim('source', 'ai', $this->postHash, 'run');
        $this->attachmentFields = false;
        $this->files->expects(self::never())->method('getStream');
        $this->expectException(Forbidden::class);
        $this->service->attachments('source', 'ai', $this->postHash, 'run', 'source', 'image');
    }

    public function testDelegationRequiresCapturedHumanAndCurrentAccess(): void
    {
        self::assertNull($this->service->context('source', 'ai', $this->postHash)->initiatorUserId);
        $human = $this->createMock(User::class);
        $human->method('isActive')->willReturn(true);
        $this->records['User/human'] = $human;
        $data = $this->source->getData();
        $data->opportunityAiInitiatorUserId = 'human';
        $this->source->setData($data);
        self::assertSame('human', $this->service->context('source', 'ai', $this->postHash)->initiatorUserId);
        $this->humanAccess = false;
        self::assertNull($this->service->context('source', 'ai', $this->postHash)->initiatorUserId);
        $this->humanAccess = true;
        $this->humanTenant = false;
        self::assertNull($this->service->context('source', 'ai', $this->postHash)->initiatorUserId);
        $this->humanTenant = true;
        $this->source->set('createdById', 'other-human');
        self::assertNull($this->service->context('source', 'ai', $this->postHash)->initiatorUserId);
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

    public function testOnlyOneExecutionCanClaimAMentionIncludingSameRunRedelivery(): void
    {
        self::assertTrue($this->service->claim('source', 'ai', $this->postHash, 'run-1')->claimed);
        self::assertFalse($this->service->claim('source', 'ai', $this->postHash, 'run-2')->claimed);
        self::assertFalse($this->service->claim('source', 'ai', $this->postHash, 'run-1')->claimed);
        self::assertSame('run-1', $this->service->context('source', 'ai', $this->postHash)->executionRunId);
        self::assertTrue($this->service->reply('source', 'ai', $this->postHash, 'Done', 'run-1')->published);
    }

    public function testAnotherExecutionCannotPublishForTheClaimOwner(): void
    {
        $this->service->claim('source', 'ai', $this->postHash, 'run-1');
        $this->expectException(Forbidden::class);
        $this->service->reply('source', 'ai', $this->postHash, 'False result', 'run-2');
    }

    public function testStaleSourcesAndCompletedRequestsCannotBeClaimed(): void
    {
        self::assertFalse($this->service->claim('source', 'ai', str_repeat('0', 64), 'run-1')->claimed);
        $this->service->reply('source', 'ai', $this->postHash, 'Done', 'run-1');
        self::assertFalse($this->service->claim('source', 'ai', $this->postHash, 'run-2')->claimed);
    }

    public function testAllSupportedStreamsKeepTheirParentThreadAndDeduplication(): void
    {
        foreach (['Opportunity', 'Initiative', 'Task', 'Meeting', 'Call', 'Account', 'Contact'] as $type) {
            $this->setUp();
            $this->source->set(['parentType' => $type, 'parentId' => 'record', 'opportunityThreadRootId' => 'thread']);
            $this->records["$type/record"] = new EntityDouble(['tenantId' => 'tenant']);
            $context = $this->service->context('source', 'ai', $this->postHash);
            self::assertTrue($context->shouldRespond);
            self::assertSame($type, $context->parentType);
            self::assertSame('record', $context->parentId);
            self::assertTrue($this->service->claim('source', 'ai', $this->postHash, 'run')->claimed);
            self::assertTrue($this->service->reply('source', 'ai', $this->postHash, 'Reply', 'run')->published);
            $reply = array_values($this->replies)[0];
            self::assertSame($type, $reply->getParentType());
            self::assertSame('record', $reply->getParentId());
            self::assertSame('thread', $reply->get('opportunityThreadRootId'));
            self::assertTrue($reply->isInternal());
            self::assertFalse($this->service->reply('source', 'ai', $this->postHash, 'Retry', 'run')->published);
            $this->replies = [];
        }
    }

    public function testTombstonedPostsCannotExecute(): void
    {
        $this->source->set('opportunityPostDeleted', true);
        self::assertFalse($this->service->context('source', 'ai', $this->postHash)->shouldRespond);
        self::assertFalse($this->service->claim('source', 'ai', $this->postHash, 'run')->claimed);
    }

    private function queuedReply(): Note
    {
        $key = hash('sha256', 'opportunity-stream-agent:source:ai');
        return $this->records['Note/pending-reply'] = $this->replies[$key] = $this->note([
            'id' => 'pending-reply', 'type' => 'Post', 'parentType' => 'Opportunity', 'parentId' => 'opp',
            'createdById' => 'ai-user', 'post' => 'Waiting to start…', 'opportunityStreamEventKey' => $key,
            'data' => (object) ['opportunityStreamAgent' => (object) [
                'sourceNoteId' => 'source', 'aiAgentMembershipId' => 'ai', 'status' => 'queued', 'workflowRunId' => null,
            ]],
        ]);
    }

    public function testQueuedReplyIsClaimedAndCompletedInPlaceExactlyOnce(): void
    {
        $reply = $this->queuedReply();
        self::assertTrue($this->service->context('source', 'ai', $this->postHash)->shouldRespond);
        self::assertTrue($this->service->claim('source', 'ai', $this->postHash, 'run')->claimed);
        self::assertSame('running', $reply->getData()->opportunityStreamAgent->status);
        self::assertSame('run', $reply->getData()->opportunityStreamAgent->workflowRunId);
        self::assertFalse($this->service->claim('source', 'ai', $this->postHash, 'run')->claimed);
        $result = $this->service->reply('source', 'ai', $this->postHash, 'Final answer', 'run');
        self::assertTrue($result->published);
        self::assertSame('pending-reply', $result->noteId);
        self::assertCount(1, $this->replies);
        self::assertSame('Final answer', $reply->getPost());
        self::assertSame('completed', $reply->getData()->opportunityStreamAgent->status);
        self::assertFalse($this->service->reply('source', 'ai', $this->postHash, 'Duplicate', 'run')->published);
        self::assertFalse($this->service->status('source', 'ai', 'run', 'failed')->updated);
        self::assertSame('Final answer', $reply->getPost());
    }

    public function testProgressIsOrderedOwnedAndPreservedOnCompletion(): void
    {
        $reply = $this->queuedReply();
        $snapshot = (object) ['sequence' => 2, 'phase' => 'thinking', 'activities' => [(object) [
            'id' => 'call', 'kind' => 'send', 'status' => 'accepted',
            'startedAt' => '2026-10-09T02:33:53.000Z', 'finishedAt' => '2026-10-09T02:33:54.000Z',
        ]]];
        self::assertFalse($this->service->updateProgress('source', 'ai', 'run', $snapshot)->updated);
        $this->service->claim('source', 'ai', $this->postHash, 'run');
        $startedAt = $reply->getData()->opportunityStreamAgent->startedAt;
        self::assertNotEmpty($startedAt);
        self::assertFalse($this->service->updateProgress('source', 'ai', 'other', $snapshot)->updated);
        self::assertTrue($this->service->updateProgress('source', 'ai', 'run', $snapshot)->updated);
        self::assertFalse($this->service->updateProgress('source', 'ai', 'run', $snapshot)->updated);
        $older = clone $snapshot;
        $older->sequence = 1;
        self::assertFalse($this->service->updateProgress('source', 'ai', 'run', $older)->updated);
        self::assertTrue($this->service->reply('source', 'ai', $this->postHash, 'Final answer', 'run')->published);
        $snapshot->sequence = 3;
        self::assertFalse($this->service->updateProgress('source', 'ai', 'run', $snapshot)->updated);
        $progress = $reply->getData()->opportunityStreamAgent;
        self::assertSame('completed', $progress->status);
        self::assertSame($startedAt, $progress->startedAt);
        self::assertNotEmpty($progress->finishedAt);
        self::assertSame('accepted', $progress->activities[0]->status);
        self::assertSame('Final answer', $reply->getPost());
    }

    public function testProgressCannotReviveCancelledOrDeletedReplies(): void
    {
        $reply = $this->queuedReply();
        $this->service->claim('source', 'ai', $this->postHash, 'run');
        $snapshot = (object) ['sequence' => 1, 'phase' => 'thinking', 'activities' => []];
        $reply->set('opportunityPostDeleted', true);
        self::assertFalse($this->service->updateProgress('source', 'ai', 'run', $snapshot)->updated);
        $reply->set('opportunityPostDeleted', false);
        $this->service->status('source', 'ai', 'run', 'cancelled');
        self::assertFalse($this->service->updateProgress('source', 'ai', 'run', $snapshot)->updated);
        self::assertNotEmpty($reply->getData()->opportunityStreamAgent->finishedAt);
    }

    public function testProgressRequiresTheAuthenticatedReplyAuthor(): void
    {
        $reply = $this->queuedReply();
        $this->service->claim('source', 'ai', $this->postHash, 'run');
        $reply->set('createdById', 'other-ai');
        $this->expectException(Forbidden::class);
        $this->service->updateProgress('source', 'ai', 'run', (object) [
            'sequence' => 1, 'phase' => 'thinking', 'activities' => [],
        ]);
    }

    public function testOnlyTheOwningRunCanStopARunningReplyEvenAfterTheSourceIsEdited(): void
    {
        $reply = $this->queuedReply();
        $this->service->claim('source', 'ai', $this->postHash, 'owner');
        self::assertFalse($this->service->status('source', 'ai', 'duplicate', 'failed')->updated);
        $this->source->setPost('Edited');
        self::assertTrue($this->service->status('source', 'ai', 'owner', 'cancelled')->updated);
        self::assertSame('cancelled', $reply->getData()->opportunityStreamAgent->status);
        self::assertFalse(StreamAgentProgress::isPending($reply));
    }

    public function testBudgetBlockEndsQueuedFeedbackWithoutAnExecutionClaim(): void
    {
        $reply = $this->queuedReply();
        self::assertTrue($this->service->status('source', 'ai', 'run', 'blocked')->updated);
        self::assertSame('blocked', $reply->getData()->opportunityStreamAgent->status);
        self::assertFalse($this->service->claim('source', 'ai', $this->postHash, 'run')->claimed);
    }

    public function testDeletedPlaceholderIsNeverResurrected(): void
    {
        $reply = $this->queuedReply();
        $this->service->claim('source', 'ai', $this->postHash, 'run');
        $reply->set('opportunityPostDeleted', true);
        self::assertFalse($this->service->reply('source', 'ai', $this->postHash, 'Answer', 'run')->published);
        self::assertFalse($this->service->status('source', 'ai', 'run', 'failed')->updated);
    }

    public function testPostingPermissionIsRecheckedWhenCompletingAPendingReply(): void
    {
        $this->queuedReply();
        $this->service->claim('source', 'ai', $this->postHash, 'run');
        $this->canCreate = false;
        $this->expectException(Forbidden::class);
        $this->service->reply('source', 'ai', $this->postHash, 'Answer', 'run');
    }

    public function testLegacyPlaceholderAuthorIsCorrectedAtClaimAndCompletion(): void
    {
        $reply = $this->queuedReply();
        $reply->set('createdById', 'human');
        self::assertTrue($this->service->claim('source', 'ai', $this->postHash, 'run')->claimed);
        self::assertSame('ai-user', $reply->getCreatedById());
        // Also cover a run already claimed before the fix was deployed.
        $reply->set('createdById', 'human');
        self::assertTrue($this->service->reply('source', 'ai', $this->postHash, 'Answer', 'run')->published);
        self::assertSame('ai-user', $reply->getCreatedById());
    }

    public function testExecutionClaimPreservesTheTriggersExistingEditHistory(): void
    {
        $this->source->setAsNotNew();
        $this->source->set(['modifiedAt' => '2026-09-15 12:05:00', 'modifiedById' => 'human-editor']);
        $post = $this->source->getPost();
        self::assertTrue($this->service->claim('source', 'ai', $this->postHash, 'run')->claimed);

        $actor = $this->createMock(User::class);
        $actor->method('getId')->willReturn('ai-user');
        $state = $this->createMock(ApplicationState::class);
        $state->method('hasUser')->willReturn(true);
        $state->method('getUser')->willReturn($actor);
        $reflection = new \ReflectionClass(Database::class);
        $database = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('applicationState')->setValue($database, $state);
        $reflection->getMethod('processCreatedAndModifiedFieldsSave')->invoke($database, $this->source, $this->sourceSaveOptions);

        self::assertSame($post, $this->source->getPost());
        self::assertSame('2026-09-15 12:05:00', $this->source->get('modifiedAt'));
        self::assertSame('human-editor', $this->source->get('modifiedById'));
        self::assertSame('run', $this->source->getData()->opportunityAiExecutions->ai);
    }

    public function testQueuedExpiryCannotStopAClaimedRunAndCompletedAnswersSurviveExpiry(): void
    {
        $reply = $this->queuedReply();
        $job = new ExpireStreamAgentReply($this->em);
        $data = ['sourceNoteId' => 'source', 'noteId' => 'pending-reply', 'workflowRunId' => null];
        $this->service->claim('source', 'ai', $this->postHash, 'run');
        $job->run(Data::create($data));
        self::assertSame('running', $reply->getData()->opportunityStreamAgent->status);
        $this->service->reply('source', 'ai', $this->postHash, 'Final', 'run');
        $job->run(Data::create([...$data, 'workflowRunId' => 'run']));
        self::assertSame('completed', $reply->getData()->opportunityStreamAgent->status);
        self::assertSame('Final', $reply->getPost());
    }

    public function testAbandonedQueuedAndRunningRepliesExpire(): void
    {
        foreach ([null, 'run'] as $runId) {
            $reply = $this->queuedReply();
            if ($runId) $this->service->claim('source', 'ai', $this->postHash, $runId);
            (new ExpireStreamAgentReply($this->em))->run(Data::create([
                'sourceNoteId' => 'source', 'noteId' => 'pending-reply', 'workflowRunId' => $runId,
            ]));
            self::assertSame('failed', $reply->getData()->opportunityStreamAgent->status);
            self::assertFalse(StreamAgentProgress::isPending($reply));
        }
    }
}
