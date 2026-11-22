<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\Modules\FeatureCredits\Verification\TwilioCredentials;
use RuntimeException;

/**
 * "Receber por SMS": Twilio Verify sends its own code by SMS and checks it (`otp.twilioVerifyServiceSid`, the
 * platform Twilio account of TwilioCredentials). Verify needs no sender number of ours and screens SMS-pumping fraud.
 * One HTTPS POST per call, no redirects, bounded time, nothing logged.
 */
class TwilioVerify
{
    private const HOST = 'verify.twilio.com';
    private const TIMEOUT_MS = 10000;

    public function __construct(private TwilioCredentials $credentials, private Settings $settings) {}

    /** Whether "Receber por SMS" can work: a Verify service in the config and the platform Twilio credentials. */
    public function available(): bool
    {
        return $this->settings->twilioVerifyServiceSid() !== null && $this->credentials->get() !== [];
    }

    /**
     * Sends an SMS code to $phone (E.164). Returns Twilio's verification SID.
     *
     * @throws RuntimeException
     */
    public function start(string $phone): string
    {
        [$status, $data] = $this->post('Verifications', ['To' => $phone, 'Channel' => 'sms', 'Locale' => 'pt-br']);
        $sid = $data['sid'] ?? null;
        if ($status < 200 || $status >= 300 || !is_string($sid) || !preg_match('/^VE[0-9a-f]{32}$/', $sid)) {
            throw new RuntimeException("Twilio Verify did not send (HTTP {$status}, code " . ($data['code'] ?? '-') . ').');
        }
        return $sid;
    }

    /** Whether $code is the one Twilio sent to $phone. A missing or expired verification is a no. */
    public function check(string $phone, string $code): bool
    {
        try {
            [$status, $data] = $this->post('VerificationCheck', ['To' => $phone, 'Code' => $code]);
        } catch (RuntimeException) {
            return false;
        }
        return $status === 200 && ($data['status'] ?? null) === 'approved';
    }

    /**
     * @param array<string, string> $fields
     * @return array{int, array<string, mixed>}
     */
    private function post(string $resource, #[\SensitiveParameter] array $fields): array
    {
        $credentials = $this->credentials->get();
        $service = $this->settings->twilioVerifyServiceSid();
        if ($credentials === [] || $service === null) throw new RuntimeException('Twilio Verify is not configured.');

        $body = '';
        $curl = curl_init();
        try {
            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://' . self::HOST . "/v2/Services/{$service}/{$resource}",
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_USERPWD => $credentials['accountSid'] . ':' . $credentials['authToken'],
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => 5000,
                CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $response = curl_exec($curl);
            if ($response === false) throw new RuntimeException('Twilio Verify could not be reached.');
            $body = (string) $response;
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
        $data = json_decode($body, true);
        return [$status, is_array($data) ? $data : []];
    }
}
