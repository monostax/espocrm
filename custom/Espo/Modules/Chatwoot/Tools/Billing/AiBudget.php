<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Billing;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Accounting\ExecutionIdentity;
use Espo\Modules\FeatureCredits\Accounting\ExecutionRouting;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder;

/** Tenant-wide admission, independent of asynchronous usage-event persistence.
 * Lock order is always Tenant, then reservation. No TTL: an uncertain/crashed
 * execution retains its slot until its terminal usage is reconciled.
 */
final class AiBudget
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
    ) {}

    /** Same application-timezone calendar boundaries as the AI Usage ledger. */
    public static function period(DateTimeImmutable $now, string $timezone): array
    {
        $local = $now->setTimezone(new DateTimeZone($timezone));
        $start = $local->modify('first day of this month')->setTime(0, 0);
        return [
            'period' => $local->format('Y-m'),
            'day' => $local->format('Y-m-d'),
            'start' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end' => $start->modify('+1 month')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    public function execute(object $input): object
    {
        $operation = $input->operation ?? '';
        if (!in_array($operation, ['status', 'admit', 'settle'], true)) {
            throw new BadRequest('Invalid budget operation.');
        }
        $tenantId = $this->resolveTenant($input);
        if (!$tenantId) {
            return (object) ['allowed' => false, 'reason' => 'ai_budget_scope_unavailable'];
        }

        $result = $this->entityManager->getTransactionManager()->run(function () use ($input, $operation, $tenantId): object {
            // Serializes all accounts/agents in this tenant, on both supported DB dialects.
            $tenant = $this->entityManager->getRDBRepository('Tenant')
                ->where(['id' => $tenantId])->forUpdate()->findOne();
            if (!$tenant) throw new BadRequest('Unknown tenant.');
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $period = self::period($now, $this->config->get('timeZone', 'UTC'));

            $pdo = $this->entityManager->getPDO();
            if ($operation === 'status') {
                $selection = ExecutionRouting::tenantLocked($pdo, $tenantId, $now->format('Y-m-d H:i:s'));
                return $selection['billingRegime'] === ExecutionRouting::UNIFIED ? (object) [
                    ...$selection, 'allowed' => false, 'reason' => 'ai_credit_execution_required',
                ] : (object) [...$selection, ...$this->status($tenantId, $period)];
            }

            foreach (['runId', 'workflowRunId'] as $key) {
                if (!is_string($input->$key ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $input->$key)) {
                    throw new BadRequest('Run and workflow identities are required.');
                }
            }
            if (!preg_match('/^[a-f0-9]{17}$/D', $input->runId)) throw new BadRequest('Invalid run identity.');
            $reservation = $this->entityManager->getRDBRepository('AiUsageReservation')
                ->where(['id' => $input->runId])->findOne();
            if ($reservation && ($reservation->get('tenantId') !== $tenantId ||
                $reservation->get('workflowRunId') !== $input->workflowRunId)) {
                return (object) ['allowed' => false, 'reason' => 'ai_engagement_already_claimed'];
            }

            $route = ExecutionRouting::findLocked($pdo, $tenantId, $input->runId, $input->workflowRunId);
            if ($route && (($reservation !== null) !== ($route['billing_regime'] === ExecutionRouting::LEGACY))) {
                throw new Conflict('Execution route and legacy reservation disagree.');
            }
            if ($operation === 'settle' && !$reservation && !$route) {
                // An old event is not a new admission. Tenant cutover cannot assign
                // it a regime or prevent telemetry persistence by demanding credit settlement.
                return (object) ['settled' => false, 'admittedAt' => null, 'billingRegime' => null, 'reason' => 'ai_budget_not_admitted'];
            }
            $selection = $reservation ? ['billingRegime' => ExecutionRouting::LEGACY, 'cutoverAt' => $route['cutover_at'] ?? null] :
                ($route ? ['billingRegime' => $route['billing_regime'], 'cutoverAt' => $route['cutover_at']] :
                    ExecutionRouting::tenantLocked($pdo, $tenantId, $now->format('Y-m-d H:i:s')));
            if ($selection['billingRegime'] === ExecutionRouting::UNIFIED) {
                // The boolean legacy protocol can neither authorize nor settle credit usage.
                return (object) [...$selection, 'allowed' => false, 'reason' => 'ai_credit_execution_required',
                    ...($operation === 'settle' ? ['settled' => false, 'admittedAt' => null] : [])];
            }
            if (isset($input->billingRegime) && $input->billingRegime !== ExecutionRouting::LEGACY) {
                throw new Conflict('Execution billing regime mismatch.');
            }
            if ($route && $route['execution_id'] !== $reservation->get('executionId')) {
                throw new Conflict('Execution route ownership mismatch.');
            }

            if ($operation === 'settle') {
                // Legacy events/backfills have no reservation. Never invent a new debit.
                if (isset($input->executionId) && $input->executionId !== $reservation->get('executionId')) {
                    throw new Conflict('Execution does not own the legacy admission.');
                }
                if (!is_bool($input->billable ?? null)) throw new BadRequest('A terminal billing outcome is required.');
                $state = $input->billable && !$reservation->get('auxiliary') ? 'consumed' : 'released';
                if ($reservation->get('state') !== 'reserved' && $reservation->get('state') !== $state) {
                    throw new Conflict('The engagement already has a different terminal outcome.');
                }
                if (!$route) ExecutionRouting::recordLocked($pdo, $tenantId,
                    new ExecutionIdentity($input->runId, $input->workflowRunId, $reservation->get('executionId')),
                    ExecutionRouting::LEGACY, $reservation->get('admittedAt'), null, null);
                $reservation->set(['state' => $state, 'settledAt' => $now->format('Y-m-d H:i:s')]);
                $this->entityManager->saveEntity($reservation);
                return (object) ['settled' => true, 'admittedAt' => $reservation->get('admittedAt')];
            }

            if (!is_string($input->executionId ?? null) || !preg_match('/^[a-f0-9-]{36}$/D', $input->executionId)) {
                throw new BadRequest('Execution identity required.');
            }

            if ($reservation) {
                if ($reservation->get('executionId') !== $input->executionId) {
                    return (object) ['allowed' => false, 'reason' => 'ai_engagement_already_claimed'];
                }
                if (!$route) ExecutionRouting::recordLocked($pdo, $tenantId,
                    new ExecutionIdentity($input->runId, $input->workflowRunId, $input->executionId),
                    ExecutionRouting::LEGACY, $reservation->get('admittedAt'), null, null);
                // HTTP retries may recover the same authorization; terminal executions cannot restart.
                return (object) [
                    'allowed' => $reservation->get('state') === 'reserved',
                    'reason' => $reservation->get('state') === 'reserved' ? 'authorized' : 'ai_engagement_already_finished',
                    'admittedAt' => $reservation->get('admittedAt'),
                ];
            }
            $status = $this->status($tenantId, $period);
            if (!$status['allowed']) return (object) $status;

            $reservation = $this->entityManager->getNewEntity('AiUsageReservation');
            $reservation->set('id', $input->runId);
            $reservation->set([
                'tenantId' => $tenantId, 'runId' => $input->runId,
                'workflowRunId' => $input->workflowRunId, 'period' => $period['period'],
                'executionId' => $input->executionId,
                'state' => 'reserved', 'auxiliary' => ($input->auxiliary ?? false) === true,
                'admittedAt' => $now->format('Y-m-d H:i:s'),
            ]);
            $this->entityManager->saveEntity($reservation);
            ExecutionRouting::recordLocked($pdo, $tenantId,
                new ExecutionIdentity($input->runId, $input->workflowRunId, $input->executionId),
                ExecutionRouting::LEGACY, $reservation->get('admittedAt'), $selection['cutoverAt'], null);
            return (object) [...$status, 'admittedAt' => $reservation->get('admittedAt')];
        });
        return (object) ['billingRegime' => ExecutionRouting::LEGACY, ...(array) $result];
    }

    private function resolveTenant(object $input): ?string
    {
        // Legacy personal assistants carry a CRM user rather than a record/account.
        // Never pick an arbitrary tenant for a multi-tenant user.
        if (isset($input->userId) && !isset($input->tenantId) && !isset($input->accountId)) {
            if (!is_string($input->userId)) throw new BadRequest('Invalid user.');
            $user = $this->entityManager->getEntityById('User', $input->userId);
            if (!$user) return null;
            $tenants = $this->entityManager->getRDBRepository('User')->getRelation($user, 'tenants')->limit(0, 2)->find();
            if (count($tenants) !== 1) return null;
            foreach ($tenants as $tenant) return $tenant->getId();
            return null;
        }
        if (isset($input->accountId)) {
            if (!is_int($input->accountId) || $input->accountId <= 0) throw new BadRequest('Invalid account.');
            $account = $this->entityManager->getRDBRepository('ChatwootAccount')
                ->where(['chatwootAccountId' => $input->accountId])->findOne();
            $tenantId = $account?->get('tenantId');
            if (isset($input->tenantId) && $input->tenantId !== $tenantId) throw new BadRequest('Budget scope mismatch.');
            return $tenantId ?: null;
        }
        $id = $input->tenantId ?? null;
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id)) throw new BadRequest('Tenant required.');
        return $id;
    }

    private function status(string $tenantId, array $period): array
    {
        $rates = $this->entityManager->getRDBRepository('TenantAiBillingRate')->where([
            'tenantId' => $tenantId, 'effectiveFrom<=' => $period['day'],
            'OR' => [['effectiveTo' => null], ['effectiveTo>=' => $period['day']]],
        ])->order('effectiveFrom', 'DESC')->order('id', 'DESC')->limit(0, 2)->find();
        if (count($rates) > 1) return ['allowed' => false, 'reason' => 'ai_budget_configuration_required'];
        $rate = null;
        foreach ($rates as $row) $rate = $row;
        if (!$rate) {
            $previous = $this->entityManager->getRDBRepository('TenantAiBillingRate')
                ->where(['tenantId' => $tenantId, 'effectiveFrom<=' => $period['day']])
                ->order('effectiveFrom', 'DESC')->order('id', 'DESC')->findOne();
            // Expiring a capped contract must not silently switch the tenant to unlimited overage.
            if ($previous?->get('overagePolicy') === 'block') {
                return ['allowed' => false, 'reason' => 'ai_budget_configuration_required'];
            }
        }
        $policy = $rate?->get('overagePolicy') ?: 'allow';
        if (!in_array($policy, ['allow', 'block'], true) ||
            ($policy === 'block' && $rate?->get('billingModel') !== 'credit')) {
            return ['allowed' => false, 'reason' => 'ai_budget_configuration_required'];
        }
        $allowance = max(0, (int) ($rate?->get('planIncludedCredits') ?? 0));
        // Exclude ALL reserved identities from historical counts, not just this month:
        // an engagement belongs to its admission month even when it finishes after midnight.
        $reservedIds = SelectBuilder::create()->from('AiUsageReservation')->select('runId')->build();
        $legacy = $this->entityManager->getRDBRepository('ChatwootAiAgentRun')->where([
            'tenantId' => $tenantId, 'runAt>=' => $period['start'], 'runAt<' => $period['end'],
            'id!=s' => $reservedIds,
            'OR' => [['billingWaived' => false], ['billingWaived' => null]],
        ])->where(Cond::notEqual(Expr::create("IFNULL:(AI_RUN_OUTCOME:modelUsage, '')"), 'failed'))->count();
        $base = $this->entityManager->getRDBRepository('AiUsageReservation');
        $reserved = $base->where(['tenantId' => $tenantId, 'period' => $period['period'], 'state' => 'reserved'])->count();
        // A later billing waiver also restores the credit. Missing async run rows
        // still count: admission/settlement, not event delivery, owns the balance.
        $consumed = $base->leftJoin('run', 'budgetRun')->where([
            'tenantId' => $tenantId, 'period' => $period['period'], 'state' => 'consumed',
            'OR' => [['budgetRun.billingWaived' => false], ['budgetRun.billingWaived' => null]],
        ])->count();
        $used = $legacy + $consumed;
        $allowed = $policy !== 'block' || $used + $reserved < $allowance;
        return [
            'allowed' => $allowed, 'reason' => $allowed ? 'authorized' : 'ai_budget_exhausted',
            'policy' => $policy, 'period' => $period['period'], 'included' => $allowance,
            'consumed' => $used, 'reserved' => $reserved,
            'remaining' => max(0, $allowance - $used - $reserved),
            'resetsAt' => str_replace(' ', 'T', $period['end']) . 'Z',
        ];
    }
}
