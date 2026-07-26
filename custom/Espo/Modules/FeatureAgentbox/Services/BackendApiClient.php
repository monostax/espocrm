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

namespace Espo\Modules\FeatureAgentbox\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;

/**
 * HTTP client for Monostax backend agentbox skills API.
 * Forwards the caller's Espo auth cookies and site Origin so backend
 * crmAuthentication + resolveCrmTenantScope succeed.
 */
class BackendApiClient
{
    public function __construct(
        private Config $config,
        private Log $log
    ) {}

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body JSON-serializable payload
     * @return array{status:int, body:?array, raw:string}
     * @throws Error
     */
    public function request(
        string $method,
        string $path,
        string $authToken,
        string $authTokenSecret,
        array $query = [],
        ?array $body = null
    ): array {
        $baseUrl = $this->getBackendBaseUrl();
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $origin = $this->getOrigin();
        $cookie = 'auth-token=' . rawurlencode($authToken) .
            '; auth-token-secret=' . rawurlencode($authTokenSecret);

        $headers = [
            'Accept: application/json',
            'Origin: ' . $origin,
            'Cookie: ' . $cookie,
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new Error('Failed to initialize backend HTTP client.');
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER => false,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new Error('Failed to encode backend request body.');
            }
            $opts[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            $this->log->error('BackendApiClient request failed: ' . $error, [
                'method' => $method,
                'path' => $path,
                'errno' => $errno,
            ]);
            throw new Error('Backend request failed: ' . ($error ?: 'unknown transport error'));
        }

        $decoded = null;
        if ($raw !== '' && $status !== 204) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) && $status < 400) {
                throw new Error('Backend returned non-JSON response.');
            }
        }

        if ($status >= 400) {
            $message = is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : ('Backend error HTTP ' . $status);
            throw new Error($message, $status);
        }

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => (string) $raw,
        ];
    }

    private function getBackendBaseUrl(): string
    {
        $fromEnv = getenv('MONOSTAX_BACKEND_URL');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }

        $fromConfig = $this->config->get('monostaxBackendUrl');
        if (is_string($fromConfig) && $fromConfig !== '') {
            return rtrim($fromConfig, '/');
        }

        // Same-namespace service DNS (tenant cluster).
        return 'http://backend-service:8181';
    }

    private function getOrigin(): string
    {
        $siteUrl = (string) ($this->config->get('siteUrl') ?? '');
        if ($siteUrl === '') {
            throw new Error('CRM siteUrl is not configured; cannot authenticate to backend.');
        }

        $parts = parse_url($siteUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new Error('CRM siteUrl is invalid; cannot authenticate to backend.');
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
