<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use Espo\Modules\FeatureCredits\Enrichment\Providers\Response;

/** Fixed-host, one-shot transport. GET submission is billable: never retry or log its URL. */
class BounceBanTransport
{
    public function send(string $path, #[\SensitiveParameter] array $query, #[\SensitiveParameter] string $key, int $timeoutMs): Response
    {
        if (!in_array($path, ['/v1/verify/single', '/v1/verify/single/status'], true) || $timeoutMs < 1 || $timeoutMs > 30000) {
            throw new \InvalidArgumentException('Invalid email verification transport request.');
        }
        $curl = curl_init();
        $body = '';
        try {
            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://api.bounceban.com' . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: ' . $key],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($curl) === false) return new Response(0, null);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            return new Response($status, is_array($data) && !array_is_list($data) ? $data : null);
        } catch (\Throwable) {
            return new Response(0, null);
        } finally {
            curl_close($curl);
        }
    }
}
