<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\RecordId;
use Espo\Modules\FeatureCredits\Jobs\RunEnrichmentBatch;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;
use stdClass;

/** Customer API for bulk enrichment. Runs belong to the user who confirmed them, like quotes. */
final class Batches
{
    public function __construct(private EntityManager $em, private Access $access, private Quotes $quotes,
        private Clock $clock, private JobSchedulerFactory $scheduler) {}

    /** Per-record plan for at most 50 records; the dashboard pages through a selection and sums. */
    public function preview(stdClass $body): array
    {
        $input = new BatchInput($body, BatchInput::MAX_PREVIEW);
        $this->access->tenant($input->tenantId());
        $records = [];
        foreach ($input->targetIds as $id) {
            $records[] = ['targetId' => $id, ...$this->plan($input->tenantId(), $input->targetType(), $id, $input->settings)];
        }
        if (isset($input->settings['sources'])) {
            return ['records' => $records, ...$this->quotes->sourceRates($input->targetType(), $input->settings['products'])];
        }
        return ['records' => $records];
    }

    public function create(stdClass $body): array
    {
        $input = new BatchInput($body, BatchInput::MAX_RECORDS, true);
        $this->access->tenant($input->tenantId());
        $actor = $this->access->actorId();
        // Fail fast on missing enrichment permission; each record is re-authorized when it runs.
        $this->access->record(new QuoteInput((object) [...BatchInput::base($input->tenantId(), $input->targetType(),
            $input->targetIds[0], $input->settings), 'requestKey' => 'preview']));
        $tenant = $input->tenantId();
        $id = $this->em->getTransactionManager()->run(function () use ($input, $actor, $tenant): string {
            $pdo = $this->em->getPDO();
            $q = $pdo->prepare('SELECT id, target_type, product, settings FROM enrichment_batch
                WHERE tenant_id=? AND created_by_id=? AND request_key=? AND deleted=FALSE');
            $q->execute([$tenant, $actor, $input->data['requestKey']]);
            if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                if ($row['target_type'] !== $input->targetType() || $row['product'] !== $input->product() ||
                    json_decode($row['settings'], true) != $input->settings) {
                    throw new Conflict('Bulk enrichment request already has different settings.');
                }
                return $row['id'];
            }
            $id = RecordId::generate(); $now = $this->clock->now();
            $pdo->prepare('INSERT INTO enrichment_batch (id, tenant_id, created_by_id, request_key, target_type, product,
                settings, state, total, created_at, deleted) VALUES (?,?,?,?,?,?,?,?,?,?,FALSE)')
                ->execute([$id, $tenant, $actor, $input->data['requestKey'], $input->targetType(), $input->product(),
                    json_encode($input->settings, JSON_THROW_ON_ERROR), 'running', count($input->targetIds), $now]);
            $item = $pdo->prepare('INSERT INTO enrichment_batch_item (id, batch_id, tenant_id, target_id, position, state, deleted)
                VALUES (?,?,?,?,?,?,FALSE)');
            foreach ($input->targetIds as $position => $targetId) {
                $item->execute([RecordId::generate(), $id, $tenant, $targetId, $position, 'pending']);
            }
            return $id;
        });
        RunEnrichmentBatch::schedule($this->scheduler, $id);
        return $this->status(['tenantId' => $tenant, 'batchId' => $id]);
    }

    /** The current user's runs in this workspace, newest first. */
    public function list(array $params): array
    {
        if (array_diff(array_keys($params), ['tenantId']) || !is_string($params['tenantId'] ?? null)) {
            throw new InvalidArgumentException('tenantId required.');
        }
        GrantInput::identity($params['tenantId']);
        $this->access->tenant($params['tenantId']);
        $q = $this->em->getPDO()->prepare('SELECT id FROM enrichment_batch WHERE tenant_id=? AND created_by_id=? AND deleted=FALSE
            ORDER BY created_at DESC, id DESC LIMIT 20');
        $q->execute([$params['tenantId'], $this->access->actorId()]);
        return ['list' => array_map(fn (string $id) => $this->status(['tenantId' => $params['tenantId'], 'batchId' => $id]),
            $q->fetchAll(PDO::FETCH_COLUMN))];
    }

    public function status(array $params): array
    {
        $batch = $this->owned($params);
        $q = $this->em->getPDO()->prepare('SELECT target_id, state, reason, credits, outcome FROM enrichment_batch_item
            WHERE batch_id=? AND tenant_id=? AND deleted=FALSE ORDER BY position');
        $q->execute([$batch['id'], $batch['tenant_id']]);
        return self::project($batch, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function cancel(stdClass $body): array
    {
        $batch = $this->owned(self::command($body));
        $this->em->getTransactionManager()->run(function () use ($batch): void {
            $pdo = $this->em->getPDO();
            $pdo->prepare("UPDATE enrichment_batch SET state='cancelled', pause_reason=NULL WHERE id=? AND state IN ('running','paused')")
                ->execute([$batch['id']]);
            // Admitted records are already paid and finish normally; nothing new starts.
            $pdo->prepare("UPDATE enrichment_batch_item SET state='cancelled', finished_at=? WHERE batch_id=?
                AND state IN ('pending','no_credits')")->execute([$this->clock->now(), $batch['id']]);
        });
        RunEnrichmentBatch::schedule($this->scheduler, $batch['id']);
        return $this->status(['tenantId' => $batch['tenant_id'], 'batchId' => $batch['id']]);
    }

    /** After a top-up: records stopped for lack of credits go back in line. */
    public function resume(stdClass $body): array
    {
        $batch = $this->owned(self::command($body));
        if ($batch['state'] !== 'paused') throw new Conflict('Only a paused bulk enrichment can be resumed.');
        $this->em->getTransactionManager()->run(function () use ($batch): void {
            $pdo = $this->em->getPDO();
            $pdo->prepare("UPDATE enrichment_batch SET state='running', pause_reason=NULL, completed_at=NULL WHERE id=? AND state='paused'")
                ->execute([$batch['id']]);
            $pdo->prepare("UPDATE enrichment_batch_item SET state='pending', reason=NULL, finished_at=NULL
                WHERE batch_id=? AND state='no_credits'")->execute([$batch['id']]);
        });
        RunEnrichmentBatch::schedule($this->scheduler, $batch['id']);
        return $this->status(['tenantId' => $batch['tenant_id'], 'batchId' => $batch['id']]);
    }

    /** @return array{maximum?: string, reason?: string, available?: object} */
    private function plan(string $tenant, string $type, string $id, array $settings): array
    {
        try {
            $plan = $this->quotes->batchPlan(BatchInput::base($tenant, $type, $id, $settings));
        } catch (Forbidden) {
            return ['reason' => 'forbidden'];
        } catch (NotFound) {
            return ['reason' => 'not_found'];
        } catch (Conflict | ServiceUnavailable) {
            return ['reason' => 'unavailable'];
        }
        return [...(isset($plan['reason']) ? ['reason' => $plan['reason']] : ['maximum' => $plan['maximum']]),
            ...(isset($plan['available']) ? ['available' => (object) $plan['available']] : [])];
    }

    private function owned(array $params): array
    {
        if (array_diff(array_keys($params), ['tenantId', 'batchId']) || !is_string($params['tenantId'] ?? null) ||
            !is_string($params['batchId'] ?? null)) throw new InvalidArgumentException('tenantId and batchId required.');
        GrantInput::identity($params['tenantId']); GrantInput::identity($params['batchId']);
        $this->access->tenant($params['tenantId']);
        $q = $this->em->getPDO()->prepare('SELECT * FROM enrichment_batch WHERE id=? AND tenant_id=? AND created_by_id=? AND deleted=FALSE');
        $q->execute([$params['batchId'], $params['tenantId'], $this->access->actorId()]);
        $batch = $q->fetch(PDO::FETCH_ASSOC);
        if (!$batch) throw new NotFound('Unknown bulk enrichment.');
        return $batch;
    }

    private static function command(stdClass $body): array
    {
        $data = get_object_vars($body);
        if (array_diff(array_keys($data), ['tenantId', 'batchId'])) throw new InvalidArgumentException('Only tenantId and batchId are accepted.');
        return $data;
    }

    /** Counts and totals only: no supplier, quote or purchased values leave through this projection. */
    public static function project(array $batch, array $items): array
    {
        $counts = array_fill_keys(['pending', 'running', 'done', 'skipped', 'no_credits', 'failed', 'cancelled'], 0);
        $skipped = []; $totals = []; $review = []; $credits = Amount::fromString('0'); $found = 0;
        foreach ($items as $item) {
            $counts[$item['state']] = ($counts[$item['state']] ?? 0) + 1;
            if ($item['state'] === 'skipped') $skipped[$item['reason']] = ($skipped[$item['reason']] ?? 0) + 1;
            if ($item['credits'] !== null) $credits = $credits->plus(Amount::fromString($item['credits']));
            $outcome = $item['outcome'] === null ? [] : json_decode($item['outcome'], true, 4, JSON_THROW_ON_ERROR);
            foreach ($outcome as $key => $value) if (is_int($value)) $totals[$key] = ($totals[$key] ?? 0) + $value;
            if (($outcome['emailsFound'] ?? 0) + ($outcome['phonesFound'] ?? 0) + ($outcome['sociosFound'] ?? 0) > 0) $found++;
            if (($outcome['needsReview'] ?? 0) > 0 || $item['state'] === 'failed') $review[] = $item['target_id'];
        }
        return ['id' => $batch['id'], 'tenantId' => $batch['tenant_id'], 'targetType' => $batch['target_type'],
            'product' => $batch['product'], 'state' => $batch['state'], 'pauseReason' => $batch['pause_reason'],
            'total' => (int) $batch['total'], 'createdAt' => $batch['created_at'], 'completedAt' => $batch['completed_at'],
            'counts' => $counts, 'skipped' => (object) $skipped, 'credits' => (string) $credits,
            'recordsFound' => $found, 'totals' => (object) $totals, 'reviewTargetIds' => array_slice($review, 0, 100)];
    }
}
