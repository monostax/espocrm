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

namespace Espo\Modules\FeatureSelfService\Tools\Provisioning\Preview;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Throwable;

/**
 * Reads a site preview once, while its workspace is provisioned. Previews are public by design (built from a
 * public website, addressed by an unguessable id), so no credential is sent. A failure only means the workspace
 * starts with the defaults; it never fails the provisioning.
 *
 * Config `selfServicePreviewUrl` (default https://monostax.ai/api/previa/) exists for tests.
 */
class PreviewClient
{
    public const DEFAULT_URL = 'https://monostax.ai/api/previa/';
    private const MAX_BYTES = 524288;

    public function __construct(
        private Config $config,
        private Log $log,
    ) {}

    public static function isPreviewId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9]{20}$/', $value) === 1;
    }

    public function fetch(string $previewId): ?PreviewCarry
    {
        if (!self::isPreviewId($previewId)) {
            return null;
        }

        $base = (string) ($this->config->get('selfServicePreviewUrl') ?: self::DEFAULT_URL);

        if (!preg_match('#^https?://[^/?\#\s]+/[^?\#\s]*$#', $base)) {
            $this->log->warning('Self-service preview: selfServicePreviewUrl is not an http(s) base URL.');

            return null;
        }

        [$status, $body] = $this->get(rtrim($base, '/') . '/' . $previewId, str_starts_with($base, 'https://'));

        if ($status !== 200) {
            $this->log->info("Self-service preview {$previewId}: HTTP {$status}, starting with the defaults.");

            return null;
        }

        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $carry = is_array($data['preview']['carry'] ?? null) ? PreviewCarry::fromArray($data['preview']['carry']) : null;

        if (!$carry) {
            $this->log->info("Self-service preview {$previewId}: no usable carry, starting with the defaults.");
        }

        return $carry;
    }

    /**
     * @return array{int, string}
     */
    protected function get(string $url, bool $https): array
    {
        $body = '';
        $curl = curl_init();

        try {
            $protocols = $https ? CURLPROTO_HTTPS : CURLPROTO_HTTP | CURLPROTO_HTTPS;
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: MonostaxCRM/1.0 (self-service provisioning)'],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => $protocols,
                CURLOPT_REDIR_PROTOCOLS => $protocols,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => 3000,
                CURLOPT_TIMEOUT_MS => 6000,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        return 0;
                    }

                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);

            if (curl_exec($curl) === false) {
                return [0, ''];
            }

            return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body];
        } catch (Throwable) {
            return [0, ''];
        } finally {
            curl_close($curl);
        }
    }
}
