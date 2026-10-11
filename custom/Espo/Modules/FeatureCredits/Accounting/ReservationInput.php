<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use InvalidArgumentException;

/** Internal operation hold, not permission to dispatch a provider request. */
final readonly class ReservationInput
{
    public string $credits;
    public string $hash;

    public function __construct(
        public string $tenantId,
        public string $operationType,
        public string $operationKey,
        public string $executionId,
        public string $billingRateId,
        mixed $bound,
        public ?SourceReference $source = null,
        public ?ExecutionIdentity $execution = null,
        public ?string $modelPolicyId = null,
    ) {
        GrantInput::identity($tenantId);
        GrantInput::identity($billingRateId);
        if (!in_array($operationType, ['ai', 'apollo'], true)) {
            throw new InvalidArgumentException('Unsupported credit operation.');
        }
        foreach ([$operationKey, $executionId] as $key) {
            if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $key)) {
                throw new InvalidArgumentException('Canonical operation and execution identities are required.');
            }
        }
        if (($execution !== null && ($operationType !== 'ai' || $operationKey !== $execution->operationKey() ||
            $executionId !== $execution->executionId)) || ($execution === null && str_starts_with($operationKey, 'ai-run:'))) {
            throw new InvalidArgumentException('Execution admissions require a matching canonical AI run identity.');
        }
        $amount = Amount::fromString($bound);
        if ($amount->compareTo(Amount::fromString('0')) <= 0) {
            throw new InvalidArgumentException('A positive conservative bound is required.');
        }
        $this->credits = (string) ($operationType === 'ai' && $amount->compareTo(Amount::fromString('0.5000')) < 0 ?
            Amount::fromString('0.5000') : $amount);
        $identity = [
            'reservation-v1', $tenantId, $operationType, $operationKey, $executionId,
            $billingRateId, (string) $amount, $this->credits,
        ];
        // Preserve hashes of pre-attribution operations exactly.
        if ($source !== null) {
            $identity[0] = 'reservation-v2';
            $identity[] = [$source->type, $source->id];
        }
        if ($execution !== null) {
            $identity[0] = 'reservation-v3';
            $identity[] = [$execution->runId, $execution->workflowRunId, $execution->executionId];
        }
        if ($modelPolicyId !== null) {
            if ($execution === null || !preg_match('/^[a-f0-9]{64}$/D', $modelPolicyId)) {
                throw new InvalidArgumentException('An execution-bound immutable initial model policy is required.');
            }
            $identity[0] = 'reservation-v4';
            $identity[] = $modelPolicyId;
        }
        $this->hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}
