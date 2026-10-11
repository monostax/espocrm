<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use InvalidArgumentException;
use stdClass;

final readonly class AuthorizationInput
{
    public string $operation;
    public string $tenantId;
    public string $modelRateId;
    public ExecutionIdentity $execution;
    public ?string $billingRateId;
    public ?string $usageId;
    public ?string $requestKey;
    public ?SourceReference $source;

    public function __construct(stdClass $input)
    {
        $fields = ['operation', 'billingRegime', 'tenantId', 'modelRateId', 'runId', 'workflowRunId', 'executionId'];
        if (!in_array($input->operation ?? null, ['admit', 'authorize'], true) ||
            ($input->billingRegime ?? null) !== ExecutionRouting::UNIFIED) {
            throw new InvalidArgumentException('Unified admission or request authorization required.');
        }
        $fields = [...$fields, ...($input->operation === 'admit' ? ['billingRateId'] : ['usageId', 'requestKey'])];
        $optional = $input->operation === 'admit' ? ['source'] : [];
        if (array_diff(array_keys(get_object_vars($input)), [...$fields, ...$optional])) throw new InvalidArgumentException('Unexpected authorization fields.');
        foreach ($fields as $field) {
            if (!is_string($input->$field ?? null)) throw new InvalidArgumentException('Complete authorization identities required.');
        }
        GrantInput::identity($input->tenantId);
        if (!preg_match('/^[a-f0-9]{64}$/D', $input->modelRateId)) throw new InvalidArgumentException('Immutable model policy reference required.');
        $this->execution = new ExecutionIdentity($input->runId, $input->workflowRunId, $input->executionId);
        $this->operation = $input->operation;
        $this->tenantId = $input->tenantId;
        $this->modelRateId = $input->modelRateId;
        $this->billingRateId = $input->billingRateId ?? null;
        $this->usageId = $input->usageId ?? null;
        $this->requestKey = $input->requestKey ?? null;
        $source = null;
        if (property_exists($input, 'source')) {
            if (!$input->source instanceof stdClass ||
                array_diff(array_keys(get_object_vars($input->source)), ['type', 'id', 'requestId']) ||
                !is_string($input->source->type ?? null) || !is_string($input->source->id ?? null) ||
                (property_exists($input->source, 'requestId') && !is_string($input->source->requestId))) {
                throw new InvalidArgumentException('A complete source reference is required.');
            }
            $source = new SourceReference($input->source->type, $input->source->id, $input->source->requestId ?? null);
        }
        $this->source = $source;
        GrantInput::identity($this->billingRateId ?? $this->usageId);
        if ($this->requestKey !== null && !preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $this->requestKey)) {
            throw new InvalidArgumentException('Canonical request identity required.');
        }
    }
}
