<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\{MetadataProvider, QueueUtil, ScheduleUtil};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use PDO;

class CreditQueueRecovery extends QueueUtil
{
    private const READY_SECONDS = 90;

    public function __construct(Config $config, private EntityManager $em, ScheduleUtil $scheduleUtil, MetadataProvider $metadataProvider)
    {
        parent::__construct($config, $em, $scheduleUtil, $metadataProvider);
    }

    public function markJobsFailed(): void
    {
        // Runs on the cron tick itself, even if both credit dispatchers lost their child processes.
        self::requeueUnstarted($this->em->getPDO(), gmdate('Y-m-d H:i:s', time() - self::READY_SECONDS));
        parent::markJobsFailed();
    }

    public static function requeueUnstarted(PDO $pdo, string $before): void
    {
        $query = $pdo->prepare("UPDATE job SET status='Pending', started_at=NULL
            WHERE deleted=FALSE AND status='Ready' AND pid IS NULL AND executed_at IS NULL AND started_at < ?
            AND (class_name IN (?, ?, ?) OR scheduled_job_id IN
                (SELECT id FROM scheduled_job WHERE deleted=FALSE AND job IN ('DispatchEnrichment', 'DispatchPhoneVerification'))
                OR ((target_group LIKE 'enrichment-%' OR target_group LIKE 'phone-verification-%')
                    AND scheduled_job_id IN (SELECT id FROM scheduled_job WHERE deleted=FALSE AND job='ProcessJobGroup')))");
        $query->execute([$before, RunEnrichment::class, RunPhoneVerification::class, RunEnrichmentBatch::class]);
    }
}
