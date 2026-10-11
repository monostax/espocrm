<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use InvalidArgumentException;
use stdClass;

/** Normalized, authoritative request result supplied by a trusted execution/reconciliation adapter. */
final readonly class OutcomeInput
{
    public string $json;
    public string $hash;

    public function __construct(
        public string $tenantId,
        public string $usageId,
        public string $executionId,
        public string $requestId,
        public string $outcome,
        public ?int $inputTokens,
        public ?int $cachedInputTokens,
        public ?int $outputTokens,
        stdClass $evidence,
        public ?string $providerRequestId = null,
    ) {
        foreach ([$tenantId, $usageId, $requestId] as $id) {
            GrantInput::identity($id);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $executionId) ||
            !in_array($outcome, ['success', 'cancelled', 'superseded', 'infrastructureFailure'], true)) {
            throw new InvalidArgumentException('Valid execution identity and terminal AI outcome required.');
        }
        foreach ([$inputTokens, $cachedInputTokens, $outputTokens] as $tokens) {
            if ($tokens !== null && $tokens < 0) {
                throw new InvalidArgumentException('Token counts must be nonnegative or unknown.');
            }
        }
        if ($cachedInputTokens !== null && $inputTokens !== null && $cachedInputTokens > $inputTokens) {
            throw new InvalidArgumentException('Cached input cannot exceed total input.');
        }
        if ($providerRequestId !== null && (trim($providerRequestId) === '' || strlen($providerRequestId) > 128)) {
            throw new InvalidArgumentException('Invalid provider request identity.');
        }
        if (get_object_vars($evidence) === []) {
            throw new InvalidArgumentException('Authoritative outcome evidence is required.');
        }
        $this->json = json_encode(GrantInput::canonical((object) [
            'version' => 'ai-outcome-v1', 'tenantId' => $tenantId, 'usageId' => $usageId,
            'executionId' => $executionId, 'requestId' => $requestId, 'outcome' => $outcome,
            'inputTokens' => $inputTokens, 'cachedInputTokens' => $cachedInputTokens, 'outputTokens' => $outputTokens,
            'providerRequestId' => $providerRequestId, 'evidence' => $evidence,
        ]), JSON_THROW_ON_ERROR);
        $this->hash = hash('sha256', $this->json);
    }

    public function measured(): bool
    {
        return $this->inputTokens !== null && $this->cachedInputTokens !== null && $this->outputTokens !== null;
    }
}
