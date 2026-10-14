<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

/**
 * Platform-owned server secrets for Twilio Lookup Identity Match (D15).
 * Independent of tenant VoIP credentials and OpenCode runtime.
 */
class TwilioCredentials
{
    /** @return array{accountSid?: string, authToken?: string} */
    public function get(): array
    {
        $names = [
            'accountSid' => 'VERIFICATION_TWILIO_ACCOUNT_SID',
            'authToken' => 'VERIFICATION_TWILIO_AUTH_TOKEN',
        ];
        $result = [];
        foreach ($names as $key => $envName) {
            $value = getenv($envName);
            if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                return [];
            }
            $result[$key] = trim($value);
        }
        return $result;
    }
}
