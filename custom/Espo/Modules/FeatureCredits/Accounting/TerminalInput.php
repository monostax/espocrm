<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use InvalidArgumentException;
use stdClass;

/** Strict worker wire contract. Tenant attribution is asserted by the signed trusted worker. */
final readonly class TerminalInput
{
    public string $operation;
    public string $tenantId;
    public string $usageId;
    public ExecutionIdentity $execution;
    public ?OutcomeInput $outcome;

    public function __construct(stdClass $input)
    {
        $common = ['operation', 'billingRegime', 'tenantId', 'usageId', 'runId', 'workflowRunId', 'executionId'];
        foreach ($common as $field) {
            if (!is_string($input->$field ?? null)) throw new InvalidArgumentException('Terminal command identities are required.');
        }
        if ($input->billingRegime !== ExecutionRouting::UNIFIED || !in_array($input->operation, ['outcome', 'settle', 'release'], true)) {
            throw new InvalidArgumentException('A unified terminal operation is required.');
        }
        $outcomeFields = ['requestId', 'outcome', 'inputTokens', 'cachedInputTokens', 'outputTokens', 'evidence', 'providerRequestId'];
        $allowed = $input->operation === 'outcome' ? [...$common, ...$outcomeFields] : $common;
        if (array_diff(array_keys(get_object_vars($input)), $allowed)) throw new InvalidArgumentException('Unexpected terminal command fields.');
        GrantInput::identity($input->tenantId);
        GrantInput::identity($input->usageId);
        $this->operation = $input->operation;
        $this->tenantId = $input->tenantId;
        $this->usageId = $input->usageId;
        $this->execution = new ExecutionIdentity($input->runId, $input->workflowRunId, $input->executionId);
        if ($this->operation !== 'outcome') {
            $this->outcome = null;
            return;
        }
        foreach (['inputTokens', 'cachedInputTokens', 'outputTokens'] as $field) {
            if (!property_exists($input, $field) || ($input->$field !== null &&
                (!is_int($input->$field) || $input->$field < 0 || $input->$field > 9007199254740991))) {
                throw new InvalidArgumentException('Explicit safe integer or null metering is required.');
            }
        }
        if (!is_string($input->requestId ?? null) || !is_string($input->outcome ?? null) ||
            !($input->evidence ?? null) instanceof stdClass ||
            (isset($input->providerRequestId) && !is_string($input->providerRequestId))) {
            throw new InvalidArgumentException('Request identity, outcome, and authoritative evidence are required.');
        }
        $this->outcome = new OutcomeInput($this->tenantId, $this->usageId, $this->execution->executionId,
            $input->requestId, $input->outcome, $input->inputTokens, $input->cachedInputTokens, $input->outputTokens,
            $input->evidence, $input->providerRequestId ?? null);
    }
}
