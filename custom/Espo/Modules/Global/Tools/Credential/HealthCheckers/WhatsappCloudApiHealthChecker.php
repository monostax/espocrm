<?php

namespace Espo\Modules\Global\Tools\Credential\HealthCheckers;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use stdClass;

/**
 * Health checker for WhatsApp Cloud API credentials.
 *
 * Verifies the credential by calling the Meta Graph API to check
 * the WhatsApp Business Account (WABA) is accessible with the current access token.
 * This is a WABA-level check (not phone-number-level). Phone-number-level health
 * checks are performed at the integration level (e.g. ChatwootInboxIntegration).
 */
class WhatsappCloudApiHealthChecker implements HealthCheckerInterface
{
    private const DEFAULT_API_VERSION = 'v21.0';
    private const GRAPH_API_BASE = 'https://graph.facebook.com';
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private Log $log,
    ) {}

    public function check(stdClass $resolvedConfig, Entity $credential, Entity $credentialType): HealthCheckResult
    {
        $accessToken = $resolvedConfig->accessToken ?? null;
        $businessAccountId = $resolvedConfig->businessAccountId ?? null;
        $apiVersion = $resolvedConfig->apiVersion ?? self::DEFAULT_API_VERSION;

        if (!$accessToken) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'No access token available. Connect an OAuth account or provide a token.',
            );
        }

        if (!$businessAccountId) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Business Account ID is not configured.',
            );
        }

        $url = self::GRAPH_API_BASE . "/{$apiVersion}/{$businessAccountId}?fields=id,name";

        $startTime = hrtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
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
                "Connection to Meta Graph API failed: {$error}",
                $responseTimeMs,
            );
        }

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $returnedId = $data['id'] ?? null;

            if ($returnedId && $returnedId === $businessAccountId) {
                $wabaName = $data['name'] ?? 'Unknown';

                return new HealthCheckResult(
                    HealthCheckResult::STATUS_HEALTHY,
                    "WhatsApp Business Account verified: {$wabaName} ({$responseTimeMs}ms)",
                    $responseTimeMs,
                );
            }

            return new HealthCheckResult(
                HealthCheckResult::STATUS_HEALTHY,
                "API responded OK but Business Account ID mismatch ({$responseTimeMs}ms)",
                $responseTimeMs,
            );
        }

        // Parse error from Meta API.
        $errorMessage = "HTTP {$httpCode}";
        $data = json_decode($response, true);

        if (!empty($data['error']['message'])) {
            $errorMessage .= ': ' . $data['error']['message'];
        }

        if ($httpCode === 401 || $httpCode === 190) {
            $errorMessage = "Access token is invalid or expired. {$errorMessage}";
        }

        return new HealthCheckResult(
            HealthCheckResult::STATUS_UNHEALTHY,
            $errorMessage . " ({$responseTimeMs}ms)",
            $responseTimeMs,
        );
    }
}
