<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use InvalidArgumentException;
use JsonException;
use PDO;
use stdClass;

/**
 * What a request may hold: the input size and output cap its adapter proved before dispatch, read from
 * the intent it stored first (DispatchStore claimIntent). A stored intent is immutable, so a recovery
 * that authorizes the same key again prices the same hold. No intent, no measurement, another policy
 * or execution, or the native-search overrun profile: the request holds the policy ceilings.
 */
final class MeasuredHolds
{
    /** @return array{inputTokens: int, outputTokens: int}|null */
    public static function forRequest(PDO $pdo, AuthorizationInput $input, string $boundProfile): ?array
    {
        if ($boundProfile === NativeSearchDebt::PROFILE || $input->usageId === null || $input->requestKey === null) return null;
        $query = $pdo->prepare('SELECT intent FROM credit_execution_attempt WHERE usage_id=? AND request_key=? AND tenant_id=? AND deleted=FALSE');
        $query->execute([$input->usageId, $input->requestKey, $input->tenantId]);
        $raw = $query->fetchColumn();
        if (!is_string($raw)) return null;
        try {
            $intent = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            if (!$intent instanceof stdClass) throw new InvalidArgumentException('Dispatch object required.');
            DispatchInput::intent($intent);
        } catch (JsonException | InvalidArgumentException) {
            throw new Conflict('Invalid stored dispatch record.');
        }
        if (!isset($intent->hold) || $intent->policy->modelRateId !== $input->modelRateId ||
            $intent->scope->executionId !== $input->execution->executionId) return null;
        return ['inputTokens' => $intent->hold->inputTokens, 'outputTokens' => $intent->hold->outputTokens];
    }
}
