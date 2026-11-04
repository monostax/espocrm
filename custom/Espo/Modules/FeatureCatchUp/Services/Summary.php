<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Entities\User;

class Summary
{
    public function __construct(private User $user, private Verdicts $verdicts) {}

    /**
     * A deal's verdict: the stored one when this revision and evidence were already judged, otherwise the
     * backend's analysis, stored once it completes. An answer that isn't a valid verdict counts as `none`:
     * it was paid for, and the same state is not analysed again.
     */
    public function analyse(array $snapshot, string $locale, ?string $requestId = null): array
    {
        $key = [$snapshot['id'], $snapshot['revision'], $locale, $snapshot['evidenceScope']];
        $stored = $this->verdicts->find(...$key);
        if ($stored) return ['status' => 'completed', 'cached' => true, ...$stored];
        $result = $this->request($snapshot, $locale, $requestId);
        if ($result['status'] !== 'completed') return [...$result, 'cached' => false, 'verdict' => null, 'reason' => null, 'card' => null];
        $card = is_array($result['card'] ?? null) ? $result['card'] : null;
        $verdict = in_array($card['verdict'] ?? null, Verdicts::VERDICTS, true) ? $card['verdict'] : 'none';
        $reason = is_string($card['reason'] ?? null) ? $card['reason'] : 'The analysis could not be read.';
        $card = $verdict === 'none' ? null : array_diff_key($card, ['verdict' => true, 'reason' => true]);
        $this->verdicts->store((string) $snapshot['tenantId'], ...[...$key, $verdict, $reason, $card]);
        return ['status' => 'completed', 'requestId' => $result['requestId'] ?? null, 'cached' => false,
            'verdict' => $verdict, 'reason' => $reason, 'card' => $card];
    }

    /** The backend enqueues/polls Hatchet. All evidence has just passed Feed's ACL checks. */
    public function request(array $snapshot, string $locale, ?string $requestId = null): array
    {
        $secret = getenv('CATCH_UP_BACKEND_SECRET');
        $baseUrl = getenv('CATCH_UP_BACKEND_URL');
        $instance = (string) $snapshot['catchUpPolicy']['instance'];
        if (!$secret || !$baseUrl || !$instance) throw new ServiceUnavailable('Catch Up backend is not configured.');
        $payload = [
            'source' => [
                'type' => $snapshot['type'], 'instance' => $instance, 'tenantId' => (string) $snapshot['tenantId'],
                'userId' => $this->user->getId(), 'recordId' => $snapshot['id'],
            ],
            'facts' => (object) $snapshot['facts'], 'evidence' => $snapshot['evidence'], 'locale' => $locale,
            'policy' => $snapshot['catchUpPolicy'],
        ];
        if ($requestId !== null) $payload['requestId'] = $requestId;
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $curl = curl_init(rtrim($baseUrl, '/') . '/internal/catch-up/summary');
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Crm-Timestamp: ' . $timestamp, 'X-Crm-Signature: sha256=' . $signature],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!in_array($status, [200, 202], true) || !is_string($response)) throw new ServiceUnavailable('Catch Up backend is temporarily unavailable.');
        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['status'])) throw new ServiceUnavailable('Invalid Catch Up backend response.');
        return array_intersect_key($data, array_flip(['status', 'requestId', 'summary', 'card', 'model']));
    }
}
