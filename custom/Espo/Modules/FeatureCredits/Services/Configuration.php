<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Services;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\RecordId;
use Espo\Modules\FeatureCredits\Accounting\NativeSearchDebt;
use Espo\Modules\FeatureCredits\Pricing\AiCatalog;
use Espo\Modules\FeatureCredits\Pricing\AiModelPolicy;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use stdClass;
use Throwable;

/** Trusted, versioned configuration writer. No balance, grant or cutover writes.
 * Published prices are immutable; a new non-overlapping agreement selects new
 * pricing. Existing admitted operations keep their original agreement and rate. */
final class Configuration
{
    public function __construct(private EntityManager $entityManager, private Clock $clock, private AiCatalog $catalog) {}

    public function publishModel(stdClass $definition, ?string $actorId = null): array
    {
        $policy = new AiModelPolicy(get_object_vars($definition));
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) throw new LogicException('Policy publication requires its own write boundary.');
        if ($actorId !== null) GrantInput::identity($actorId);
        $read = function () use ($pdo, $policy): array|false {
            $q = $pdo->prepare('SELECT id, policy_id, deleted FROM ai_model_credit_rate WHERE policy_id=?');
            $q->execute([$policy->rate->id]);
            return $q->fetch(PDO::FETCH_ASSOC);
        };
        if (!$row = $read()) {
            try {
                $pdo->prepare('INSERT INTO ai_model_credit_rate (id, name, policy_id, definition, created_at, created_by_id)
                    VALUES (?,?,?,?,?,?)')->execute([RecordId::generate(), $policy->rate->model, $policy->rate->id,
                        json_encode(GrantInput::canonical($definition), JSON_THROW_ON_ERROR), $this->clock->now(), $actorId]);
            } catch (PDOException $e) {
                // The unique policy hash serializes simultaneous publication of
                // identical definitions without a separate mutable catalog lock.
                if (!in_array($e->getCode(), ['23000', '23505'], true) || !$read()) throw $e;
            }
            $row = $read();
        }
        if (!$row || $row['deleted']) throw new Conflict('Deleted model policy cannot be republished.');
        $this->catalog->resolve($policy->rate->id); // Detect a corrupt/edited definition.
        return ['id' => $row['id'], 'policyId' => $policy->rate->id, 'policy' => $policy->receipt()];
    }

    public function publishAgreement(stdClass $input, ?string $actorId = null, ?string $replacesId = null): array
    {
        $fields = ['tenantId', 'version', 'effectiveFrom', 'effectiveUntil', 'currency', 'creditUnitPrice',
            'monthlyCredits', 'aiModelCreditRateId', 'aiMaxRequests'];
        if (array_diff(array_keys(get_object_vars($input)), [...$fields, 'aiNativeSearchModelCreditRateId']) || array_diff($fields, array_keys(get_object_vars($input)))) {
            throw new InvalidArgumentException('Complete agreement required.');
        }
        foreach (['tenantId', 'aiModelCreditRateId'] as $field) {
            if (!is_string($input->$field)) throw new InvalidArgumentException('Invalid agreement identity.');
            GrantInput::identity($input->$field);
        }
        $nativeId = $input->aiNativeSearchModelCreditRateId ?? null;
        if ($nativeId !== null) {
            if (!is_string($nativeId)) throw new InvalidArgumentException('Invalid native-search policy identity.');
            GrantInput::identity($nativeId);
        }
        if ($actorId !== null) GrantInput::identity($actorId);
        if ($replacesId !== null) GrantInput::identity($replacesId);
        if (!is_string($input->version) || !preg_match('/^[a-zA-Z0-9._:-]{1,64}$/D', $input->version) ||
            !is_string($input->currency) || !preg_match('/^[A-Z]{3}$/D', $input->currency) ||
            !is_int($input->aiMaxRequests) || $input->aiMaxRequests < 1 || $input->aiMaxRequests > 100) {
            throw new InvalidArgumentException('Invalid agreement version/currency/request ceiling.');
        }
        if (!is_string($input->effectiveFrom) || ($input->effectiveUntil !== null && !is_string($input->effectiveUntil))) {
            throw new InvalidArgumentException('UTC agreement timestamps required.');
        }
        GrantInput::timestamp($input->effectiveFrom);
        if ($input->effectiveUntil !== null) {
            GrantInput::timestamp($input->effectiveUntil);
            if ($input->effectiveUntil <= $input->effectiveFrom) throw new InvalidArgumentException('Agreement end must follow its start.');
        }
        if (!is_string($input->creditUnitPrice) || !preg_match('/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,8})?$/D', $input->creditUnitPrice)) {
            throw new InvalidArgumentException('Exact positive credit unit price required.');
        }
        $price = BigDecimal::of($input->creditUnitPrice)->toScale(8);
        if ($price->isLessThanOrEqualTo(0)) throw new InvalidArgumentException('Positive credit unit price required.');
        $monthly = Amount::fromString($input->monthlyCredits);
        if ($monthly->compareTo(Amount::fromString('0')) < 0) throw new InvalidArgumentException('Negative monthly credits.');
        $owned = clone $input; $owned->creditUnitPrice = (string) $price; $owned->monthlyCredits = (string) $monthly;
        $owned->replacesId = $replacesId;
        $hash = hash('sha256', json_encode(GrantInput::canonical($owned), JSON_THROW_ON_ERROR));
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) throw new LogicException('Agreement publication owns its transaction.');
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT id FROM tenant WHERE id=? AND deleted=FALSE FOR UPDATE');
            $q->execute([$input->tenantId]);
            if (!$q->fetchColumn()) throw new NotFound('Unknown agreement tenant.');
            $q = $pdo->prepare('SELECT id, configuration_hash, deleted FROM tenant_credit_billing_rate WHERE tenant_id=? AND version=?');
            $q->execute([$input->tenantId, $input->version]);
            $existing = $q->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['deleted'] || $existing['configuration_hash'] !== $hash) throw new Conflict('Agreement version already has different terms.');
                $pdo->commit();
                return ['id' => $existing['id'], 'version' => $input->version];
            }
            $q = $pdo->prepare('SELECT policy_id FROM ai_model_credit_rate WHERE id=? AND deleted=FALSE');
            $q->execute([$input->aiModelCreditRateId]);
            $policyId = $q->fetchColumn();
            if (!is_string($policyId)) throw new NotFound('Unknown model policy.');
            $this->catalog->resolve($policyId);
            if ($nativeId !== null) {
                $q->execute([$nativeId]);
                $nativePolicyId = $q->fetchColumn();
                if (!is_string($nativePolicyId)) throw new NotFound('Unknown native-search policy.');
                $native = $this->catalog->resolve($nativePolicyId);
                if (!NativeSearchDebt::permitted($native->receipt())) throw new Conflict('An explicit native-search overrun policy is required.');
            }
            if ($replacesId !== null) {
                $q = $pdo->prepare('SELECT effective_from, effective_until FROM tenant_credit_billing_rate WHERE id=? AND tenant_id=? AND deleted=FALSE');
                $q->execute([$replacesId, $input->tenantId]);
                $old = $q->fetch(PDO::FETCH_ASSOC);
                if (!$old || $input->effectiveFrom < $this->clock->now() || $input->effectiveFrom <= $old['effective_from'] ||
                    ($old['effective_until'] !== null && $old['effective_until'] < $input->effectiveFrom)) {
                    throw new Conflict('Replacement must prospectively close an existing same-tenant agreement.');
                }
                // Only the future selection window changes. No prior price,
                // admitted usage, reservation or grant is rewritten.
                $pdo->prepare('UPDATE tenant_credit_billing_rate SET effective_until=? WHERE id=?')
                    ->execute([$input->effectiveFrom, $replacesId]);
            }
            $q = $pdo->prepare('SELECT id FROM tenant_credit_billing_rate WHERE tenant_id=? AND deleted=FALSE
                AND (effective_until IS NULL OR effective_until>?)' . ($input->effectiveUntil === null ? '' : ' AND effective_from<?'));
            $q->execute([$input->tenantId, $input->effectiveFrom, ...($input->effectiveUntil === null ? [] : [$input->effectiveUntil])]);
            if ($q->fetchColumn()) throw new Conflict('Agreement windows cannot overlap.');
            $id = RecordId::generate();
            $pdo->prepare('INSERT INTO tenant_credit_billing_rate
                (id, tenant_id, version, effective_from, effective_until, currency, credit_unit_price, monthly_credits,
                  ai_model_credit_rate_id, ai_max_requests, configuration_hash, created_at, created_by_id, ai_native_search_model_credit_rate_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id, $input->tenantId, $input->version, $input->effectiveFrom,
                    $input->effectiveUntil, $input->currency, (string) $price, (string) $monthly, $input->aiModelCreditRateId,
                    $input->aiMaxRequests, $hash, $this->clock->now(), $actorId, $nativeId]);
            $pdo->commit();
            return ['id' => $id, 'version' => $input->version];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
