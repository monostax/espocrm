<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Jobs\RunEnrichment;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Feeds a bulk run through the normal quote → confirm → admit → execute path a few records at a
 * time, then applies each settled result. Small windows keep wallet holds and the shared enrichment
 * queue fair to other users; the daemon re-runs a step every few seconds until the run is done.
 */
final class BatchRunner
{
    /** In-flight (admitted, unsettled) records per run and across every run. */
    public const WINDOW = 5;
    public const GLOBAL_WINDOW = 25;
    private const STEP_SECONDS = 8;

    public function __construct(private EntityManager $em, private ActorScope $actors, private JobSchedulerFactory $scheduler,
        private Clock $clock, private Log $log) {}

    /** One bounded step. Returns whether another step is needed. */
    public function step(string $batchId): bool
    {
        $pdo = $this->em->getPDO();
        $q = $pdo->prepare('SELECT * FROM enrichment_batch WHERE id=? AND deleted=FALSE');
        $q->execute([$batchId]);
        $batch = $q->fetch(PDO::FETCH_ASSOC);
        if (!$batch || $batch['completed_at'] !== null) return false;
        $pdo->prepare('UPDATE enrichment_batch SET stepped_at=? WHERE id=?')->execute([$this->clock->now(), $batchId]);
        $settings = json_decode($batch['settings'], true, 8, JSON_THROW_ON_ERROR);
        $until = microtime(true) + self::STEP_SECONDS;
        try {
            $this->actors->run($batch['created_by_id'], function (callable $make) use ($batch, $settings, $until): void {
                $quotes = $make(Quotes::class);
                $application = $make(BatchApplication::class);
                foreach ($this->items($batch['id'], 'running', 50) as $item) {
                    if (microtime(true) > $until) return;
                    $this->finish($quotes, $application, $batch, $settings, $item);
                }
                if ($this->state($batch['id']) !== 'running') return;
                $free = min(self::WINDOW - $this->count($batch['id'], 'running'), self::GLOBAL_WINDOW - $this->globalRunning());
                if ($free < 1) return;
                // Skipped records cost no window, so a selection full of ineligible records moves quickly.
                foreach ($this->items($batch['id'], 'pending', 50) as $item) {
                    if (microtime(true) > $until) return;
                    $started = $this->start($quotes, $batch, $settings, $item);
                    if ($started === null) return;
                    if ($started && --$free < 1) return;
                }
            });
        } catch (Forbidden) {
            // The purchaser left or lost access: nothing new starts, and admitted work is waived by Execution.
            $this->transition($batch['id'], ['pending', 'no_credits'], 'skipped', 'forbidden');
            $this->transition($batch['id'], ['running'], 'failed', 'forbidden');
        }
        $this->expire($batch['id']);
        return $this->settle($batch['id']);
    }

    /**
     * Quote, confirm and admit one record. True when admitted, false when the record was closed
     * (skipped/failed), null when this step must admit nothing more.
     */
    private function start(Quotes $quotes, array $batch, array $settings, array $item): ?bool
    {
        $tenant = $batch['tenant_id'];
        $quoteId = $item['quote_id'];
        try {
            if ($quoteId === null) {
                $plan = $quotes->batchPlan(BatchInput::base($tenant, $batch['target_type'], $item['target_id'], $settings));
                if (isset($plan['reason'])) return $this->finishItem($item, 'skipped', $plan['reason']);
                $receipt = $quotes->quote(new QuoteInput((object) [...$plan['request'], 'requestKey' => 'bulk-' . $item['id']]));
                $quoteId = $receipt['id'];
                $this->em->getPDO()->prepare('UPDATE enrichment_batch_item SET quote_id=?, maximum=? WHERE id=?')
                    ->execute([$quoteId, $receipt['maximum'], $item['id']]);
                if (!$receipt['confirmable']) return $this->finishItem($item, 'skipped', 'no_route');
            }
            $quotes->confirm($tenant, $quoteId);
        } catch (Forbidden) {
            return $this->finishItem($item, 'skipped', 'forbidden');
        } catch (NotFound) {
            return $this->finishItem($item, 'skipped', 'not_found');
        } catch (Conflict | ServiceUnavailable | \InvalidArgumentException) {
            return $this->finishItem($item, 'skipped', 'unavailable');
        }
        try {
            $quotes->admit($tenant, $quoteId);
        } catch (Conflict $e) {
            if (!str_contains((string) $e->getBody(), 'insufficient_credits')) return $this->finishItem($item, 'failed', 'admission');
            // Holds of this run's in-flight records may be what is short; wait for them to settle first.
            if ($this->count($batch['id'], 'running') === 0) $this->pause($batch['id']);
            return null;
        }
        $this->em->getPDO()->prepare("UPDATE enrichment_batch_item SET state='running', started_at=? WHERE id=? AND state='pending'")
            ->execute([$this->clock->now(), $item['id']]);
        $this->scheduler->create()->setClassName(RunEnrichment::class)
            ->setData(['tenantId' => $tenant, 'quoteId' => $quoteId])->setGroup('enrichment-' . $quoteId)->schedule();
        return true;
    }

