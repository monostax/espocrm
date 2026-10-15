<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Dedicated credentials bind a platform before any caller-supplied identity is read. */
class ManagedCampaignBridge
{
    public const PATH = '/api/v1/ManagedWhatsAppCampaign';

    public function __construct(
        private Config $config, private EntityManager $em, private Log $log, private ManagedCampaignAccess $access,
    ) {}

    public static function signature(string $secret, string $method, string $path, string $timestamp, string $id, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [strtoupper($method), $path, $timestamp, $id, $body]), $secret);
    }

    public function authenticate(Request $request, string $body): Entity
    {
        $key = (string) $request->getHeader('X-Campaign-Key');
        $credentials = (array) $this->config->get('managedWhatsAppCampaignCredentials', []);
        $credential = (array) ($credentials[$key] ?? []);
        $secret = $this->secret($credential);
        $timestamp = (string) $request->getHeader('X-Campaign-Timestamp');
        $id = (string) $request->getHeader('X-Campaign-Request-Id');
        $path = $request->getUri()->getPath();
        // Like CRM's core authentication, honor HTTPS terminated by the ingress.
        $https = $request->getUri()->getScheme() === 'https' || $request->getServerParam('HTTP_X_FORWARDED_PROTO') === 'https';
        if (!$https || strtoupper($request->getMethod()) !== 'POST' ||
            strlen($secret) < 32 || $path !== self::PATH || !preg_match('/^\d{10}$/D', $timestamp) ||
            abs(time() - (int) $timestamp) > 300 || !preg_match('/^[\w-]{16,100}$/D', $id) ||
            !hash_equals(self::signature($secret, 'POST', self::PATH, $timestamp, $id, $body), (string) $request->getHeader('X-Campaign-Signature'))) {
            $this->log->warning('Managed campaign authentication failure.', ['stage' => 'authentication']);
            throw new Forbidden('Invalid campaign signature.');
        }
        $platform = $this->em->getEntityById('ChatwootPlatform', (string) ($credential['platformId'] ?? ''));
        if (!$platform) throw new Forbidden('Platform unavailable.');
        return $platform;
    }

    public function call(Entity $campaign, string $action, array $data = []): array
    {
        try {
            return $this->request($campaign, $action, $data);
        } catch (Forbidden) {
            return ['rejected' => true, 'status' => 403];
        }
    }

    private function request(Entity $campaign, string $action, array $data): array
    {
        $account = $this->em->getEntityById('ChatwootAccount', $campaign->get('chatwootAccountId'));
        $platform = $this->em->getEntityById('ChatwootPlatform', $campaign->get('managedPlatformId'));
        $inbox = $this->em->getEntityById('ChatwootInbox', $campaign->get('chatwootInboxId'));
        if (!$account || !$platform || !$inbox || $account->get('platformId') !== $platform->getId() ||
            $inbox->get('chatwootAccountId') !== $account->getId()) throw new Forbidden('Campaign binding changed.');
        [$key, $secret, $callbackUrl] = $this->credential($platform->getId());
        // Ordinary API traffic keeps its internal backendUrl. Campaign callbacks use TLS.
        $url = rtrim((string) ($callbackUrl ?? $platform->get('frontendUrl')), '/');
        if (!preg_match('#^https://[^/]+$#D', $url)) throw new Forbidden('TLS campaign endpoint required.');
        $path = '/integrations/campaigns/' . (int) $account->get('chatwootAccountId') . '/' .
            (int) $campaign->get('managedOperationId') . '/' . $action;
        $body = json_encode(array_merge($data, [
            'campaign_id' => $campaign->getId(), 'actor_id' => (int) $campaign->get('managedActorId'),
            'request_fingerprint' => $campaign->get('managedFingerprint'),
            'inbox_id' => (int) $inbox->get('chatwootInboxId'),
            'sender_id' => $this->access->integrationSenderId($account),
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $id = bin2hex(random_bytes(16));
        $curl = curl_init($url . $path);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 40, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Campaign-Key: ' . $key,
                'X-Campaign-Timestamp: ' . $timestamp, 'X-Campaign-Request-Id: ' . $id,
                'X-Campaign-Signature: ' . self::signature($secret, 'POST', $path, $timestamp, $id, $body)],
        ]);
        try {
            $raw = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($curl);
        }
        // Return only structured categories. Never log provider payloads or resolved fields.
        if ($raw === false || $status >= 500 || $status === 429 || $status === 0) return ['transient' => true];
        if ($status < 200 || $status >= 300) return ['rejected' => true, 'status' => $status];
        $result = json_decode($raw, true);
        return is_array($result) ? $result : ['transient' => true];
    }

    private function credential(string $platformId): array
    {
        foreach ((array) $this->config->get('managedWhatsAppCampaignCredentials', []) as $key => $entry) {
            $entry = (array) $entry;
            $secret = $this->secret($entry);
            if (($entry['platformId'] ?? '') === $platformId && strlen($secret) >= 32) {
                return [$key, $secret, $entry['callbackUrl'] ?? null];
            }
        }
        throw new Forbidden('Campaign credential unavailable.');
    }

    private function secret(array $credential): string
    {
        return isset($credential['secretEnv'])
            ? (string) getenv($credential['secretEnv'])
            : (string) ($credential['secret'] ?? '');
    }
}
