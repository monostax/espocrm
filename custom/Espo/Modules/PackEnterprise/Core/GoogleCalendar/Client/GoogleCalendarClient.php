<?php

namespace Espo\Modules\PackEnterprise\Core\GoogleCalendar\Client;

use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Standalone HTTP client for Google Calendar API v3.
 * Replaces the original Google\Clients\Google (OAuth2Abstract) + Google\Clients\Calendar
 * hierarchy with a simpler class that accepts a plain access token from TokensProvider.
 */
class GoogleCalendarClient
{
    private const BASE_URL = 'https://www.googleapis.com/calendar/v3/';

    private string $accessToken;
    private LoggerInterface $log;

    public function __construct(string $accessToken, LoggerInterface $log)
    {
        $this->accessToken = $accessToken;
        $this->log = $log;
    }

    /**
     * List calendars the authenticated user has access to.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getCalendarList(array $params = []): array
    {
        $this->log->debug("MsxGoogleCalendar [Client/getCalendarList]: params=" . json_encode($params));

        $defaultParams = [
            'maxResults' => 50,
            'minAccessRole' => 'owner',
        ];

        $params = array_merge($defaultParams, $params);

        $url = $this->buildUrl('users/me/calendarList');

        return $this->request($url, $params, 'GET');
    }

    /**
     * Get calendar metadata (including timezone).
     *
     * @return array<string, mixed>|false
     */
    public function getCalendarInfo(string $calendarId)
    {
        $this->log->debug("MsxGoogleCalendar [Client/getCalendarInfo]: calendarId={$calendarId}");

        $url = $this->buildUrl('calendars/' . urlencode($calendarId));

        try {
            $result = $this->request($url, null, 'GET');
            $this->log->debug("MsxGoogleCalendar [Client/getCalendarInfo]: OK, timeZone=" . ($result['timeZone'] ?? 'N/A'));
            return $result;
        } catch (Exception $e) {
            $this->log->error('MsxGoogleCalendar [Client/getCalendarInfo]: error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * List events from a calendar.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getEventList(string $calendarId, array $params = []): array
    {
        $this->log->debug("MsxGoogleCalendar [Client/getEventList]: calendarId={$calendarId}, params=" . json_encode($params));

        $defaultParams = [
            'maxResults' => 10,
            'alwaysIncludeEmail' => 'true',
        ];

        $params = array_merge($defaultParams, $params);

        $url = $this->buildUrl('calendars/' . urlencode($calendarId) . '/events');

        try {
            $result = $this->request($url, $params, 'GET');
            $itemCount = isset($result['items']) ? count($result['items']) : 0;
            $this->log->debug("MsxGoogleCalendar [Client/getEventList]: OK, items={$itemCount}" .
                (isset($result['nextPageToken']) ? ', hasNextPage=true' : '') .
                (isset($result['nextSyncToken']) ? ', hasNextSyncToken=true' : ''));
            return $result;
        } catch (Exception $e) {
            $result = [
                'success' => false,
            ];

            $code = $e->getCode();

            if ($code == 400 || $code == 410) {
                $result['action'] = 'resetToken';
            }

            $this->log->error('MsxGoogleCalendar [Client/getEventList]: error (code=' . $code . '): ' . $e->getMessage());
            $this->log->debug('MsxGoogleCalendar [Client/getEventList]: params: ' . print_r($params, true));

            return $result;
        }
    }

    /**
     * List instances of a recurring event.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getEventInstances(string $calendarId, string $eventId, array $params = []): array
    {
        $this->log->debug("MsxGoogleCalendar [Client/getEventInstances]: calendarId={$calendarId}, eventId={$eventId}");

        $defaultParams = [
            'maxResults' => 10,
            'alwaysIncludeEmail' => 'true',
        ];

        $params = array_merge($defaultParams, $params);

        $url = $this->buildUrl(
            'calendars/' . urlencode($calendarId) . '/events/' . urlencode($eventId) . '/instances'
        );

        try {
            $result = $this->request($url, $params, 'GET');
            $itemCount = isset($result['items']) ? count($result['items']) : 0;
            $this->log->debug("MsxGoogleCalendar [Client/getEventInstances]: OK, instances={$itemCount}");
            return $result;
        } catch (Exception $e) {
            $result = [
                'success' => false,
            ];

            $code = $e->getCode();

            if ($code == 400 || $code == 410) {
                $result['action'] = 'resetToken';
            } elseif ($code == 403 || $code == 404) {
                $result['action'] = 'deleteEvent';
            }

            $this->log->error('MsxGoogleCalendar [Client/getEventInstances]: error (code=' . $code . '): ' . $e->getMessage());
            $this->log->debug('MsxGoogleCalendar [Client/getEventInstances]: params: ' . print_r($params, true));

            return $result;
        }
    }

    /**
     * Insert a new event into a calendar.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>|false
     */
    public function insertEvent(string $calendarId, array $event)
    {
        $this->log->debug("MsxGoogleCalendar [Client/insertEvent]: calendarId={$calendarId}, summary=" . ($event['summary'] ?? 'N/A'));

        $url = $this->buildUrl('calendars/' . urlencode($calendarId) . '/events');

        try {
            $result = $this->request($url, json_encode($event), 'POST', 'application/json');
            $this->log->debug("MsxGoogleCalendar [Client/insertEvent]: OK, id=" . ($result['id'] ?? 'N/A'));
            return $result;
        } catch (Exception $e) {
            $this->log->error('MsxGoogleCalendar [Client/insertEvent]: error: ' . $e->getMessage());
            $this->log->debug('MsxGoogleCalendar [Client/insertEvent]: params: ' . print_r($event, true));

            return false;
        }
    }

    /**
     * Update an existing event.
     *
     * @param array<string, mixed> $modification
     * @return array<string, mixed>|false
     */
    public function updateEvent(string $calendarId, string $eventId, array $modification)
    {
        $this->log->debug("MsxGoogleCalendar [Client/updateEvent]: calendarId={$calendarId}, eventId={$eventId}");

        $url = $this->buildUrl(
            'calendars/' . urlencode($calendarId) . '/events/' . urlencode($eventId)
        );

        try {
            $result = $this->request($url, json_encode($modification), 'PUT', 'application/json');
            $this->log->debug("MsxGoogleCalendar [Client/updateEvent]: OK");
            return $result;
        } catch (Exception $e) {
            $this->log->error('MsxGoogleCalendar [Client/updateEvent]: error: ' . $e->getMessage());
            $this->log->debug('MsxGoogleCalendar [Client/updateEvent]: params: ' . print_r($modification, true));

            return false;
        }
    }

    /**
     * Delete an event from a calendar.
     */
    public function deleteEvent(string $calendarId, string $eventId): bool
    {
        $this->log->debug("MsxGoogleCalendar [Client/deleteEvent]: calendarId={$calendarId}, eventId={$eventId}");

        $url = $this->buildUrl(
            'calendars/' . urlencode($calendarId) . '/events/' . urlencode($eventId)
        );

        try {
            $this->request($url, null, 'DELETE');
            $this->log->debug("MsxGoogleCalendar [Client/deleteEvent]: OK");
        } catch (Exception $e) {
            $this->log->error('MsxGoogleCalendar [Client/deleteEvent]: error: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Retrieve a single event by ID.
     *
     * @return array<string, mixed>|false
     */
    public function retrieveEvent(string $calendarId, string $eventId)
    {
        $this->log->debug("MsxGoogleCalendar [Client/retrieveEvent]: calendarId={$calendarId}, eventId={$eventId}");

        $url = $this->buildUrl(
            'calendars/' . urlencode($calendarId) . '/events/' . urlencode($eventId)
        );

        try {
            $result = $this->request($url, [], 'GET');
            $this->log->debug("MsxGoogleCalendar [Client/retrieveEvent]: OK, summary=" . ($result['summary'] ?? 'N/A'));
            return $result;
        } catch (Exception $e) {
            $this->log->error('MsxGoogleCalendar [Client/retrieveEvent]: error: ' . $e->getMessage());

            return false;
        }
    }

    private function buildUrl(string $path): string
    {
        return self::BASE_URL . ltrim($path, '/');
    }

    /**
     * Execute an HTTP request to the Google Calendar API.
     *
     * @param string $url
     * @param mixed $params Query params (array for GET) or JSON body (string for POST/PUT).
     * @param string $method HTTP method (GET, POST, PUT, DELETE).
     * @param string|null $contentType Content-Type header for body requests.
     * @return array<string, mixed>
     *
     * @throws RuntimeException on HTTP errors.
     */
    private function request(string $url, $params, string $method, ?string $contentType = null): array
    {
        // Log the request (mask the token in the URL for security).
        $this->log->debug("MsxGoogleCalendar [Client/request]: {$method} {$url}");

        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
        ];

        if ($method === 'GET' && is_array($params) && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if (in_array($method, ['POST', 'PUT']) && $params !== null) {
            $body = is_string($params) ? $params : json_encode($params);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

            $contentType = $contentType ?? 'application/json';
            $headers[] = 'Content-Type: ' . $contentType;
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

        curl_close($ch);

        $this->log->debug("MsxGoogleCalendar [Client/request]: Response httpCode={$httpCode}, time={$totalTime}s, " .
            "responseLen=" . ($response !== false ? strlen($response) : 'FALSE'));

        if ($response === false) {
            throw new RuntimeException(
                "MsxGoogleCalendar [Client]: cURL error for {$method} {$url}: {$curlError}"
            );
        }

        $result = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return is_array($result) ? $result : [];
        }

        $reason = '';

        if (is_array($result) && isset($result['error']['message'])) {
            $reason = ' Reason: ' . $result['error']['message'];
        }

        $this->log->debug("MsxGoogleCalendar [Client/request]: ERROR response body=" . substr($response, 0, 500));

        throw new RuntimeException(
            "MsxGoogleCalendar [Client]: HTTP {$httpCode} error for {$method} {$url}.{$reason}",
            $httpCode
        );
    }
}
