<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Log;
use Throwable;

/**
 * Best-effort fixed-window rate limiter for the public ingest endpoint.
 *
 * No rate-limiting primitive exists anywhere in this codebase; the only
 * shared cache is the file-backed {@see DataCache} (data/cache/application).
 * Counters are therefore:
 *
 *   - NON-ATOMIC — concurrent requests can undercount (read-modify-write).
 *   - NON-DURABLE — wiped on every rebuild/cache clear.
 *   - SLOW-ish   — one file read + write per request, potentially on NFS.
 *
 * That is acceptable: this is coarse abuse damping for a public endpoint,
 * not a billing-grade quota. If precise limiting is ever needed, swap the
 * backend for Redis/APCu behind the same interface.
 *
 * Window key layout (DataCache keys allow only [a-zA-Z0-9_/-]; raw IPs
 * would throw "Bad cache key", hence the md5):
 *   trackingIngest/src-{sourceId}-{epochMinute}
 *   trackingIngest/ip-{md5(ip)}-{epochMinute}
 *
 * The previous window's file is cleared opportunistically on rollover so
 * the cache dir doesn't accumulate one file per minute forever.
 */
class RateLimiter
{
    /** Hard per-client-IP cap for public (unsigned) sources. */
    public const IP_LIMIT_PER_MINUTE = 120;

    private const KEY_PREFIX = 'trackingIngest';

    public function __construct(
        private DataCache $dataCache,
        private Log $log,
    ) {}

    /**
     * Whether this request is within the source's own per-minute budget.
     *
     * @param int $limitPerMinute 0 (or negative) = unlimited.
     */
    public function allowSource(string $sourceId, int $limitPerMinute, ?int $now = null): bool
    {
        if ($limitPerMinute <= 0) {
            return true;
        }

        return $this->bump('src-' . $sourceId, $limitPerMinute, $now ?? time());
    }

    /**
     * Whether this request is within the per-client-IP budget.
     * Applied to public (unsigned) sources only.
     */
    public function allowIp(?string $ip, ?int $now = null): bool
    {
        if ($ip === null || $ip === '') {
            // No resolvable client IP — don't hard-fail on infra quirks;
            // the per-source limit still applies.
            return true;
        }

        return $this->bump('ip-' . md5($ip), self::IP_LIMIT_PER_MINUTE, $now ?? time());
    }

    private function bump(string $subject, int $limit, int $now): bool
    {
        $minute = intdiv($now, 60);
        $key = self::KEY_PREFIX . '/' . $subject . '-' . $minute;

        try {
            $data = $this->dataCache->tryGet($key);

            $count = is_array($data) && isset($data['n']) && is_int($data['n'])
                ? $data['n']
                : 0;

            if ($count >= $limit) {
                return false;
            }

            $this->dataCache->store($key, ['n' => $count + 1]);

            // Opportunistic cleanup of the previous window.
            if ($count === 0) {
                $this->dataCache->clear(self::KEY_PREFIX . '/' . $subject . '-' . ($minute - 1));
            }
        } catch (Throwable $e) {
            // Cache trouble must never take the ingest endpoint down.
            $this->log->warning('TrackingEvent RateLimiter: cache error — ' . $e->getMessage());

            return true;
        }

        return true;
    }
}
