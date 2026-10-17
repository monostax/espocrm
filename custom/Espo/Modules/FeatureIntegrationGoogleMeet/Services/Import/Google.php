<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

use Espo\Tools\OAuth\TokensProvider;

/** Import-only strict reader. A failed page is never represented as an empty result. */
class Google
{
    public const MEET = 'https://meet.googleapis.com/v2/';
    public const DOCS = 'https://docs.googleapis.com/v1/documents/';
    public const DRIVE = 'https://www.googleapis.com/drive/v3/files/';
    public const MAX_BYTES = 5368709120;

    public function __construct(private TokensProvider $tokens) {}

    public static function id(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,255}$/D', $id)) throw new Failure('invalidSource');
        return $id;
    }

    public static function conference(string $name): string
    {
        if (!preg_match('~^conferenceRecords/[A-Za-z0-9_-]{1,200}$~D', $name)) throw new Failure('invalidSource');
        return $name;
    }

    public function token(string $account, float $deadline): string
    {
        if (microtime(true) >= $deadline) throw new Failure('deadline');
        try { return $this->tokens->get($account, min(30, $deadline - microtime(true)))->getAccessToken(); }
        catch (\Throwable) { throw new Failure('reconnect'); }
    }

    public function get(string $account, string $url, array $query, float $deadline): array
    {
        $token = $this->token($account, $deadline);
        $ch = curl_init($url . ($query ? '?' . http_build_query($query) : ''));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT_MS => max(1, (int) (min(30, $deadline - microtime(true)) * 1000)),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json']]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) throw new Failure(microtime(true) >= $deadline ? 'deadline' : 'sourceUnavailable');
        $this->checkStatus($status);
        try { $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new Failure('invalidSource'); }
        if (!is_array($data)) throw new Failure('invalidSource');
        return $data;
    }

    public function pages(string $account, string $path, string $field, float $deadline): \Generator
    {
        $cursor = null;
        $seen = [];
        do {
            $page = $this->get($account, self::MEET . $path, array_filter(['pageSize' => 100, 'pageToken' => $cursor]), $deadline);
            if (isset($page[$field]) && !is_array($page[$field])) throw new Failure('invalidSource');
            foreach ($page[$field] ?? [] as $row) {
                if (!is_array($row) || !is_string($row['name'] ?? null)) throw new Failure('invalidSource');
                yield $row;
            }
            $cursor = $page['nextPageToken'] ?? null;
            if ($cursor !== null && (!is_string($cursor) || isset($seen[$cursor]))) throw new Failure('invalidSource');
            if ($cursor) $seen[$cursor] = true;
        } while ($cursor);
    }

    public function doc(string $account, string $id, float $deadline): array
    {
        return $this->get($account, self::DOCS . self::id($id), ['includeTabsContent' => 'true'], $deadline);
    }

    public function file(string $account, string $id, float $deadline): array
    {
        return $this->get($account, self::DRIVE . self::id($id), ['fields' => 'id,size,mimeType,md5Checksum'], $deadline);
    }

    public function download(string $account, string $id, string $path, array $metadata, float $deadline): int
    {
        if (($metadata['mimeType'] ?? null) !== 'video/mp4') throw new Failure('unsupportedMedia');
        if (isset($metadata['size']) && (int) $metadata['size'] > self::MAX_BYTES) throw new Failure('tooLarge');
        $token = $this->token($account, $deadline);
        $out = fopen($path, 'xb');
        if (!$out) throw new Failure('storage');
        chmod($path, 0600);
        $bytes = 0;
        $hash = hash_init('md5');
        $failure = null;
        $ch = curl_init(self::DRIVE . self::id($id) . '?alt=media');
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT_MS => max(1, (int) (($deadline - microtime(true)) * 1000)),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: video/mp4'],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($out, &$bytes, &$failure, $hash): int {
                $length = strlen($chunk);
                if ($bytes + $length > self::MAX_BYTES) { $failure = 'tooLarge'; return 0; }
                if (fwrite($out, $chunk) !== $length) { $failure = 'storage'; return 0; }
                hash_update($hash, $chunk);
                $bytes += $length;
                return $length;
            }]);
        try {
            $ok = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($failure) throw new Failure($failure);
            if ($ok === false) throw new Failure(microtime(true) >= $deadline ? 'deadline' : 'sourceUnavailable');
            $this->checkStatus($status);
            if (!$bytes || (isset($metadata['size']) && $bytes !== (int) $metadata['size']) ||
                (isset($metadata['md5Checksum']) && !hash_equals($metadata['md5Checksum'], hash_final($hash)))) {
                throw new Failure('incompleteTransfer');
            }
            if (!fflush($out)) throw new Failure('storage');
            return $bytes;
        } finally { curl_close($ch); fclose($out); }
    }

    private function checkStatus(int $status): void
    {
        if ($status === 200) return;
        throw new Failure(match ($status) { 401 => 'reconnect', 403 => 'denied', 404, 410 => 'sourceMissing', 429 => 'quota', default => 'sourceUnavailable' });
    }
}
