<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;

/**
 * Tenant HTTP egress with URL prefix allow-list + SSRF host blocks.
 * Params: requestUrl, requestType (GET|POST|PUT|PATCH), optional contentType, content, headers[].
 * Empty allowedUrlPrefixList → disabled (fail-closed).
 */
class SendHttpRequest implements Action
{
    public function __construct(
        private TenantGuard $tenantGuard,
        private Metadata $metadata,
        private Config $config,
        private Log $log,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('SendHttpRequest: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $url = trim((string) ($context->params['requestUrl'] ?? $context->params['url'] ?? ''));
        $method = strtoupper(trim((string) ($context->params['requestType'] ?? $context->params['method'] ?? 'POST')));

        if ($url === '') {
            throw new Error('SendHttpRequest: requestUrl is required.');
        }

        /** @var list<string>|null $allowedMethods */
        $allowedMethods = $this->metadata->get(['app', 'journeyHttpRequest', 'allowedMethods']);
        if (!is_array($allowedMethods) || $allowedMethods === []) {
            $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH'];
        }
        $allowedMethods = array_map('strtoupper', $allowedMethods);

        if (!in_array($method, $allowedMethods, true)) {
            throw new Error("SendHttpRequest: method '{$method}' not allowed.");
        }

        $url = $this->interpolateTarget($url, $context);
        $this->tenantGuard->assertHttpUrlAllowed($url);

        $contentType = $context->params['contentType'] ?? null;
        if ($contentType !== null && $contentType !== '') {
            $contentType = (string) $contentType;
            if (!in_array($contentType, ['application/json', 'application/x-www-form-urlencoded'], true)) {
                throw new Error('SendHttpRequest: unsupported contentType.');
            }
        } else {
            $contentType = null;
        }

        $content = $context->params['content'] ?? null;
        if (is_array($content) || $content instanceof \stdClass) {
            $encoded = json_encode($content, JSON_UNESCAPED_UNICODE);
            $content = $encoded === false ? null : $encoded;
            if ($contentType === null) {
                $contentType = 'application/json';
            }
        } elseif (is_string($content)) {
            $content = $this->interpolateTarget($content, $context);
        } else {
            $content = null;
        }

        $maxBody = (int) ($this->metadata->get(['app', 'journeyHttpRequest', 'maxBodyBytes']) ?? 65536);
        if (is_string($content) && strlen($content) > max(1024, $maxBody)) {
            throw new Error('SendHttpRequest: body too large.');
        }

        $timeout = (int) (
            $this->metadata->get(['app', 'journeyHttpRequest', 'timeoutSeconds'])
            ?? $this->config->get('workflowSendRequestTimeout', 7)
            ?? 7
        );
        $timeout = max(1, min(30, $timeout));

        $headers = [];
        if ($contentType) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $extra = $context->params['headers'] ?? [];
        if ($extra instanceof \stdClass) {
            $extra = (array) $extra;
        }
        if (is_array($extra)) {
            foreach ($extra as $h) {
                if (!is_string($h) || trim($h) === '') {
                    continue;
                }
                $h = $this->interpolateTarget(trim($h), $context);
                // Block host override / hop-by-hop style abuse.
                if (preg_match('/^(host|content-length)\\s*:/i', $h)) {
                    continue;
                }
                $headers[] = $h;
            }
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new Error('SendHttpRequest: curl init failed.');
        }

        $payload = $content;
        if ($method === 'GET' && is_string($payload) && $payload !== '') {
            $separator = (parse_url($url, PHP_URL_QUERY) === null) ? '?' : '&';
            $url .= $separator . $payload;
            $payload = null;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);

        if ($payload !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $this->log->info("SendHttpRequest: {$method} {$url} tenant={$tenantId}");

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            throw new Error("SendHttpRequest: curl error {$errno}.");
        }

        if ($code < 200 || $code >= 300) {
            $snippet = is_string($response) ? substr($response, 0, 200) : '';
            throw new Error("SendHttpRequest: HTTP {$code}" . ($snippet !== '' ? " — {$snippet}" : ''));
        }
    }

    private function interpolateTarget(string $content, ActionContext $context): string
    {
        $target = $context->target;

        foreach ($target->getAttributeList() as $attr) {
            if ($attr === 'password' || str_contains(strtolower($attr), 'token') || str_contains(strtolower($attr), 'secret')) {
                continue;
            }
            $value = $target->get($attr);
            if (is_scalar($value) || $value === null) {
                $content = str_replace('{$' . $attr . '}', $value === null ? '' : (string) $value, $content);
            }
        }

        $content = str_replace('{$journeyId}', (string) $context->journey->getId(), $content);
        $content = str_replace('{$journeyRecordId}', (string) $context->record->getId(), $content);
        $content = str_replace('{$tenantId}', (string) ($context->tenantId ?? ''), $content);

        return $content;
    }
}
