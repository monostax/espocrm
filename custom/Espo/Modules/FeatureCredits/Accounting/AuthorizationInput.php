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

    public function __construct(stdClass $input)
    {
        $fields = ['operation', 'billingRegime', 'tenantId', 'modelRateId', 'runId', 'workflowRunId', 'executionId'];
        if (!in_array($input->operation ?? null, ['admit', 'authorize'], true) ||
            ($input->billingRegime ?? null) !== ExecutionRouting::UNIFIED) {
            throw new InvalidArgumentException('Unified admission or request authorization required.');
        }
        $fields = [...$fields, ...($input->operation === 'admit' ? ['billingRateId'] : ['usageId', 'requestKey'])];
        if (array_diff(array_keys(get_object_vars($input)), $fields)) throw new InvalidArgumentException('Unexpected authorization fields.');
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
        GrantInput::identity($this->billingRateId ?? $this->usageId);
        if ($this->requestKey !== null && !preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $this->requestKey)) {
            throw new InvalidArgumentException('Canonical request identity required.');
        }
    }
}
