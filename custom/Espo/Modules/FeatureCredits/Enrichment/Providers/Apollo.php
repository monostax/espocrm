<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

use Espo\Modules\Global\Tools\LinkedInProfile as LinkedIn;

final class Apollo extends Supplier
{
    protected function provider(): string { return 'apollo'; }
    protected function eligible(string $type, string $product, array $identity): bool
    {
        return match ($type) {
            'Contact' => in_array($product, ['email', 'phone'], true) && LinkedIn::profile($type,$identity['linkedinUrl'] ?? null) !== null,
            // User-approved capability exception: do not substitute an employee's email.
            'Account' => in_array($product, ['phone', 'company'], true) && LinkedIn::profile($type,$identity['linkedinUrl'] ?? null) !== null,
            default => false,
        };
    }

    public function lookup(string $type, string $product, array $identity, int $timeoutMs = 30000): Result
    {
        $connection = $this->connection($type, $product, $identity);
        if (!$connection) return new Result('failed');
        $person = $type === 'Contact';
        $profile = LinkedIn::profile($type,$identity['linkedinUrl']);
        $query = $person ? ['linkedin_url' => $profile,
            'reveal_personal_emails' => $product === 'email' ? 'true' : 'false',
            'reveal_phone_number' => $product === 'phone' ? 'true' : 'false',
            'run_waterfall_email' => 'false', 'run_waterfall_phone' => 'false', 'poll_only' => 'true'] :
            ['linkedin_url' => $profile];
        $response = $this->transport->send('apollo', $person ? 'POST' : 'GET',
            $person ? '/api/v1/people/match' : '/api/v1/organizations/enrich', $query, ['x-api-key: ' . $connection['key']], $timeoutMs);
        if ($failure = $this->httpFailure($response)) return $failure;
        $body = $response->data;
        if (isset($body['error']) || isset($body['error_details'])) return new Result('failed');
        if ($person) {
            if (!array_key_exists('person', $body)) return new Result('ambiguous');
            if ($body['person'] === null) return new Result('no_match');
            if (($body['match_confidence'] ?? null) === 'none') return new Result('no_match');
            $data = $body['person'];
            if (!is_array($data) || !is_string($data['id'] ?? null) || LinkedIn::profile('Contact',$data['linkedin_url'] ?? null) !== $profile) return new Result('ambiguous');
            if ($product === 'phone') {
                $request = self::requestId($body['request_id'] ?? null);
                // Phone results are documented as asynchronous, even if demographics have phones.
                return $request === null ? new Result('ambiguous') : new Result('pending', requestId: $request, subjectId: $data['id']);
            }
            $emails = is_array($data['personal_emails'] ?? []) ? $data['personal_emails'] ?? [] : [];
            if (!in_array(strtolower((string) ($data['email_status'] ?? '')), ['invalid', 'unavailable'], true)) $emails[] = $data['email'] ?? null;
            $emails = array_filter($emails, static fn ($email) => $email !== 'email_not_unlocked@domain.com');
            // Incidental numbers must already carry a successful phone outcome;
            // demographics/placeholders do not bypass the asynchronous phone contract.
            $phones = [];
            foreach (is_array($data['phone_numbers'] ?? null) ? $data['phone_numbers'] : [] as $phone) {
                if (is_array($phone) && ($phone['status_cd'] ?? null) === 'valid_number') $phones[] = $phone['sanitized_number'] ?? null;
            }
            return $this->result($emails, $phones, 'email');
        }
        if (!array_key_exists('organization', $body)) return new Result('ambiguous');
        $data = $body['organization'];
        if ($data === null) return new Result('no_match');
        if (!is_array($data) || !is_string($data['id'] ?? null) ||
            LinkedIn::profile('Account',$data['linkedin_url'] ?? null) !== $profile) return new Result('ambiguous');
        if ($product === 'phone') return $this->result([], [$data['primary_phone']['sanitized_number'] ?? null, $data['phone'] ?? null], 'phone');
        $values = [];
        foreach (['name' => 'name', 'website_url' => 'website', 'industry' => 'industry', 'short_description' => 'description',
            'street_address' => 'billingAddressStreet', 'city' => 'billingAddressCity', 'state' => 'billingAddressState',
            'country' => 'billingAddressCountry', 'postal_code' => 'billingAddressPostalCode'] as $source => $field) {
            if (is_string($data[$source] ?? null) && trim($data[$source]) !== '') $values[$field] = trim($data[$source]);
        }
        $phone = $this->result([], [$data['primary_phone']['sanitized_number'] ?? null, $data['phone'] ?? null], 'phone');
        return new Result($values ? 'match' : 'no_match', $values, hidden: $phone->values ? ['phone' => $phone->values] : []);
    }

    public function poll(string $requestId, string $subjectId, int $timeoutMs = 30000): Result
    {
        $connection = $this->credentials->get('apollo');
        if (!$connection || self::requestId($requestId) === null || $subjectId === '') return new Result('failed');
        $response = $this->transport->send('apollo', 'GET', '/api/v1/webhook_result/' . rawurlencode($requestId), [],
            ['x-api-key: ' . $connection['key']], $timeoutMs);
        $body = $response->data;
        if ($response->status === 404 && ($body['error_code'] ?? null) === 'result_pending') {
            return new Result('pending', requestId: $requestId, subjectId: $subjectId,
                retryAfter: max(1, min(300, (int) ($body['retry_after_seconds'] ?? 10))));
        }
        if (in_array($body['error_code'] ?? null, ['request_id_unknown', 'request_id_expired', 'invalid_request_id'], true)) return new Result('ambiguous');
        if ($failure = $this->httpFailure($response)) return $failure;
        if (self::requestId($body['request_id'] ?? null) !== $requestId || ($body['request_type'] ?? null) !== 'phone') return new Result('ambiguous');
        $payload = $body['webhook_result'] ?? null;
        // Polling recovers the payload even when webhook delivery failed (including
        // poll-only requests). Delivery status is not the enrichment outcome.
        if (!in_array($body['webhook_status'] ?? null, ['success', 'failed'], true) || !is_array($payload) || !is_array($payload['people'] ?? null)) return new Result('ambiguous');
        if (($payload['status'] ?? null) !== 'success') return new Result('failed');
        $people = $payload['people'];
        if (count($people) !== 1 || ($people[0]['id'] ?? null) !== $subjectId) return new Result('ambiguous');
        if (!is_array($people[0]['phone_numbers'] ?? null)) return new Result('ambiguous');
        $phones = [];
        foreach ($people[0]['phone_numbers'] as $phone) {
            if (!is_array($phone) || in_array($phone['status_cd'] ?? null, ['invalid_number', 'invalid'], true)) continue;
            $phones[] = $phone['sanitized_number'] ?? $phone['raw_number'] ?? null;
        }
        $emails = is_array($people[0]['personal_emails'] ?? null) ? $people[0]['personal_emails'] : [];
        if (!in_array(strtolower((string) ($people[0]['email_status'] ?? '')), ['invalid', 'unavailable'], true)) $emails[] = $people[0]['email'] ?? null;
        $emails = array_filter($emails, static fn ($email) => $email !== 'email_not_unlocked@domain.com');
        return $this->result($emails, $phones, 'phone');
    }

    private static function requestId(mixed $id): ?string
    {
        if (is_int($id)) $id = (string) $id;
        return is_string($id) && preg_match('/^-?[0-9]{1,19}$/D', $id) ? $id : null;
    }

}
