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
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            preg_match('/[\x00-\x20\\\\]/', $url) || $key === '' || preg_match('/[\r\n]/', $key) ||
            !preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/', $session)) return [];
        return ['url' => $url, 'apiKey' => $key, 'session' => $session];
    }
}
