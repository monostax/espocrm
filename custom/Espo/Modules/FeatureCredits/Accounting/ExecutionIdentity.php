<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use InvalidArgumentException;

/** Shared identity used by the legacy budget authority and unified execution adapters. */
final readonly class ExecutionIdentity
{
    public function __construct(public string $runId, public string $workflowRunId, public string $executionId)
    {
        if (!preg_match('/^[a-f0-9]{17}$/D', $runId) ||
            !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $workflowRunId) ||
            !preg_match('/^[a-f0-9-]{36}$/D', $executionId)) {
            throw new InvalidArgumentException('Valid run, workflow and execution identities required.');
        }
    }

    public function operationKey(): string
    {
        return 'ai-run:' . $this->runId;
    }
}
