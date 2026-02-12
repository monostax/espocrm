<?php

namespace Espo\Modules\PackEnterprise\Core\GoogleCalendar\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Standalone HTTP client for Google Calendar API.
 * Uses a pre-refreshed bearer token (from TokensProvider) instead of extending OAuth2Abstract.
 */
class GoogleCalendarClient
{
    private const BASE_URL = 'https://www.googleapis.com/calendar/v3/';

    private Client $httpClient;
    private string $accessToken;
    private LoggerInterface $log;

    public function __construct(string $accessToken, LoggerInterface $log)
    {
        $this->accessToken = $accessToken;
        $this->log = $log;

        $this->httpClient = new Client([
            'base_uri' => self::BASE_URL,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getCalendarList(array $params = []): array
    {
        $defaultParams = [
            'maxResults' => 50,
            'minAccessRole' => 'owner',
        ];

        $params = array_merge($defaultParams, $params);

        return $this->request('GET', 'users/me/calendarList', $params);
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getCalendarInfo(string $calendarId)
    {
        try {
            return $this->request('GET', 'calendars/' . urlencode($calendarId));
        } catch (\Exception $e) {
            $this->log->error('MsxGoogleCalendar: getCalendarInfo error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getEventList(string $calendarId, array $params = []): array
    {
        $defaultParams = [
            'maxResults' => 10,
            'alwaysIncludeEmail' => 'true',
        ];

        $params = array_merge($defaultParams, $params);

        try {
            return $this->request('GET', 'calendars/' . urlencode($calendarId) . '/events', $params);
        } catch (\Exception $e) {
            $result = [
                'success' => false,
            ];

            if ($e->getCode() == 400 || $e->getCode() == 410) {
                $result['action'] = 'resetToken';
            }

            $this->log->error('MsxGoogleCalendar: getEventList error: ' . $e->getMessage());

            return $result;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getEventInstances(string $calendarId, string $eventId, array $params = []): array
    {
        $defaultParams = [
            'maxResults' => 10,
            'alwaysIncludeEmail' => 'true',
        ];

        $params = array_merge($defaultParams, $params);

        try {
            return $this->request(
                'GET',
                'calendars/' . urlencode($calendarId) . '/events/' . urlencode($eventId) . '/instances',
                $params
            );
        } catch (\Exception $e) {
            $result = [
                'success' => false,
            ];

            if ($e->getCode() == 400 || $e->getCode() == 410) {
                $result['action'] = 'resetToken';
            } else if ($e->getCode() == 403 || $e->getCode() == 404) {
                $result['action'] = 'deleteEvent';
            }

            $this->log->error('MsxGoogleCalendar: getEventInstances error: ' . $e->getMessage());

            return $result;
        }
    }

    public function deleteEvent(string $calendarId, string $eventId): bool
    {
        try {
            $this->request('DELETE', 'calendars/' . urlencode($calendarId) . '/events/' . urlencode($eventId));

            return true;
        } catch (\Exception $e) {
            $this->log->error('MsxGoogleCalendar: deleteEvent error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return array<string, mixed>|false
     */
    public function retrieveEvent(string $calendarId, string $eventId)
    {
        try {
            return $this->request(
                'GET',
                'calendars/' . urlencode($calendarId) . '/events/' . urlencode($eventId)
            );
        } catch (\Exception $e) {
            $this->log->error('MsxGoogleCalendar: retrieveEvent error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|false
     */
    public function insertEvent(string $calendarId, array $event)
    {
        try {
            return $this->request(
                'POST',
                'calendars/' . urlencode($calendarId) . '/events',
                null,
                $event
            );
        } catch (\Exception $e) {
            $this->log->error('MsxGoogleCalendar: insertEvent error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed> $modification
     * @return array<string, mixed>|false
     */
    public function updateEvent(string $calendarId, string $eventId, array $modification)
    {
        try {
            return $this->request(
                'PUT',
                'calendars/' . urlencode($calendarId) . '/events/' . urlencode($eventId),
                null,
                $modification
            );
        } catch (\Exception $e) {
            $this->log->error('MsxGoogleCalendar: updateEvent error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed>|null $queryParams
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $uri,
        ?array $queryParams = null,
        ?array $body = null
    ): array {
        $options = [];

        if ($queryParams) {
            $options['query'] = $queryParams;
        }

        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $uri, $options);
        } catch (GuzzleException $e) {
            throw new RuntimeException('MsxGoogleCalendar HTTP error: ' . $e->getMessage(), 0, $e);
        }

        $code = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        if ($code >= 200 && $code < 300) {
            if (empty($responseBody)) {
                return [];
            }

            $result = json_decode($responseBody, true);

            return is_array($result) ? $result : [];
        }

        $reason = '';
        $decoded = json_decode($responseBody, true);

        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $reason = ' Reason: ' . $decoded['error']['message'];
        }

        throw new RuntimeException(
            "MsxGoogleCalendar: Error after requesting $method $uri. Code: $code.$reason",
            $code
        );
    }
}
