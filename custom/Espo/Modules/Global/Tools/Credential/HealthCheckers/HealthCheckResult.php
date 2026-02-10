<?php

namespace Espo\Modules\Global\Tools\Credential\HealthCheckers;

/**
 * DTO representing the result of a credential health check.
 */
class HealthCheckResult
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_UNHEALTHY = 'unhealthy';
    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?int $responseTimeMs = null,
    ) {}

    public function toStdClass(): \stdClass
    {
        $result = new \stdClass();
        $result->status = $this->status;
        $result->message = $this->message;
        $result->responseTimeMs = $this->responseTimeMs;
        $result->checkedAt = date('Y-m-d H:i:s');

        return $result;
    }
}
