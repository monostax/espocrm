<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

/**
 * Twilio Lookup v2 Identity Match adapter and response evaluator (D3, D4, D6, D9, D12, D15).
 * Neither the browser nor Twilio's summary_score determines the customer charge or badge.
 */
class TwilioLookup
{
    private const ALLOWED_MATCH_VALUES = [
        'exact_match',
        'high_partial_match',
        'partial_match',
        'no_match',
        'not_available',
    ];

    private const COMPARISON_VALUES = [
        'exact_match',
        'high_partial_match',
        'partial_match',
        'no_match',
    ];

    public function __construct(
        private TwilioCredentials $credentials,
        private TwilioTransport $transport,
    ) {}

    /**
     * @param array{phone: string, firstName: string, lastName: string, dateOfBirth?: ?string} $identity
     */
    public function verify(#[\SensitiveParameter] array $identity, int $timeoutMs = 30000): VerificationResult
    {
        $creds = $this->credentials->get();
        if (!$creds) {
            return VerificationResult::failed('credentials_unavailable');
        }
        $e164 = BrazilianPhone::e164($identity['phone'] ?? null);
        $firstName = is_string($identity['firstName'] ?? null) ? trim($identity['firstName']) : '';
        $lastName = is_string($identity['lastName'] ?? null) ? trim($identity['lastName']) : '';
        $dob = $identity['dateOfBirth'] ?? null;
        if ($e164 === null || $firstName === '' || $lastName === '') {
            return VerificationResult::failed('invalid_identity_input');
        }
        $dobParam = null;
        if ($dob !== null && $dob !== '') {
            if (!is_string($dob) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $dob)) {
                return VerificationResult::failed('invalid_dob_input');
            }
            $dobParam = IdentityInput::twilioDateOfBirth($dob);
        }
        $query = [
            'Fields' => 'identity_match',
            'FirstName' => $firstName,
            'LastName' => $lastName,
        ];
        if ($dobParam !== null) {
            $query['DateOfBirth'] = $dobParam;
        }
        $auth = base64_encode($creds['accountSid'] . ':' . $creds['authToken']);
        $response = $this->transport->send(
            '/v2/PhoneNumbers/' . rawurlencode($e164),
            $query,
            ['Authorization: Basic ' . $auth],
            $timeoutMs,
        );
        return self::evaluate($response->status, $response->data, $e164, $dobParam !== null);
    }

    public static function evaluate(int $status, ?array $body, string $expectedE164, bool $dobSubmitted): VerificationResult
    {
        if ($status === 0) {
            return VerificationResult::failed('provider_timeout');
        }
        if ($status !== 200 || $body === null) {
            return VerificationResult::failed('http_' . $status);
        }
        if (array_key_exists('valid', $body) && $body['valid'] !== true) {
            return VerificationResult::failed('invalid_phone_response');
        }
        if (!is_string($body['phone_number'] ?? null) || BrazilianPhone::e164($body['phone_number']) !== $expectedE164) {
            return VerificationResult::failed('phone_mismatch');
        }
        $match = $body['identity_match'] ?? null;
        if (!is_array($match) || array_is_list($match) || $match === []) {
            return VerificationResult::failed('missing_identity_match');
        }
        $errorCode = $match['error_code'] ?? null;
        $errorMessage = $match['error_message'] ?? null;
        if ($errorCode !== null || (is_string($errorMessage) && trim($errorMessage) !== '') ||
            ($errorMessage !== null && !is_string($errorMessage))) {
            return VerificationResult::failed('package_error');
        }
        if (!array_key_exists('first_name_match', $match) || !array_key_exists('last_name_match', $match)) {
            return VerificationResult::failed('missing_match_fields');
        }
        if ($dobSubmitted && !array_key_exists('date_of_birth_match', $match)) {
            return VerificationResult::failed('missing_dob_match_field');
        }
        foreach (['first_name_match', 'last_name_match', 'date_of_birth_match'] as $field) {
            if (!array_key_exists($field, $match)) {
                continue;
            }
            $value = $match[$field];
            if ($value !== null && (!is_string($value) || !in_array($value, self::ALLOWED_MATCH_VALUES, true))) {
                return VerificationResult::failed('unsupported_match_value');
            }
        }
        if (!$dobSubmitted && array_key_exists('date_of_birth_match', $match) &&
            !in_array($match['date_of_birth_match'], [null, 'not_available'], true)) {
            return VerificationResult::failed('unexpected_dob_match_value');
        }

        $firstOutcome = $match['first_name_match'] ?? 'not_available';
        $lastOutcome = $match['last_name_match'] ?? 'not_available';
        $dobOutcome = $dobSubmitted ? ($match['date_of_birth_match'] ?? 'not_available') : 'not_checked';

        $fieldOutcomes = [
            'firstName' => $firstOutcome,
            'lastName' => $lastOutcome,
            'dateOfBirth' => $dobOutcome,
        ];

        $matchedFields = [];
        if ($firstOutcome === 'exact_match') {
            $matchedFields[] = 'firstName';
        }
        if ($lastOutcome === 'exact_match') {
            $matchedFields[] = 'lastName';
        }
        if ($dobSubmitted && $dobOutcome === 'exact_match') {
            $matchedFields[] = 'dateOfBirth';
        }

        if (self::hasExactFirstNameOrDobMatch($fieldOutcomes)) {
            return VerificationResult::completed('verified', $matchedFields, $fieldOutcomes);
        }

        $submittedOutcomes = [$firstOutcome, $lastOutcome, ...($dobSubmitted ? [$dobOutcome] : [])];
        $hasComparisonEvidence = false;
        foreach ($submittedOutcomes as $outcome) {
            if (in_array($outcome, self::COMPARISON_VALUES, true)) {
                $hasComparisonEvidence = true;
                break;
            }
        }

        return VerificationResult::completed(
            $hasComparisonEvidence ? 'not_verified' : 'insufficient_data',
            $matchedFields,
            $fieldOutcomes,
        );
    }

    public static function hasExactFirstNameOrDobMatch(?array $fieldOutcomes): bool
    {
        return ($fieldOutcomes['firstName'] ?? null) === 'exact_match' ||
            ($fieldOutcomes['dateOfBirth'] ?? null) === 'exact_match';
    }
}
