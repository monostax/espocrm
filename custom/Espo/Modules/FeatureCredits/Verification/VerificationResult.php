<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

/**
 * Evaluated identity-match outcome and billing classification (D4, D6, Section 5).
 */
final readonly class VerificationResult
{
    /**
     * @param list<string> $matchedFields
     * @param ?array{firstName: string, lastName: string, dateOfBirth: string} $fieldOutcomes
     */
    public function __construct(
        public bool $billable,
        public string $outcome,
        public array $matchedFields = [],
        public ?array $fieldOutcomes = null,
        public ?string $failureReason = null,
    ) {}

    /**
     * @param list<string> $matchedFields
     * @param array{firstName: string, lastName: string, dateOfBirth: string} $fieldOutcomes
     */
    public static function completed(string $outcome, array $matchedFields, array $fieldOutcomes): self
    {
        if (!in_array($outcome, ['verified', 'not_verified', 'insufficient_data'], true)) {
            throw new \InvalidArgumentException('Unsupported completed verification outcome.');
        }
        return new self(true, $outcome, $matchedFields, $fieldOutcomes, null);
    }

    public static function failed(string $reason = 'provider_failed'): self
    {
        return new self(false, 'failed', [], null, $reason);
    }
}
