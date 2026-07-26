<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Log;
use Throwable;

/**
 * Best-effort fixed-window rate limiter (duplicated pattern from FeatureTrackingEvent).
 * Keys: journeyActions/{subject}-{epochMinute}
 */
class JourneyRateLimiter
{
    public const ACTIONS_PER_MINUTE_DEFAULT = 120;
    public const ENROLLMENTS_PER_MINUTE_DEFAULT = 300;

    private const KEY_PREFIX = 'journeyActions';

    public function __construct(
        private DataCache $dataCache,
        private Log $log,
    ) {}

    public function allowActions(string $tenantId, int $limitPerMinute = self::ACTIONS_PER_MINUTE_DEFAULT, ?int $now = null): bool
    {
        if ($limitPerMinute <= 0) {
            return true;
        }

        return $this->bump('act-' . $tenantId, $limitPerMinute, $now ?? time());
    }

    public function allowEnrollments(string $tenantId, int $limitPerMinute = self::ENROLLMENTS_PER_MINUTE_DEFAULT, ?int $now = null): bool
    {
        if ($limitPerMinute <= 0) {
            return true;
        }

        return $this->bump('enr-' . $tenantId, $limitPerMinute, $now ?? time());
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

            if ($count === 0) {
                $this->dataCache->clear(self::KEY_PREFIX . '/' . $subject . '-' . ($minute - 1));
            }
        } catch (Throwable $e) {
            $this->log->warning('JourneyRateLimiter: cache error — ' . $e->getMessage());

            return true;
        }

        return true;
    }
}
