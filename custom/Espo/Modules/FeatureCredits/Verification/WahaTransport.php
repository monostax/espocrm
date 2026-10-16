<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use Espo\Modules\FeatureCredits\Enrichment\Providers\Response;

/** Bounded GETs to the operator-configured platform. No redirects, retries or logs. */
class WahaTransport
{
    public function send(#[\SensitiveParameter] array $connection, #[\SensitiveParameter] string $phone, int $timeoutMs): Response
    {
        if (!preg_match('/\A[1-9][0-9]{7,14}\z/', $phone)) {
            throw new \InvalidArgumentException('Invalid registration lookup request.');
        }
        return $this->get($connection, '/api/contacts/check-exists?' . http_build_query(
            ['phone' => $phone, 'session' => $connection['session']], '', '&', PHP_QUERY_RFC3986), $timeoutMs);
    }

    public function sessions(#[\SensitiveParameter] array $connection, int $timeoutMs): Response
    {
        return $this->get($connection, '/api/sessions?all=true', $timeoutMs, true);
    }

    private function get(#[\SensitiveParameter] array $connection, #[\SensitiveParameter] string $path, int $timeoutMs, bool $list = false): Response
    {
        if ($timeoutMs < 1 || $timeoutMs > 30000) {
            throw new \InvalidArgumentException('Invalid registration lookup timeout.');
        }
        $maxBytes = $list ? 1048576 : 65536;
        $body = '';
        $curl = curl_init();
        try {
            curl_setopt_array($curl, [
                CURLOPT_URL => $connection['url'] . $path,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Api-Key: ' . $connection['apiKey']],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($curl) === false) return new Response(0, null);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            return new Response($status, is_array($data) && array_is_list($data) === $list ? $data : null);
        } catch (\Throwable) {
            return new Response(0, null);
        } finally {
            curl_close($curl);
        }
    }
}
