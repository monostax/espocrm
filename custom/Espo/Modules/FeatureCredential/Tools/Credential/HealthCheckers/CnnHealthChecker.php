<?php

namespace Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckers;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use stdClass;

/**
 * Health checker for Clínica nas Nuvens API credentials (code: cnn).
 *
 * Calls GET /info using:
 * - HTTP Basic auth with clientId:clientSecret
 * - Header clinicaNasNuvens-cid with clinic token
 */
class CnnHealthChecker implements HealthCheckerInterface
{
    private const DEFAULT_BASE_URL = 'https://api.clinicanasnuvens.com.br';
    private const INFO_ENDPOINT = '/info';
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private Log $log,
    ) {}

    public function check(stdClass $resolvedConfig, Entity $credential, Entity $credentialType): HealthCheckResult
    {
        $clientId = $resolvedConfig->clientId ?? null;
        $clientSecret = $resolvedConfig->clientSecret ?? null;
        $clinicCid = $resolvedConfig->clinicCid ?? null;
        $baseUrl = $resolvedConfig->baseUrl ?? self::DEFAULT_BASE_URL;

        if (!$clientId || !$clientSecret || !$clinicCid) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Missing required values: clientId, clientSecret, or clinicCid.',
            );
        }

        $url = rtrim((string) $baseUrl, '/') . self::INFO_ENDPOINT;

        $startTime = hrtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $clientSecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'clinicaNasNuvens-cid: ' . $clinicCid,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $endTime = hrtime(true);
        $responseTimeMs = (int) (($endTime - $startTime) / 1_000_000);

        if ($error) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                "Connection to Clínica nas Nuvens API failed: {$error}",
                $responseTimeMs,
            );
        }

        if ($httpCode === 200) {
            $data = json_decode((string) $response, true);
            $clinicName = null;

            if (is_array($data)) {
                $clinicName = $data['nomeFantasia'] ?? $data['razaoSocial'] ?? null;
            }

            $message = $clinicName
                ? "Clínica nas Nuvens credentials valid for '{$clinicName}' ({$responseTimeMs}ms)"
                : "Clínica nas Nuvens credentials validated successfully ({$responseTimeMs}ms)";

            return new HealthCheckResult(
                HealthCheckResult::STATUS_HEALTHY,
                $message,
                $responseTimeMs,
            );
        }

        $apiMessage = $this->extractApiMessage((string) $response);

        if ($httpCode === 401) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Unauthorized (401): invalid clientId/clientSecret.' . ($apiMessage ? " {$apiMessage}" : '') . " ({$responseTimeMs}ms)",
                $responseTimeMs,
            );
        }

        if ($httpCode === 403) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Forbidden (403): check clinicaNasNuvens-cid token and partner permissions.' . ($apiMessage ? " {$apiMessage}" : '') . " ({$responseTimeMs}ms)",
                $responseTimeMs,
            );
        }

        if ($httpCode === 404) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Not Found (404): /info endpoint not found on configured baseUrl.' . ($apiMessage ? " {$apiMessage}" : '') . " ({$responseTimeMs}ms)",
                $responseTimeMs,
            );
        }

        if ($httpCode === 429) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Rate limit exceeded (429): too many requests for this clinic token.' . ($apiMessage ? " {$apiMessage}" : '') . " ({$responseTimeMs}ms)",
                $responseTimeMs,
            );
        }

        $this->log->warning("CNN health check returned unexpected HTTP {$httpCode} for credential '{$credential->getId()}'.");

        return new HealthCheckResult(
            HealthCheckResult::STATUS_UNHEALTHY,
            "Unexpected HTTP {$httpCode}" . ($apiMessage ? ": {$apiMessage}" : '') . " ({$responseTimeMs}ms)",
            $responseTimeMs,
        );
    }

    private function extractApiMessage(string $response): ?string
    {
        if ($response === '') {
            return null;
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            return null;
        }

        if (!empty($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }

        if (!empty($data['error']) && is_string($data['error'])) {
            return $data['error'];
        }

        return null;
    }
}
