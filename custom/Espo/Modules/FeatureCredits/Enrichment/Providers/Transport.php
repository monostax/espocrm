<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

/** One HTTPS request, no redirects/retries, bounded time/body, no URL/body/error logging. */
class Transport
{
    public function send(string $provider, string $method, #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] array $query = [], #[\SensitiveParameter] array $headers = [], int $timeoutMs = 30000): Response
    {
        $host = match ($provider) {
            'cpfcnpj' => 'api.cpfcnpj.com.br',
            'hub' => 'ws.hubdodesenvolvedor.com.br',
            'apollo' => 'api.apollo.io',
            'registrobr' => 'rdap.registro.br',
            default => throw new \InvalidArgumentException('Unknown enrichment supplier.'),
        };
        if (!in_array($method, ['GET', 'POST'], true) || !str_starts_with($path, '/') ||
            str_starts_with($path, '//') || preg_match('/[?#\\\\\x00-\x20]/', $path) || $timeoutMs < 1 || $timeoutMs > 30000) {
            throw new \InvalidArgumentException('Invalid enrichment transport request.');
        }
        // Apollo documents repeated [] parameters. PHP's default numeric indexes
        // encode them as objects in Rails-style query parsers instead of lists.
        $encodedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($provider === 'apollo') $encodedQuery = preg_replace('/%5B[0-9]+%5D=/', '%5B%5D=', $encodedQuery);
        $url = 'https://' . $host . $path . ($query ? '?' . $encodedQuery : '');
        $body = '';
        $curl = curl_init();
        try {
            curl_setopt_array($curl, [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => ['Accept: application/json', ...$headers],
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs), CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_NOSIGNAL => true, CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 2097152) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                }]);
            if (curl_exec($curl) === false) return new Response(0, null);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            try { $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
            catch (\JsonException) { $data = null; }
            return new Response($status, is_array($data) && !array_is_list($data) ? $data : null);
        } catch (\Throwable) {
            // curl exceptions/errors can include credentials in URLs. Do not chain or log them.
            return new Response(0, null);
        } finally {
            curl_close($curl);
        }
    }
}
