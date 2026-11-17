<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureCredits\Enrichment\Providers\Response;

/**
 * OpenRouter Decisions API: parallel HTTPS POSTs to one fixed URL, no redirects, bounded time/body,
 * and no body/header/error logging. The key comes from the server environment only.
 */
class Decisions
{
    private const URL = 'https://openrouter.ai/api/alpha/decisions';
    private const PARALLEL = 8;

    /** Empty when the platform key is not configured. */
    public function key(): string
    {
        $value = getenv('ENRICHMENT_OPENROUTER_API_KEY');
        return is_string($value) && trim($value) !== '' && !preg_match('/[\x00-\x1f\x7f]/', $value) ? trim($value) : '';
    }

    /**
     * @param array<int|string, array> $bodies one decision request each
     * @return array<int|string, Response> same keys; status 0 when a request got no answer
     */
    public function ask(#[\SensitiveParameter] string $key, #[\SensitiveParameter] array $bodies, int $timeoutMs): array
    {
        if ($key === '' || $timeoutMs < 1 || $timeoutMs > 30000) throw new \InvalidArgumentException('Invalid decision request.');
        $answers = [];
        foreach (array_chunk($bodies, self::PARALLEL, true) as $chunk) $answers += $this->chunk($key, $chunk, $timeoutMs);
        return $answers;
    }

    private function chunk(#[\SensitiveParameter] string $key, #[\SensitiveParameter] array $bodies, int $timeoutMs): array
    {
        $multi = curl_multi_init();
        $handles = []; $buffers = []; $answers = [];
        try {
            foreach ($bodies as $id => $body) {
                $buffers[$id] = '';
                $curl = curl_init();
                curl_setopt_array($curl, [CURLOPT_URL => self::URL, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $key],
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs), CURLOPT_TIMEOUT_MS => $timeoutMs, CURLOPT_NOSIGNAL => true,
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$buffers, $id): int {
                        if (strlen($buffers[$id]) + strlen($chunk) > 262144) return 0;
                        $buffers[$id] .= $chunk;
                        return strlen($chunk);
                    }]);
                curl_multi_add_handle($multi, $curl);
                $handles[$id] = $curl;
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) curl_multi_select($multi, 0.2);
            } while ($running && $status === CURLM_OK);
            foreach ($handles as $id => $curl) {
                $code = curl_errno($curl) === 0 ? (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE) : 0;
                try { $data = json_decode($buffers[$id], true, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { $data = null; }
                $answers[$id] = new Response($code, is_array($data) && !array_is_list($data) ? $data : null);
            }
        } catch (\Throwable) {
            // curl errors can carry the key in headers. Do not chain or log them.
        } finally {
            foreach ($handles as $curl) { curl_multi_remove_handle($multi, $curl); curl_close($curl); }
            curl_multi_close($multi);
        }
        foreach ($bodies as $id => $body) $answers[$id] ??= new Response(0, null);
        return $answers;
    }
}
