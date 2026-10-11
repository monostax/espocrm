<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use InvalidArgumentException;
use stdClass;

/** Detached service-only mailbox commands; no caller-provided prices or delivery receipts. */
final readonly class DispatchInput
{
    public stdClass $data;
    public string $tenantId;

    public function __construct(stdClass $input)
    {
        $this->data = json_decode(self::json($input), false, 32, JSON_THROW_ON_ERROR);
        $d = $this->data;
        $fields = match ($d->operation ?? null) {
            'claimIntent' => ['operation', 'intent'],
            'bind' => ['operation', 'binding'],
            'load' => ['operation', 'scope', 'requestKey'],
            'enqueue' => ['operation', 'binding', 'facts', 'phase'],
            'deliver' => ['operation', 'tenantId', 'leaseMs', 'retryMs'],
            'seal', 'recover' => ['operation', 'scope'],
            'recoverOne' => ['operation', 'tenantId', 'leaseMs', 'retryMs'],
            default => throw new InvalidArgumentException('Unsupported credit dispatch command.'),
        };
        self::fields($d, $fields);
        if (in_array($d->operation, ['deliver', 'recoverOne'], true)) {
            self::id($d->tenantId);
            foreach ([$d->leaseMs, $d->retryMs] as $ms) {
                if (!is_int($ms) || $ms < 1000 || $ms > 3600000) throw new InvalidArgumentException('Explicit bounded retry timings required.');
            }
            $this->tenantId = $d->tenantId;
            return;
        }
        if (in_array($d->operation, ['load', 'seal', 'recover'], true)) {
            self::scope($d->scope);
            if ($d->operation === 'load') self::key($d->requestKey);
            $this->tenantId = $d->scope->tenantId;
            return;
        }
        $intent = $d->operation === 'claimIntent' ? $d->intent : $d->binding;
        self::intent($intent, $d->operation !== 'claimIntent');
        $this->tenantId = $intent->scope->tenantId;
        if ($d->operation === 'enqueue') {
            if (!in_array($d->phase, [0, 1], true) || !$d->facts instanceof stdClass) throw new InvalidArgumentException('Invalid delivery facts or phase.');
            $terminal = self::terminal($intent->scope, $d->facts);
            if ($terminal->outcome->requestId !== $intent->requestId) throw new InvalidArgumentException('Outcome request must match binding.');
        }
    }

    public static function fields(mixed $value, array $required): void
    {
        if (!$value instanceof stdClass || array_diff(array_keys(get_object_vars($value)), $required) ||
            array_diff($required, array_keys(get_object_vars($value)))) throw new InvalidArgumentException('Invalid dispatch command fields.');
    }

    public static function scope(mixed $scope): void
    {
        self::fields($scope, ['billingRegime', 'tenantId', 'usageId', 'runId', 'workflowRunId', 'executionId']);
        self::id($scope->tenantId); self::id($scope->usageId);
        if ($scope->billingRegime !== ExecutionRouting::UNIFIED || !is_string($scope->runId) ||
            !is_string($scope->workflowRunId) || !is_string($scope->executionId)) throw new InvalidArgumentException('Unified execution scope required.');
        new ExecutionIdentity($scope->runId, $scope->workflowRunId, $scope->executionId);
    }

    public static function intent(mixed $intent, bool $binding = false): void
    {
        self::fields($intent, $binding ? ['scope', 'requestKey', 'requestHash', 'policy', 'kind', 'requestId'] :
            ['scope', 'requestKey', 'requestHash', 'policy', 'kind']);
        self::scope($intent->scope); self::key($intent->requestKey);
        if ($binding) self::id($intent->requestId);
        if (!is_string($intent->requestHash) || !preg_match('/^[a-f0-9]{64}$/D', $intent->requestHash) ||
            !in_array($intent->kind, ['generate', 'stream'], true)) throw new InvalidArgumentException('Invalid prepared request identity.');
        $p = $intent->policy;
        self::fields($p, ['modelRateId', 'provider', 'model', 'inputTokenBound', 'outputTokenLimit', 'boundProfile']);
        if (!is_string($p->modelRateId) || !preg_match('/^[a-f0-9]{64}$/D', $p->modelRateId)) throw new InvalidArgumentException('Content-addressed policy required.');
        foreach (['provider' => 64, 'model' => 128, 'boundProfile' => 64] as $key => $max) {
            if (!is_string($p->$key) || strlen($p->$key) > $max || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:\/-]*$/D', $p->$key)) throw new InvalidArgumentException('Invalid provider policy.');
        }
        foreach ([$p->inputTokenBound, $p->outputTokenLimit] as $count) {
            if (!is_int($count) || $count <= 0 || $count > 9007199254740991) throw new InvalidArgumentException('Invalid token bound.');
        }
    }

    public static function terminal(stdClass $scope, stdClass $facts): TerminalInput
    {
        // Validate facts separately: they must not override ownership fields.
        $allowed = ['requestId', 'outcome', 'inputTokens', 'cachedInputTokens', 'outputTokens', 'evidence', 'providerRequestId'];
        if (array_diff(array_keys(get_object_vars($facts)), $allowed)) throw new InvalidArgumentException('Unexpected outcome fields.');
        return new TerminalInput((object) [...get_object_vars($scope), ...get_object_vars($facts), 'operation' => 'outcome']);
    }

    public static function json(mixed $value): string
    {
        $json = json_encode(GrantInput::canonical($value), JSON_THROW_ON_ERROR);
        if (strlen($json) > 60000) throw new InvalidArgumentException('Dispatch record is too large.');
        return $json;
    }

    private static function id(mixed $value): void
    {
        if (!is_string($value)) throw new InvalidArgumentException('Entity identity required.');
        GrantInput::identity($value);
    }

    private static function key(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $value)) throw new InvalidArgumentException('Canonical request key required.');
    }
}
