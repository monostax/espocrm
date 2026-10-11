<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Modules\FeatureCredits\Pricing\AiCatalog;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use stdClass;
use Throwable;

/** Resolve CRM agreement/model references before admission, without creating a
 * wallet or reserving funds. Immutable agreements/policies prevent repricing;
 * Reservations revalidates the agreement and route inside its own transaction. */
final class AdmissionConfiguration
{
    public function __construct(private EntityManager $entityManager, private Clock $clock, private AiCatalog $catalog) {}

    public function resolve(stdClass $input): array
    {
        $fields = ['operation', 'billingRegime', 'tenantId', 'runId', 'workflowRunId', 'executionId', 'provider', 'model'];
        if (array_diff(array_keys(get_object_vars($input)), $fields) || array_diff($fields, array_keys(get_object_vars($input)))) {
            throw new InvalidArgumentException('Complete configuration scope required.');
        }
        foreach ($fields as $field) if (!is_string($input->$field)) throw new InvalidArgumentException('Invalid configuration identity.');
        if ($input->operation !== 'configuration' || $input->billingRegime !== ExecutionRouting::UNIFIED) throw new InvalidArgumentException('Invalid configuration operation.');
        GrantInput::identity($input->tenantId);
        $identity = new ExecutionIdentity($input->runId, $input->workflowRunId, $input->executionId);
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) throw new LogicException('Configuration resolution owns its transaction.');
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare('SELECT id FROM tenant WHERE id=? AND deleted=FALSE FOR UPDATE');
            $query->execute([$input->tenantId]);
            if (!$query->fetchColumn()) throw new NotFound('Unknown accounting tenant.');
            $now = $this->clock->now();
            $routing = ExecutionRouting::unifiedLocked($pdo, $input->tenantId, $identity, $now);
            if ($routing['route']) {
                $query = $pdo->prepare('SELECT r.* FROM tenant_credit_billing_rate r JOIN credit_usage u ON u.billing_rate_id=r.id
                    WHERE u.id=? AND u.tenant_id=? AND u.deleted=FALSE AND r.tenant_id=? AND r.deleted=FALSE');
                $query->execute([$routing['route']['usage_id'], $input->tenantId, $input->tenantId]);
            } else {
                $query = $pdo->prepare('SELECT * FROM tenant_credit_billing_rate WHERE tenant_id=? AND deleted=FALSE
                    AND effective_from<=? AND (effective_until IS NULL OR effective_until>?)');
                $query->execute([$input->tenantId, $now, $now]);
            }
            $rates = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rates) !== 1) throw new Conflict('Exactly one CRM agreement is required.');
            $rate = $rates[0];
            $max = (int) ($rate['ai_max_requests'] ?? 0);
            if (!$rate['ai_model_credit_rate_id'] || $max < 1 || $max > 100) throw new ServiceUnavailable('CRM agreement has no approved AI execution policy.');
            $query = $pdo->prepare('SELECT policy_id FROM ai_model_credit_rate WHERE id=? AND deleted=FALSE');
            $query->execute([$rate['ai_model_credit_rate_id']]);
            $policyId = $query->fetchColumn();
            if (!is_string($policyId)) throw new ServiceUnavailable('Approved model rate is unavailable.');
            $policy = $this->catalog->resolve($policyId);
            if ($policy->rate->provider !== $input->provider || $policy->rate->model !== $input->model) throw new Conflict('Configured model does not match this caller.');
            $result = ['billingRateId' => $rate['id'], 'modelRateId' => $policyId, 'maxRequests' => $max];
            if ($rate['ai_native_search_model_credit_rate_id'] ?? null) {
                $query->execute([$rate['ai_native_search_model_credit_rate_id']]);
                $nativePolicyId = $query->fetchColumn();
                if (!is_string($nativePolicyId)) throw new ServiceUnavailable('Native-search policy unavailable.');
                $native = $this->catalog->resolve($nativePolicyId);
                if (!NativeSearchDebt::permitted($native->receipt())) throw new Conflict('Invalid native-search policy.');
                $result['nativeSearchPolicy'] = $native->receipt();
            }
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
