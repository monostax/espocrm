<?php

namespace Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckers;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use stdClass;

/**
 * Generic HTTP-based health checker.
 *
 * Reads healthCheckConfig from the CredentialType and performs an HTTP request.
 *
 * healthCheckConfig format:
 * {
 *   "url": "https://api.example.com/health",
 *   "method": "GET",
 *   "headers": {"Authorization": "Bearer {{accessToken}}"},
 *   "expectedStatus": 200,
 *   "timeoutSeconds": 10
 * }
 *
 * The {{fieldName}} placeholders in url and headers are substituted
 * from the resolved config values.
 */
class GenericHttpHealthChecker implements HealthCheckerInterface
{
    public function __construct(
        private Log $log,
    ) {}

    public function check(stdClass $resolvedConfig, Entity $credential, Entity $credentialType): HealthCheckResult
    {
        $configRaw = $credentialType->get('healthCheckConfig');

        if (!$configRaw) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNKNOWN,
                'No healthCheckConfig defined on credential type.',
            );
        }

        $healthConfig = is_string($configRaw)
            ? json_decode($configRaw, true)
            : (array) $configRaw;

        if (empty($healthConfig) || empty($healthConfig['url'])) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNKNOWN,
                'Invalid healthCheckConfig: missing url.',
            );
        }

        $url = $this->interpolate($healthConfig['url'], $resolvedConfig);
        $method = strtoupper($healthConfig['method'] ?? 'GET');
        $expectedStatus = (int) ($healthConfig['expectedStatus'] ?? 200);
        $timeoutSeconds = (int) ($healthConfig['timeoutSeconds'] ?? 10);
        $headers = [];

        if (!empty($healthConfig['headers']) && is_array($healthConfig['headers'])) {
            foreach ($healthConfig['headers'] as $key => $value) {
                $headers[] = $key . ': ' . $this->interpolate($value, $resolvedConfig);
            }
        }

        $startTime = hrtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $endTime = hrtime(true);
        $responseTimeMs = (int) (($endTime - $startTime) / 1_000_000);

        if ($error) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                "Connection failed: {$error}",
                $responseTimeMs,
            );
        }

        if ($httpCode === $expectedStatus) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_HEALTHY,
                "HTTP {$httpCode} OK ({$responseTimeMs}ms)",
                $responseTimeMs,
            );
        }

        return new HealthCheckResult(
            HealthCheckResult::STATUS_UNHEALTHY,
            "Expected HTTP {$expectedStatus}, got {$httpCode} ({$responseTimeMs}ms)",
            $responseTimeMs,
        );
    }

    /**
     * Replace {{fieldName}} placeholders with values from resolved config.
     */
    private function interpolate(string $template, stdClass $config): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($config) {
            $field = $matches[1];

            if (isset($config->$field)) {
                return (string) $config->$field;
            }

            return $matches[0]; // Leave placeholder as-is if not found.
        }, $template);
    }
}
