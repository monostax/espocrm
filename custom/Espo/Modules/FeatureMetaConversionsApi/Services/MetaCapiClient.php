<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Services;

use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use RuntimeException;

/**
 * Thin cURL client for Meta's Conversions API.
 *
 * Endpoint: POST https://graph.facebook.com/{apiVersion}/{datasetId}/events
 *
 * Reference:
 *   https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api
 *
 * The dataset access_token is sent in the query string (per Meta docs) NOT a Bearer header,
 * mirroring official Meta SDK behavior for the events endpoint.
 */
class MetaCapiClient
{
    private const BASE_URL = 'https://graph.facebook.com';
    private const DEFAULT_API_VERSION = 'v25.0';
    private const TIMEOUT_SECONDS = 15;
    private const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Sent as the top-level `partner_agent` body field. Identifies this
     * integration to Meta (recommended for business_messaging events).
     */
    private const PARTNER_AGENT = 'monostax';

    public function __construct(
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * Send a batch of events to a dataset.
     *
     * @param array<int, array<string, mixed>> $events  Event objects as built by EventBuilder.
     * @return SendResult
     */
    public function sendEvents(MetaCapiDataset $dataset, array $events): SendResult
    {
        if ($events === []) {
            return new SendResult(true, 0, null, null, null, null);
        }

        $datasetId = (string) $dataset->get('datasetId');
        $apiVersion = (string) ($dataset->get('apiVersion') ?: self::DEFAULT_API_VERSION);
        $testEventCode = trim((string) ($dataset->get('testEventCode') ?? ''));

        $encryptedToken = (string) ($dataset->get('accessToken') ?? '');

        if ($datasetId === '' || $encryptedToken === '') {
            throw new RuntimeException('MetaCapiDataset is missing datasetId or accessToken.');
        }

        $accessToken = $this->crypt->decrypt($encryptedToken);

        $url = sprintf(
            '%s/%s/%s/events?access_token=%s',
            self::BASE_URL,
            $apiVersion,
            rawurlencode($datasetId),
            rawurlencode($accessToken),
        );

        $body = [
            'data' => array_values($events),
            'partner_agent' => self::PARTNER_AGENT,
        ];

        if ($testEventCode !== '') {
            $body['test_event_code'] = $testEventCode;
        }

        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($jsonBody === false) {
            throw new RuntimeException('Failed to JSON-encode events payload.');
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('curl_init failed.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $jsonBody,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);

        $rawResponse = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($rawResponse === false || $curlErrno !== 0) {
            $msg = sprintf('cURL error (%d): %s', $curlErrno, $curlError);
            $this->log->error('MetaCapiClient: ' . $msg);

            return new SendResult(false, $httpStatus, null, $body, null, $msg);
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) $rawResponse, true);

        $fbtraceId = is_array($decoded) ? ($decoded['fbtrace_id'] ?? null) : null;
        $eventsReceived = is_array($decoded) ? ($decoded['events_received'] ?? null) : null;

        if ($httpStatus >= 200 && $httpStatus < 300) {
            return new SendResult(
                true,
                $httpStatus,
                $eventsReceived !== null ? (int) $eventsReceived : null,
                $body,
                $decoded,
                null,
                is_string($fbtraceId) ? $fbtraceId : null,
            );
        }

        $errorMessage = $this->extractErrorMessage($decoded) ?? ('HTTP ' . $httpStatus);

        $this->log->warning(sprintf(
            'MetaCapiClient: send failed dataset=%s status=%d error=%s',
            $datasetId,
            $httpStatus,
            $errorMessage,
        ));

        return new SendResult(
            false,
            $httpStatus,
            $eventsReceived !== null ? (int) $eventsReceived : null,
            $body,
            $decoded,
            $errorMessage,
            is_string($fbtraceId) ? $fbtraceId : null,
        );
    }

    /**
     * @param array<string, mixed>|null $decoded
     */
    private function extractErrorMessage(?array $decoded): ?string
    {
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $err = $decoded['error'];

            $parts = [];
            if (isset($err['code']))            { $parts[] = 'code=' . $err['code']; }
            if (isset($err['error_subcode']))   { $parts[] = 'subcode=' . $err['error_subcode']; }
            if (isset($err['type']))            { $parts[] = 'type=' . $err['type']; }
            if (isset($err['message']))         { $parts[] = (string) $err['message']; }

            return $parts ? implode(' | ', $parts) : null;
        }

        return null;
    }
}
