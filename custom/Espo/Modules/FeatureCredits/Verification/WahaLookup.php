<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

class WahaLookup
{
    public function __construct(private WahaCredentials $credentials, private WahaTransport $transport) {}

    public function verify(#[\SensitiveParameter] string $phone, int $timeoutMs = 30000): WhatsAppResult
    {
        $connection = $this->credentials->get();
        if (!$this->credentials->enabled() || !$connection || WhatsAppPhone::e164($phone) !== $phone) {
            return new WhatsAppResult(null);
        }
        try {
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
