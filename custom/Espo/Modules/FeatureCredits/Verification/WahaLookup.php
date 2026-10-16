<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

class WahaLookup
{
    public function __construct(private WahaCredentials $credentials, private WahaTransport $transport) {}

    public function verify(#[\SensitiveParameter] string $phone, int $timeoutMs = 30000): WhatsAppResult
    {
        $connection = $this->credentials->get();
        if (!$this->credentials->enabled() || !$connection || WhatsAppPhone::e164($phone) !== $phone ||
            $timeoutMs < 1 || $timeoutMs > 30000) {
            return new WhatsAppResult(null);
        }
        try {
            if (isset($connection['sessions'])) {
                // Discovery happens after the durable dispatch claim, outside database locks.
                // Both HTTP requests share the original lease/timeout; never fail over a lookup.
                $started = hrtime(true);
                $sessions = $this->transport->sessions($connection, min(5000, $timeoutMs));
                if ($sessions->status < 200 || $sessions->status >= 300 ||
                    !is_array($sessions->data) || !array_is_list($sessions->data)) return new WhatsAppResult(null);
                $working = [];
                foreach ($sessions->data as $session) {
                    if (is_array($session) && ($session['status'] ?? null) === 'WORKING' &&
                        is_string($session['name'] ?? null) && in_array($session['name'], $connection['sessions'], true)) {
                        $working[$session['name']] = $session['name'];
                    }
                }
                $working = array_values($working);
                $timeoutMs -= (int) ceil((hrtime(true) - $started) / 1000000);
                if (!$working || $timeoutMs < 1) return new WhatsAppResult(null);
                $connection['session'] = $working[random_int(0, count($working) - 1)];
                unset($connection['sessions']);
            }
            $response = $this->transport->send($connection, substr($phone, 1), $timeoutMs);
            return self::evaluate($response->status, $response->data);
        } catch (\Throwable) {
            return new WhatsAppResult(null);
        }
    }

    public static function evaluate(int $status, ?array $data): WhatsAppResult
    {
        return new WhatsAppResult($status >= 200 && $status < 300 && is_bool($data['numberExists'] ?? null)
            ? $data['numberExists'] : null);
    }
}
