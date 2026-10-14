<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use Espo\Modules\FeatureCredits\Enrichment\Providers\Response;

/**
 * One HTTPS GET request to lookups.twilio.com, no redirects/retries, bounded time/body,
 * and no URL/header/body/error logging (D3, D12, D15, D25).
 */
class TwilioTransport
{
    private const HOST = 'lookups.twilio.com';

    public function send(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] array $query = [],
        #[\SensitiveParameter] array $headers = [],
        int $timeoutMs = 30000,
    ): Response {
        if (!str_starts_with($path, '/v2/PhoneNumbers/') ||
            str_starts_with($path, '//') ||
            preg_match('/[?#\\\\\x00-\x20]/', $path) ||
            $timeoutMs < 1 || $timeoutMs > 30000) {
            throw new \InvalidArgumentException('Invalid Twilio Lookup transport request.');
        }
        $encodedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $url = 'https://' . self::HOST . $path . ($query ? '?' . $encodedQuery : '');
        $body = '';
        $curl = curl_init();
        try {
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => ['Accept: application/json', ...$headers],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 2097152) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($curl) === false) {
                return new Response(0, null);
            }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            try {
                $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                $data = null;
            }
            return new Response($status, is_array($data) && !array_is_list($data) ? $data : null);
        } catch (\Throwable) {
            // Never log or rethrow curl errors containing query URLs or headers.
            return new Response(0, null);
        } finally {
            curl_close($curl);
        }
    }
}
