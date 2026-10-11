<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Pricing\AiModelPolicy;

final class ExecutionFixtures
{
    public static function outcome(): object
    {
        return (object) ['operation' => 'outcome', 'billingRegime' => 'unified-prepaid-v1', 'tenantId' => 'tenant',
            'usageId' => 'usage', 'runId' => 'aaaaaaaaaaaaaaaaa', 'workflowRunId' => 'workflow',
            'executionId' => '00000000-0000-4000-8000-000000000000', 'requestId' => 'request', 'outcome' => 'success',
            'inputTokens' => 0, 'cachedInputTokens' => 0, 'outputTokens' => 1, 'evidence' => (object) ['source' => 'provider-response']];
    }

    public static function policy(): array
    {
        return ['provider' => 'fixture-provider', 'model' => 'fixture-model', 'multiplier' => '1',
            'inputTokenBound' => 1000, 'outputTokenLimit' => 1000, 'boundProfile' => 'fixture-text-v1'];
    }

    public static function admission(): object
    {
        $input = self::outcome();
        foreach (['usageId', 'requestId', 'outcome', 'inputTokens', 'cachedInputTokens', 'outputTokens', 'evidence'] as $field) unset($input->$field);
        $input->operation = 'admit'; $input->billingRateId = 'rate';
        $input->modelRateId = (new AiModelPolicy(self::policy()))->rate->id;
        return $input;
    }
}
