<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services;

use Espo\Core\Exceptions\Error as EspoError;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * HTTP client for the Google Meet REST API v2.
 * Uses cURL with Bearer token authentication.
 */
class GoogleMeetApiClient
{
    private const BASE_URL = 'https://meet.googleapis.com/v2/';

    public function __construct(
        private LoggerInterface $log
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSpace(string $accessToken, string $spaceNameOrCode): array
    {
        $path = 'spaces/' . urlencode($spaceNameOrCode);

        return $this->request($accessToken, $path);
    }

    /**
     * @param array<string, mixed> $params  Query params (e.g. filter, pageSize, pageToken).
     * @return array<string, mixed>
     */
    public function listConferenceRecords(string $accessToken, array $params = []): array
    {
        return $this->request($accessToken, 'conferenceRecords', $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConferenceRecord(string $accessToken, string $conferenceRecordId): array
    {
        $path = 'conferenceRecords/' . urlencode($conferenceRecordId);

        return $this->request($accessToken, $path);
    }

    /**
     * @param array<string, mixed> $params  Query params (e.g. pageSize, pageToken).
     * @return array<string, mixed>
     */
    public function listParticipants(string $accessToken, string $conferenceRecordId, array $params = []): array
    {
        $path = 'conferenceRecords/' . urlencode($conferenceRecordId) . '/participants';

        return $this->request($accessToken, $path, $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function getParticipant(string $accessToken, string $conferenceRecordId, string $participantId): array
    {
        $path = 'conferenceRecords/' . urlencode($conferenceRecordId)
            . '/participants/' . urlencode($participantId);

        return $this->request($accessToken, $path);
    }

    /**
     * @param array<string, mixed> $params  Query params (e.g. pageSize, pageToken).
     * @return array<string, mixed>
     */
    public function listTranscripts(string $accessToken, string $conferenceRecordId, array $params = []): array
    {
        $path = 'conferenceRecords/' . urlencode($conferenceRecordId) . '/transcripts';

        return $this->request($accessToken, $path, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function listTranscriptEntries(
        string $accessToken,
        string $conferenceRecordId,
        string $transcriptId,
        array $params = [],
    ): array {
        $path = 'conferenceRecords/' . urlencode($conferenceRecordId)
            . '/transcripts/' . urlencode($transcriptId)
            . '/entries';

        return $this->request($accessToken, $path, $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTranscriptEntry(
        string $accessToken,
        string $conferenceRecordId,
        string $transcriptId,
        string $entryId,
    ): array {
        $path = 'conferenceRecords/' . urlencode($conferenceRecordId)
            . '/transcripts/' . urlencode($transcriptId)
            . '/entries/' . urlencode($entryId);

        return $this->request($accessToken, $path);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function request(string $accessToken, string $path, array $params = []): array
    {
        $url = self::BASE_URL . ltrim($path, '/');

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $this->log->debug("GoogleMeetApiClient: GET {$url}");

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

        curl_close($ch);

        $this->log->debug(
            "GoogleMeetApiClient: Response httpCode={$httpCode}, time={$totalTime}s, "
            . "responseLen=" . ($response !== false ? strlen($response) : 'FALSE')
        );

        if ($response === false) {
            throw new RuntimeException(
                "GoogleMeetApiClient: cURL error for GET {$url}: {$curlError}"
            );
        }

        $result = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return is_array($result) ? $result : [];
        }

        $reason = "HTTP {$httpCode} error from Google Meet API.";

        if (is_array($result) && isset($result['error']['message'])) {
            $reason = $result['error']['message'];
        }

        $this->log->debug("GoogleMeetApiClient: ERROR response body=" . substr($response, 0, 500));

        throw new EspoError($reason);
    }
}
