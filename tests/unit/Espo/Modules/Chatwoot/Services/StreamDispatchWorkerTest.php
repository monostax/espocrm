<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Job\JobRunner;
use Espo\Entities\Job;
use Espo\Entities\Note;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use Espo\Modules\Chatwoot\Services\StreamDispatchWorker;
use Espo\Modules\Chatwoot\Tools\Stream\DispatchQueue;
use PHPUnit\Framework\TestCase;

class StreamDispatchWorkerTest extends TestCase
{
    private function exercise(bool $lostClaim): void
    {
        $fields = array_fill_keys(['id', 'status', 'queue', 'startedAt'], ['type' => 'varchar']);
        $job = new Job('Job', ['attributes' => $fields + ['pid' => ['type' => 'int'], 'data' => ['type' => 'jsonObject']]]);
        $job->set(['id' => 'probe', 'status' => 'Pending', 'queue' => 'stream-ai']);
        $em = $this->createMock(EntityManager::class);
        $repo = $this->createMock(RDBRepository::class);
        $select = $this->createMock(RDBSelectBuilder::class);
        $em->method('getRDBRepository')->with('Job')->willReturn($repo);
        $repo->method('where')->willReturnCallback(function ($where) use ($select) {
            self::assertSame('stream-ai', $where['queue']);
            self::assertSame('Pending', $where['status']);
            return $select;
        });
        $select->method('order')->willReturnSelf();
        $select->expects(self::once())->method('forUpdate')->willReturnSelf();
        $select->method('findOne')->willReturnOnConsecutiveCalls($job, $lostClaim ? null : $job);
        $inTransaction = false;
        $transaction = $this->createMock(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(function ($fn) use (&$inTransaction) {
            $inTransaction = true;
            try { return $fn(); } finally { $inTransaction = false; }
        });
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->expects($lostClaim ? self::never() : self::once())->method('saveEntity')->willReturnCallback(function ($saved) use (&$inTransaction) {
            self::assertTrue($inTransaction);
            self::assertSame('Running', $saved->getStatus());
            self::assertNotNull($saved->getStartedAt());
        });
        $runner = $this->createMock(JobRunner::class);
        $runner->expects($lostClaim ? self::never() : self::once())->method('run')->willReturnCallback(function ($claimed) use (&$inTransaction) {
            self::assertFalse($inTransaction, 'External delivery must occur after commit.');
            $claimed->setStatus('Success');
        });
        self::assertSame(!$lostClaim, (new StreamDispatchWorker($em, $runner))->tick('stream-ai'));
    }

    public function testClaimCommitsBeforeDelivery(): void { $this->exercise(false); }
    public function testAnotherConsumerWinningTheClaimPreventsDuplicateDelivery(): void { $this->exercise(true); }

    public function testQueueCutoverAndInteractiveNotifications(): void
    {
        $before = getenv('CRM_STREAM_DISPATCH_ENABLED');
        try {
            $note = new Note('Note', ['attributes' => ['data' => ['type' => 'jsonObject'], 'parentType' => ['type' => 'varchar']]]);
            $note->set('data', (object) ['opportunityAiMentionTargets' => [(object) ['aiAgentMembershipId' => 'ai']]]);
            putenv('CRM_STREAM_DISPATCH_ENABLED=0');
            self::assertSame('q0', DispatchQueue::execution());
            self::assertSame('q0', DispatchQueue::notification($note));
            putenv('CRM_STREAM_DISPATCH_ENABLED=1');
            self::assertSame('stream-ai', DispatchQueue::execution());
            self::assertSame('stream-ui', DispatchQueue::notification($note));
            $note->set('data', (object) []);
            self::assertSame('q0', DispatchQueue::notification($note));
        } finally {
            putenv($before === false ? 'CRM_STREAM_DISPATCH_ENABLED' : 'CRM_STREAM_DISPATCH_ENABLED=' . $before);
        }
    }
}
