<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureCredits\Enrichment\Providers\Result;
use Espo\Modules\FeatureCredits\Verification\TwilioCredentials;
use Espo\Modules\FeatureCredits\Verification\TwilioTransport;

/**
 * A Contact's enriched phones must be valid mobile numbers. Twilio Lookup (Line Type
 * Intelligence) decides; when it cannot answer, the Brazilian numbering plan does.
 * Company (Account) phones are out of scope: landlines are expected there.
 */
class MobilePhones
{
    /** Contact phone capability marker; changing it retires earlier unchecked sibling caches. */
    public const REVISION = 'contact-phone-mobile-only-v1';

    private const CALL_TIMEOUT_MS = 5000;
    private const MINIMUM_CALL_MS = 500;

    public function __construct(private TwilioCredentials $credentials, private TwilioTransport $transport) {}

    /** Applies to the requested phones and to phones retained as an unpurchased sibling. */
    public function apply(Result $result, string $targetType, string $product, int $budgetMs): Result
    {
        if ($targetType !== 'Contact' || !in_array($result->state, ['match', 'no_match'], true)) return $result;
        $requested = $product === 'phone' && $result->values;
        if (!$requested && !isset($result->hidden['phone'])) return $result;
        $deadline = hrtime(true) + max(0, $budgetMs) * 1_000_000;
        $values = $requested ? $this->filter($result->values, $deadline) : $result->values;
        $hidden = $result->hidden;
        if (isset($hidden['phone'])) {
            $hidden['phone'] = $this->filter($hidden['phone'], $deadline);
            if (!$hidden['phone']) unset($hidden['phone']);
        }
        return new Result($values ? 'match' : 'no_match', $values, hidden: $hidden,
            retentionSeconds: $result->retentionSeconds, dateOfBirth: $result->dateOfBirth);
    }

    /** @param list<string> $phones E.164 values @return list<string> */
    private function filter(array $phones, int $deadline): array
    {
        $kept = [];
        foreach ($phones as $phone) {
            if (!is_string($phone)) continue;
            $brazilian = str_starts_with($phone, '+55');
            // A Brazilian number outside the mobile plan needs no paid lookup to be refused.
            if ($brazilian && !self::brazilianMobile($phone)) continue;
            $mobile = $this->lookup($phone, $deadline);
            // No Twilio answer: the numbering plan stands in for Brazil; other countries have no such rule.
            if ($mobile ?? $brazilian) $kept[] = $phone;
        }
        return $kept;
    }

    /** DDD + 9 + eight digits. The ninth digit is never inferred for older eight-digit values. */
    public static function brazilianMobile(string $e164): bool
    {
        return preg_match('/\A\+55[1-9][1-9]9[0-9]{8}\z/', $e164) === 1;
    }

    /** True/false is Twilio's answer; null means it gave none. */
    private function lookup(string $e164, int $deadline): ?bool
    {
        $remaining = intdiv($deadline - hrtime(true), 1_000_000);
        $creds = $this->credentials->get();
        if (!$creds || $remaining < self::MINIMUM_CALL_MS || !preg_match('/\A\+[1-9][0-9]{7,14}\z/', $e164)) return null;
        try {
            $response = $this->transport->send('/v2/PhoneNumbers/' . rawurlencode($e164),
                ['Fields' => 'line_type_intelligence'],
                ['Authorization: Basic ' . base64_encode($creds['accountSid'] . ':' . $creds['authToken'])],
                min(self::CALL_TIMEOUT_MS, $remaining));
        } catch (\Throwable) {
            return null;
        }
        // Twilio reports an unknown number as 404; every other non-200 is an outage, not an answer.
        if ($response->status === 404) return false;
        if ($response->status !== 200 || $response->data === null) return null;
        $body = $response->data;
        if (($body['valid'] ?? null) === false) return false;
        $line = $body['line_type_intelligence'] ?? null;
        if (($body['valid'] ?? null) !== true || !is_array($line) || ($line['error_code'] ?? null) !== null ||
            !is_string($line['type'] ?? null) || $line['type'] === '') {
            return null;
        }
        return $line['type'] === 'mobile';
    }
}
