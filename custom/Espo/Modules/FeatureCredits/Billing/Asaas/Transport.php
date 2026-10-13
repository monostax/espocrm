<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing\Asaas;

/** One bounded HTTPS operation; no redirects, retries, raw-body logging or exception chaining. */
class Transport
{
    public function send(Connection $connection, string $method, string $path, #[\SensitiveParameter] ?string $json = null, array $query = []): Response
    {
        if (!in_array($method, ['GET', 'POST'], true) || !preg_match('#^/(customers|payments|subscriptions)(/[a-zA-Z0-9_-]+)?$#D', $path) ||
            ($method === 'GET' && $json !== null) || ($method === 'POST' && (!$connection->creationEnabled || $json === null || $query))) {
            throw new \InvalidArgumentException('Invalid Asaas transport operation.');
        }
        $url = $connection->baseUrl() . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $body = ''; $curl = curl_init();
        try {
            curl_setopt_array($curl, [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $connection->headers(), CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => 5000, CURLOPT_TIMEOUT_MS => 30000, CURLOPT_NOSIGNAL => true,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 1048576) return 0;
                    $body .= $chunk; return strlen($chunk);
                }]);
            if ($json !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
            if (curl_exec($curl) === false) return new Response(0, null);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            try { $data = Json::decode($body); } catch (\JsonException | \InvalidArgumentException) { $data = null; }
            return new Response($status, $data);
        } catch (\Throwable) {
            return new Response(0, null);
        } finally { curl_close($curl); }
    }
}
