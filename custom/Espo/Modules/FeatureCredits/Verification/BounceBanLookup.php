<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

class BounceBanLookup
{
    public function __construct(private BounceBanCredentials $credentials, private BounceBanTransport $transport) {}

    public function verify(string $email, ?string $taskId, int $timeoutMs): EmailResult
    {
        $credentials = $this->credentials->get();
        if (!$credentials) return new EmailResult('failed');
        $response = $this->transport->send(
            $taskId === null ? '/v1/verify/single' : '/v1/verify/single/status',
            $taskId === null ? ['email' => $email, 'mode' => 'regular', 'disable_catchall_verify' => '0'] : ['id' => $taskId],
            $credentials['apiKey'],
            $timeoutMs,
        );
        return self::evaluate($response->status, $response->data, $email, $taskId);
    }

    public static function evaluate(int $status, ?array $data, string $email, ?string $taskId = null): EmailResult
    {
        $failed = new EmailResult('failed');
        if ($status !== 200 || !$data || isset($data['error']) ||
            !is_string($data['id'] ?? null) || !preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $data['id']) ||
            ($taskId !== null && !hash_equals($taskId, $data['id']))) return $failed;
        if (isset($data['email']) && Kind::email($data['email']) !== $email) return $failed;
        if (in_array($data['status'] ?? null, ['verifying', 'queue'], true)) {
            if (isset($data['try_again_at']) && (!is_int($data['try_again_at']) || $data['try_again_at'] < 0)) return $failed;
            return new EmailResult('pending', $data['id'], tryAgainAt: $data['try_again_at'] ?? null);
        }
        if (($data['status'] ?? null) !== 'success' || Kind::email($data['email'] ?? null) !== $email ||
            !in_array($data['result'] ?? null, Kind::outcomes(Kind::EMAIL), true) ||
            !is_int($data['score'] ?? null) || $data['score'] < 0 || $data['score'] > 100 ||
            (isset($data['mode']) && $data['mode'] !== 'regular')) return $failed;
        $flags = [];
        foreach (['is_disposable', 'is_accept_all', 'is_role', 'is_free'] as $flag) {
            if (!array_key_exists($flag, $data)) continue;
            if (!is_bool($data[$flag])) return $failed;
            $flags[$flag] = $data[$flag];
        }
        $verifiedAt = $data['verify_at'] ?? null;
        if ($verifiedAt !== null && (!is_string($verifiedAt) ||
            !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $verifiedAt) ||
            strtotime($verifiedAt) === false)) return $failed;
        return new EmailResult('completed', $data['id'], $data['result'], [
            'score' => $data['score'], 'flags' => $flags, 'verifiedAt' => $verifiedAt,
        ]);
    }
}
