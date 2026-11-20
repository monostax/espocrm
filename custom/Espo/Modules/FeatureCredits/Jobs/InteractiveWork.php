<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Enrichment\ExecutionRouter;
use Espo\Modules\FeatureCredits\Verification\{BatchAdmissions, Execution, Kind};
use Espo\ORM\EntityManager;
use PDO;

/** Fast consumers of the same committed outbox the scheduled recovery jobs use.
 * Execution still claims and settles under the wallet lock; supplier I/O runs outside that lock.
 */
final class InteractiveWork
{
    public function __construct(private EntityManager $em, private Clock $clock, private ExecutionRouter $enrich,
        private Execution $verify, private BatchAdmissions $batches, private Config $config) {}

    public function tick(string $lane): bool
    {
        $pdo = $this->em->getPDO();
        $now = $this->clock->now();
        if ($lane === 'admissions') {
            $this->batches->run();
            return false;
        }
        $row = self::next($pdo, $lane, $now);
        if (!$row) return false;
        $this->config->update(); // Respect changes to admin limits and kill switches before processing.
        if (str_starts_with($lane,'enrich-')) $this->enrich->run($row['tenant_id'],$row['id']);
        else $this->verify->run($row['tenant_id'],$row['id']);
        return true;
    }

    /** Stable partitions distribute unrelated IDs without concurrent jobs for a quote's shared budget. */
    public static function next(PDO $pdo, string $lane, string $now): ?array
    {
        if (!in_array($lane,['enrich-a','enrich-b','phone-a','phone-b','email'],true)) {
            throw new \InvalidArgumentException('Unknown credit worker lane.');
        }
        $prefix = str_ends_with($lane,'-a') ? '<' : '>=';
        if (str_starts_with($lane,'enrich-')) {
            // Start fresh quotes only. Leave partially executed waterfalls and discovery continuations to their owner.
            $q = $pdo->prepare("SELECT q.id,q.tenant_id FROM credit_reservation r
                JOIN enrichment_quote q ON q.usage_id=r.usage_id AND q.tenant_id=r.tenant_id
                JOIN credit_usage u ON u.id=r.usage_id AND u.tenant_id=r.tenant_id
                WHERE r.state='held' AND r.deleted=FALSE AND u.state='admitted' AND u.deleted=FALSE AND q.deleted=FALSE
                AND SUBSTR(q.id,1,1) $prefix '8'
                AND NOT EXISTS (SELECT 1 FROM enrichment_attempt a WHERE a.quote_id=q.id)
                AND NOT EXISTS (SELECT 1 FROM enrichment_discovery_call d WHERE d.quote_id=q.id)
                ORDER BY q.admitted_at,q.id LIMIT 1");
            $q->execute();
        } else {
            $filter = $lane === 'email' ? 'o.verification_kind=?' : "o.verification_kind<>? AND SUBSTR(o.id,1,1) $prefix '8'";
            $q = $pdo->prepare("SELECT o.id,o.tenant_id FROM phone_verification_operation o
                JOIN credit_usage u ON u.id=o.usage_id AND u.tenant_id=o.tenant_id
                WHERE o.deleted=FALSE AND u.deleted=FALSE AND u.state='admitted' AND $filter
                AND (o.state='admitted' OR (o.state='awaiting' AND o.next_poll_at<=? AND o.poll_count<10))
                ORDER BY o.admitted_at,o.id LIMIT 1");
            $q->execute([Kind::EMAIL,$now]);
        }
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
