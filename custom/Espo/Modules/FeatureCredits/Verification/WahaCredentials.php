<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

class WahaCredentials
{
    public function enabled(): bool
    {
        return in_array(strtolower(trim((string) getenv('VERIFICATION_WHATSAPP_ENABLED'))), ['1', 'true', 'yes'], true);
    }

    public function get(): array
    {
        $url = rtrim(trim((string) getenv('WAHA_PLATFORM_BACKEND_URL')), '/');
        $key = trim((string) getenv('WAHA_PLATFORM_API_KEY'));
        $session = trim((string) getenv('VERIFICATION_WHATSAPP_SESSION'));
        $pool = trim((string) getenv('VERIFICATION_WHATSAPP_SESSIONS'));
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            preg_match('/[\x00-\x20\\\\]/', $url) || $key === '' || preg_match('/[\r\n]/', $key) ||
            ($pool === '' && !preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/', $session))) return [];
        if ($pool !== '') {
            try {
                $sessions = json_decode($pool, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
            if (!is_array($sessions) || !array_is_list($sessions) || $sessions === []) return [];
            foreach ($sessions as $name) {
                if (!is_string($name) || !preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/', $name)) return [];
            }
            return ['url' => $url, 'apiKey' => $key, 'sessions' => array_values(array_unique($sessions))];
        }
        return ['url' => $url, 'apiKey' => $key, 'session' => $session];
    }
}
