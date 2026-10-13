<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * Durable intent and fenced worker leases. Construct with the authoritative
 * EntityManager's PDO, never a separate connection for mutation-side append.
 * Delivery/ACL evaluation deliberately happens outside database transactions.
 */
final class Store
{
    public const RETENTION_SECONDS = 7 * 24 * 60 * 60;
    public const FRESHNESS_SECONDS = 5;
    private const LEASE_SECONDS = 30;

    public function __construct(private PDO $pdo)
    {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Outbox storage requires exception-mode PDO.');
        }
    }

    /** The caller owns the mutation transaction and must propagate every failure. */
    public function append(Intent $intent, DateTimeImmutable $now): string
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Live-record intent requires the mutation transaction.');
        }

        // Espo's default entity ID column is 17 characters wide.
        $id = substr(bin2hex(random_bytes(9)), 0, 17);
        $time = self::time($now);
        $statement = $this->pdo->prepare('INSERT INTO live_record_outbox
            (id, tenant_id, intent, created_at, due_at, attempts)
            VALUES (?, ?, ?, ?, ?, 0)');
        $statement->execute([$id, $intent->tenantId, $intent->encode(), $time, $time]);

        return $id;
    }

    /** Expire stale hints in bounded batches. They must never become catch-up events. */
    public function expire(DateTimeImmutable $now): int
    {
        $this->requireAutocommit();
        $time = self::time($now);
        $cutoff = self::time($now->modify('-' . self::FRESHNESS_SECONDS . ' seconds'));
        // Advisory read, followed by a conditional primary-key update. Avoid
        // range locks blocking producers while cleaning an old backlog.
        $query = $this->pdo->prepare('SELECT id FROM live_record_outbox
            WHERE completed_at IS NULL AND created_at <= ? AND (lease_until IS NULL OR lease_until <= ?)
            ORDER BY id LIMIT 1000');
        $query->execute([$cutoff, $time]);
        $ids = $query->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return 0;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $update = $this->pdo->prepare("UPDATE live_record_outbox SET completed_at = ?,
            lease_token = NULL, lease_until = NULL, failure_code = 'expired'
            WHERE id IN ($marks) AND completed_at IS NULL AND created_at <= ?
                AND (lease_until IS NULL OR lease_until <= ?)");
        $update->execute([$time, ...$ids, $cutoff, $time]);
        return $update->rowCount();
    }

    /**
     * Compare-and-set claim: concurrent workers cannot own the same lease.
     * Candidate reads are advisory; every ownership transition is atomic SQL.
     * Return raw intent so malformed work can be failed/retried after claiming.
     *
     * @return Lease[]
     */
    public function claim(DateTimeImmutable $now, int $limit = 100): array
    {
        $this->requireAutocommit();
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Invalid outbox batch size.');
        }
        $time = self::time($now);
        $cutoff = self::time($now->modify('-' . self::FRESHNESS_SECONDS . ' seconds'));
        $query = $this->pdo->prepare('SELECT id, tenant_id, intent, attempts, created_at FROM live_record_outbox
            WHERE completed_at IS NULL AND due_at <= ? AND (lease_until IS NULL OR lease_until <= ?) AND created_at > ?
            ORDER BY due_at, id LIMIT ' . $limit);
        $query->execute([$time, $time, $cutoff]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $leases = [];
        foreach ($rows as $row) {
            $token = bin2hex(random_bytes(32));
            $update = $this->pdo->prepare('UPDATE live_record_outbox
                SET lease_token = ?, lease_until = ?, attempts = attempts + 1
                WHERE id = ? AND completed_at IS NULL AND due_at <= ?
                    AND (lease_until IS NULL OR lease_until <= ?)');
            $update->execute([$token, self::time($now->modify('+' . self::LEASE_SECONDS . ' seconds')),
                $row['id'], $time, $time]);
            if ($update->rowCount() !== 1) {
                continue;
            }
            // attempts may have changed since the candidate read (another worker
            // can fail and release it). Read the attempt owned by this token.
            $owned = $this->pdo->prepare('SELECT attempts FROM live_record_outbox WHERE id = ? AND lease_token = ?');
            $owned->execute([$row['id'], $token]);
            $attempts = $owned->fetchColumn();
            if ($attempts === false) {
                continue;
            }
            $leases[] = new Lease($row['id'], $row['tenant_id'], $token, $row['intent'], (int) $attempts, $row['created_at']);
        }

        return $leases;
    }

    /** Acknowledge only after all intended routing/delivery work has succeeded. */
    public function complete(Lease $lease, DateTimeImmutable $now): bool
    {
        $this->requireAutocommit();
        $query = $this->pdo->prepare('UPDATE live_record_outbox
            SET completed_at = ?, lease_token = NULL, lease_until = NULL, failure_code = NULL
            WHERE id = ? AND tenant_id = ? AND lease_token = ? AND lease_until > ? AND completed_at IS NULL');
        $time = self::time($now);
        $query->execute([$time, $lease->id, $lease->tenantId, $lease->token, $time]);

        return $query->rowCount() === 1;
    }

    /** Retry only while the hint is fresh; expire() retires old failed work. */
    public function fail(Lease $lease, DateTimeImmutable $now, string $code): bool
    {
        $this->requireAutocommit();
        // Store categories, never exception messages, grants or record bodies.
        if (!in_array($code, ['authorization_unavailable', 'bridge_unavailable', 'malformed_intent', 'internal_error'], true)) {
            throw new InvalidArgumentException('Unsupported outbox failure category.');
        }
        $delay = self::retryDelayMilliseconds($lease->attempts);
        // Persisted timestamps have second precision. Round up to avoid an early retry.
        $due = $now->modify('+' . (int) ceil($delay / 1000) . ' seconds');
        $query = $this->pdo->prepare('UPDATE live_record_outbox
            SET due_at = ?, lease_token = NULL, lease_until = NULL, failure_code = ?
            WHERE id = ? AND tenant_id = ? AND lease_token = ? AND lease_until > ? AND completed_at IS NULL');
        $query->execute([self::time($due), $code, $lease->id, $lease->tenantId, $lease->token, self::time($now)]);

        return $query->rowCount() === 1;
    }

    public static function retryDelayMilliseconds(int $attempts): int
    {
        if ($attempts < 1) {
            throw new InvalidArgumentException('Attempt number must be positive.');
        }
        $ceiling = min(60_000, 1000 * (2 ** min($attempts - 1, 6)));

        // Equal jitter, bounded below by D20's one-second initial retry.
        return random_int(max(1000, (int) ($ceiling / 2)), $ceiling);
    }

    public function purgeCompleted(DateTimeImmutable $now): int
    {
        $this->requireAutocommit();
        $query = $this->pdo->prepare('DELETE FROM live_record_outbox WHERE completed_at < ?');
        $query->execute([self::time($now->modify('-' . self::RETENTION_SECONDS . ' seconds'))]);

        return $query->rowCount();
    }

    /** Aggregate operational data only; no record IDs or bodies as metric labels. */
    public function pendingMetrics(DateTimeImmutable $now): array
    {
        $query = $this->pdo->query('SELECT COUNT(*) AS pending, MIN(created_at) AS oldest,
            COALESCE(SUM(attempts), 0) AS attempts FROM live_record_outbox WHERE completed_at IS NULL');
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $oldest = $row['oldest'] === null ? null : new DateTimeImmutable($row['oldest'], new DateTimeZone('UTC'));

        return [
            'pending' => (int) $row['pending'],
            'attempts' => (int) $row['attempts'],
            'oldestAgeSeconds' => $oldest ? max(0, $now->getTimestamp() - $oldest->getTimestamp()) : 0,
        ];
    }

    private function requireAutocommit(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Outbox worker operations must not share a mutation transaction.');
        }
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
