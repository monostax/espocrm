<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\QueueName;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Jobs\BroadcastOpportunityUpdate;
use Espo\Modules\Chatwoot\Jobs\ProcessOpportunityBulkPost;
use Espo\Modules\Chatwoot\Tools\Stream\BulkPostContext;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

class OpportunityBulkPost
{
    public const OPERATION = 'OpportunityBulkPost';
    public const TARGET = 'OpportunityBulkPostTarget';
    public const CHUNK_SIZE = 50;

    public function __construct(
        private EntityManager $em,
        private User $user,
        private OpportunityBulkPostAccess $access,
        private ServiceFactory $records,
        private BulkPostContext $context,
        private Log $log,
    ) {}

    public function submit(int $accountId, object $data): object
    {
        $account = $this->access->workspace($accountId);
        $ids = $data->ids ?? null;
        $post = $data->post ?? null;
        $key = $data->idempotencyKey ?? null;
        if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 10000 ||
            !is_string($post) || !trim($post) || strlen($post) > 100000 ||
            !is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $key)) {
            throw new BadRequest('Provide up to 10000 IDs, a post, and an idempotency key.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id)) throw new BadRequest('Invalid ID.');
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $post = trim($post);
        $requestKey = hash('sha256', $this->user->getId() . ':' . $account->getId() . ':' . $key);
        $payloadHash = hash('sha256', json_encode([$ids, $post], JSON_THROW_ON_ERROR));

        $operation = $this->em->getTransactionManager()->run(function () use ($account, $ids, $post, $requestKey, $payloadHash): Entity {
            // Serialize submissions by the same actor, including the first use of a key.
            $this->em->getRDBRepository('User')->select('id')->where(['id' => $this->user->getId()])->forUpdate()->findOne();
            $existing = $this->em->getRDBRepository(self::OPERATION)->where(['requestKey' => $requestKey])->findOne();
            if ($existing) {
                $this->access->operation($existing, $account);
                if (!hash_equals($existing->get('payloadHash'), $payloadHash)) throw new Conflict('Idempotency key already used.');
                return $existing;
            }
            // Validate the complete fixed selection before accepting any work. Never disclose which foreign ID failed.
            foreach (array_chunk($ids, 100) as $batch) {
                $found = $this->em->getRDBRepository('Opportunity')->where(['id' => $batch])->find();
                if (count($found) !== count($batch)) throw new Forbidden('Selection is unavailable.');
                foreach ($found as $opportunity) $this->access->target($opportunity, $account);
            }
            $operation = $this->em->createEntity(self::OPERATION, [
                'requestKey' => $requestKey, 'payloadHash' => $payloadHash, 'post' => $post,
                'userId' => $this->user->getId(), 'accountId' => $account->getId(),
                'tenantId' => $account->get('tenantId'), 'platformId' => $account->get('platformId'),
                'chatwootAccountId' => (int) $account->get('chatwootAccountId'),
                'total' => count($ids), 'succeeded' => 0, 'failed' => 0, 'generation' => 0,
            ]);
            foreach ($ids as $id) {
                $this->em->createEntity(self::TARGET, ['operationId' => $operation->getId(), 'opportunityId' => $id, 'status' => 'Pending']);
            }
            $this->schedule($operation);
            return $operation;
        });
        return $this->summary($operation);
    }

    public function recent(int $accountId): object
    {
        $account = $this->access->workspace($accountId);
        $where = [
            'userId' => $this->user->getId(), 'accountId' => $account->getId(),
            'tenantId' => $account->get('tenantId'), 'platformId' => $account->get('platformId'),
            'chatwootAccountId' => $accountId,
        ];
        $list = [];
        foreach ($this->em->getRDBRepository(self::OPERATION)->where($where)
            ->order('createdAt', 'DESC')->limit(0, 20)->find() as $operation) {
            $list[$operation->getId()] = $this->summary($operation);
        }
        // Newer completed operations must not hide a still-running operation after reload.
        foreach ($this->em->getRDBRepository(self::OPERATION)->where($where + ['status' => ['Queued', 'Running']])
            ->order('createdAt', 'DESC')->find() as $operation) {
            $list[$operation->getId()] ??= $this->summary($operation);
        }
        return (object) ['list' => array_values($list)];
    }

    public function status(int $accountId, string $id): object
    {
        return $this->summary($this->owned($id, $this->access->workspace($accountId)));
    }

    public function results(int $accountId, string $id, ?string $after): object
    {
        $this->owned($id, $this->access->workspace($accountId));
        $where = ['operationId' => $id];
        if ($after !== null) $where['id>'] = $after;
        $targets = iterator_to_array($this->em->getRDBRepository(self::TARGET)
            ->where($where)->order('id')->limit(0, 101)->find(), false);
        $hasMore = count($targets) > 100;
        $targets = array_slice($targets, 0, 100);
        // Only the submitter's original IDs and outcome codes. No record names/content or exception messages.
        return (object) [
            'list' => array_map(fn (Entity $target) => (object) [
                'opportunityId' => $target->get('opportunityId'), 'status' => $target->get('status'),
                'error' => $target->get('error'),
            ], $targets),
            'next' => $hasMore ? end($targets)->getId() : null,
        ];
    }

    public function retry(int $accountId, string $id): object
    {
        $account = $this->access->workspace($accountId);
        $operation = $this->em->getTransactionManager()->run(function () use ($account, $id): Entity {
            $operation = $this->owned($id, $account, true);
            if ($this->jobActive($operation) || $operation->get('status') === 'Completed') return $operation;
            foreach ($this->em->getRDBRepository(self::TARGET)->where(['operationId' => $id, 'status' => 'Failed'])->find() as $target) {
                $target->set(['status' => 'Pending', 'error' => null]);
                $this->em->saveEntity($target);
            }
            $operation->set('failed', 0);
            $this->schedule($operation);
            return $operation;
        });
        return $this->summary($operation);
    }

    /** Runs inside a fresh container for the original author, including ALL ORM/record hooks. */
    public function process(string $id, int $generation): void
    {
        $operation = $this->em->getEntityById(self::OPERATION, $id);
        if (!$operation || (int) $operation->get('generation') !== $generation) return;
        $targets = $this->em->getRDBRepository(self::TARGET)
            ->where(['operationId' => $id, 'status' => 'Pending'])->order('id')->limit(0, self::CHUNK_SIZE)->find();
        foreach ($targets as $target) {
            try {
                $this->publish($id, $generation, $target->getId());
            } catch (Throwable $e) {
                $code = $e instanceof Forbidden || $e instanceof NotFound ? 'unavailable' : ($e instanceof BadRequest ? 'invalid_post' : 'send_failed');
                $this->log->error('Bulk opportunity post failed.', ['operationId' => $id, 'targetId' => $target->getId(), 'exception' => $e]);
                // The publish transaction has rolled back, including Note, hooks, and outbox jobs.
                $this->em->getTransactionManager()->run(function () use ($id, $generation, $target, $code): void {
                    $operation = $this->lock($id);
                    if ((int) $operation->get('generation') !== $generation) return;
                    $current = $this->locked(self::TARGET, $target->getId());
                    if ($current->get('status') !== 'Pending') return;
                    $current->set(['status' => 'Failed', 'error' => $code]);
                    $operation->set('failed', (int) $operation->get('failed') + 1);
                    $this->em->saveEntity($current);
                    $this->em->saveEntity($operation);
                });
            }
        }
        $this->em->getTransactionManager()->run(function () use ($id, $generation): void {
            $operation = $this->lock($id);
            if ((int) $operation->get('generation') !== $generation) return;
            // The dirty flag is committed with each Note, so a killed worker cannot lose the invalidation.
            if ($operation->get('broadcastPending')) {
                $this->em->createEntity('Job', [
                    'name' => BroadcastOpportunityUpdate::class, 'className' => BroadcastOpportunityUpdate::class,
                    'queue' => QueueName::Q0, 'attempts' => 3,
                    'data' => (object) ['tenantIds' => [$operation->get('tenantId')]],
                ]);
                $operation->set('broadcastPending', false);
            }
            if ((int) $operation->get('succeeded') + (int) $operation->get('failed') < (int) $operation->get('total')) {
                $this->schedule($operation);
            } else {
                $operation->set('status', $operation->get('failed') ? 'Partial' : 'Completed');
                $this->em->saveEntity($operation);
            }
        });
    }

    private function publish(string $id, int $generation, string $targetId): void
    {
        try {
            $this->em->getTransactionManager()->run(function () use ($id, $generation, $targetId): void {
                // Uniform lock order: operation -> target -> opportunity. Only one short transaction per post.
                $operation = $this->lock($id);
                if ((int) $operation->get('generation') !== $generation) return;
                $target = $this->locked(self::TARGET, $targetId);
                if (!$target || $target->get('operationId') !== $id || $target->get('status') !== 'Pending') return;
                $accountRecord = $this->em->getEntityById('ChatwootAccount', $operation->get('accountId'));
                if (!$accountRecord) throw new Forbidden();
                $account = $this->access->workspace((int) $accountRecord->get('chatwootAccountId'));
                $this->access->operation($operation, $account);
                $opportunity = $this->locked('Opportunity', $target->get('opportunityId'));
                $this->access->target($opportunity, $account);
                $this->context->opportunityId = $opportunity->getId();
                $note = $this->records->create('Note')->create((object) [
                    'type' => 'Post', 'parentType' => 'Opportunity', 'parentId' => $opportunity->getId(),
                    'post' => $operation->get('post'), 'opportunityChatwootAccountId' => (int) $account->get('chatwootAccountId'),
                ])->getEntity();
                $target->set(['status' => 'Succeeded', 'noteId' => $note->getId(), 'error' => null]);
                $operation->set([
                    'succeeded' => (int) $operation->get('succeeded') + 1,
                    'status' => 'Running', 'broadcastPending' => true,
                ]);
                $this->em->saveEntity($target);
                $this->em->saveEntity($operation);
            });
        } finally {
            $this->context->opportunityId = null;
        }
    }

    private function schedule(Entity $operation): void
    {
        $generation = (int) $operation->get('generation') + 1;
        $job = $this->em->createEntity('Job', [
            'name' => ProcessOpportunityBulkPost::class, 'className' => ProcessOpportunityBulkPost::class,
            // Same-tenant runs share a serial group; other tenants can make progress independently.
            'group' => 'opportunity-bulk-post-' . $operation->get('tenantId'), 'attempts' => 3,
            'data' => (object) ['operationId' => $operation->getId(), 'generation' => $generation],
        ]);
        $operation->set(['generation' => $generation, 'jobId' => $job->getId(), 'status' => 'Queued']);
        $this->em->saveEntity($operation);
    }

    private function owned(string $id, Entity $account, bool $lock = false): Entity
    {
        $operation = $lock ? $this->locked(self::OPERATION, $id) : $this->em->getEntityById(self::OPERATION, $id);
        if (!$operation) throw new NotFound();
        $this->access->operation($operation, $account);
        return $operation;
    }

    private function lock(string $id): Entity
    {
        $operation = $this->locked(self::OPERATION, $id);
        if (!$operation) throw new NotFound();
        return $operation;
    }

    private function locked(string $type, string $id): ?Entity
    {
        // Selecting link display names introduces outer joins, which PostgreSQL cannot FOR UPDATE.
        $row = $this->em->getRDBRepository($type)->select('id')->where(['id' => $id])->forUpdate()->findOne();
        return $row ? $this->em->getEntityById($type, $id) : null;
    }

    private function jobActive(Entity $operation): bool
    {
        $job = $this->em->getEntityById('Job', (string) $operation->get('jobId'));
        return $job && in_array($job->get('status'), ['Pending', 'Ready', 'Running'], true);
    }

    private function summary(Entity $operation): object
    {
        $status = $operation->get('status');
        if (in_array($status, ['Queued', 'Running'], true) && !$this->jobActive($operation)) $status = 'Interrupted';
        return (object) [
            'id' => $operation->getId(), 'status' => $status, 'total' => (int) $operation->get('total'),
            'succeeded' => (int) $operation->get('succeeded'), 'failed' => (int) $operation->get('failed'),
            'createdAt' => $operation->get('createdAt'),
        ];
    }
}