    /** Backstop: execution deadlines settle quotes within minutes, so a record this old is stuck. */
    private function expire(string $batchId): void
    {
        $before = (new \DateTimeImmutable($this->clock->now(), new \DateTimeZone('UTC')))->modify('-2 hours')->format('Y-m-d H:i:s');
        $this->em->getPDO()->prepare("UPDATE enrichment_batch_item SET state='failed', reason='timeout', finished_at=?
            WHERE batch_id=? AND state='running' AND started_at<?")->execute([$this->clock->now(), $batchId, $before]);
    }

    /** Apply a settled record. Still-admitted quotes are left to the execution jobs. */
    private function finish(Quotes $quotes, BatchApplication $application, array $batch, array $settings, array $item): void
    {
        $tenant = $batch['tenant_id'];
        try {
            $receipt = $quotes->status(['tenantId' => $tenant, 'quoteId' => $item['quote_id']]);
        } catch (Forbidden | NotFound | Conflict) {
            $this->finishItem($item, 'failed', 'unavailable');
            return;
        }
        if (in_array($receipt['admission'], ['admitted', 'not_admitted'], true)) return;
        $q = $this->em->getPDO()->prepare('SELECT settled_credits FROM credit_usage WHERE id=? AND tenant_id=?');
        $q->execute([$receipt['usageId'], $tenant]);
        $credits = $q->fetchColumn() ?: '0';
        $request = ['products' => $settings['products'], 'targetId' => $item['target_id']];
        try {
            $outcome = $application->apply($tenant, $item['quote_id'], $request);
        } catch (Throwable $e) {
            // Paid results stay in the record's history; the run only reports that they need review.
            $this->log->warning('Bulk enrichment application failed.', ['batchId' => $batch['id'], 'exception' => $e]);
            $outcome = ['needsReview' => 1];
        }
        $this->em->getPDO()->prepare("UPDATE enrichment_batch_item SET state='done', credits=?, outcome=?, finished_at=?
            WHERE id=? AND state='running'")->execute([$credits, json_encode($outcome, JSON_THROW_ON_ERROR),
                $this->clock->now(), $item['id']]);
    }

    /** Close the run when nothing is in flight and nothing more may start. */
    private function settle(string $batchId): bool
    {
        $state = $this->state($batchId);
        $running = $this->count($batchId, 'running');
        if ($running > 0) return true;
        if ($state === 'running' && $this->count($batchId, 'pending') > 0) return true;
        if ($state === 'paused') return false;
        $this->em->getPDO()->prepare("UPDATE enrichment_batch SET state=CASE WHEN state='running' THEN 'completed' ELSE state END,
            completed_at=? WHERE id=? AND completed_at IS NULL")->execute([$this->clock->now(), $batchId]);
        return false;
    }

    private function pause(string $batchId): void
    {
        $this->em->getPDO()->prepare("UPDATE enrichment_batch SET state='paused', pause_reason='no_credits' WHERE id=? AND state='running'")
            ->execute([$batchId]);
        $this->transition($batchId, ['pending'], 'no_credits', null);
    }

    private function finishItem(array $item, string $state, ?string $reason): bool
    {
        $this->em->getPDO()->prepare("UPDATE enrichment_batch_item SET state=?, reason=?, finished_at=? WHERE id=? AND state IN ('pending','running')")
            ->execute([$state, $reason, $this->clock->now(), $item['id']]);
        return false;
    }

    private function transition(string $batchId, array $from, string $to, ?string $reason): void
    {
        $marks = implode(',', array_fill(0, count($from), '?'));
        $this->em->getPDO()->prepare("UPDATE enrichment_batch_item SET state=?, reason=?, finished_at=? WHERE batch_id=? AND state IN ($marks)")
            ->execute([$to, $reason, $this->clock->now(), $batchId, ...$from]);
    }

    private function items(string $batchId, string $state, int $limit): array
    {
        if ($limit < 1) return [];
        $q = $this->em->getPDO()->prepare('SELECT * FROM enrichment_batch_item WHERE batch_id=? AND state=? AND deleted=FALSE
            ORDER BY position LIMIT ' . $limit);
        $q->execute([$batchId, $state]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function state(string $batchId): ?string
    {
        $q = $this->em->getPDO()->prepare('SELECT state FROM enrichment_batch WHERE id=?');
        $q->execute([$batchId]);
        return $q->fetchColumn() ?: null;
    }

    private function count(string $batchId, string $state): int
    {
        $q = $this->em->getPDO()->prepare('SELECT COUNT(*) FROM enrichment_batch_item WHERE batch_id=? AND state=? AND deleted=FALSE');
        $q->execute([$batchId, $state]);
        return (int) $q->fetchColumn();
    }

    private function globalRunning(): int
    {
        return (int) $this->em->getPDO()->query("SELECT COUNT(*) FROM enrichment_batch_item WHERE state='running' AND deleted=FALSE")
            ->fetchColumn();
    }

    /** Recovery for runs whose self-scheduled step was lost (deploy, crash). */
    public function stale(): array
    {
        $q = $this->em->getPDO()->prepare("SELECT b.id FROM enrichment_batch b WHERE b.deleted=FALSE AND b.completed_at IS NULL
            AND (b.stepped_at IS NULL OR b.stepped_at<?) AND (b.state<>'paused' OR EXISTS (SELECT 1 FROM enrichment_batch_item i
            WHERE i.batch_id=b.id AND i.state='running' AND i.deleted=FALSE)) ORDER BY b.stepped_at LIMIT 50");
        $q->execute([(new \DateTimeImmutable($this->clock->now(), new \DateTimeZone('UTC')))->modify('-60 seconds')->format('Y-m-d H:i:s')]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }
}
