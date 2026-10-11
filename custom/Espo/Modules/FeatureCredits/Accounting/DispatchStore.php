<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use stdClass;
use Throwable;

/** CRM-owned execution mailbox. Tenant lock serializes claims and leases without
 * creating wallets or changing financial projections. Outcome application uses
 * the existing accounting transaction, outside the mailbox transaction. */
final class DispatchStore
{
    public function __construct(private EntityManager $entityManager, private Clock $clock, private Outcomes $outcomes,
        private Reservations $reservations, private Settlements $settlements) {}

    public function execute(DispatchInput $input): mixed
    {
        $d = $input->data;
        if ($d->operation === 'deliver') return $this->deliver($input->tenantId, $d->leaseMs, $d->retryMs);
        if ($d->operation === 'recover') return $this->recover($d->scope);
        if ($d->operation === 'recoverOne') return $this->recoverOne($input->tenantId, $d->leaseMs, $d->retryMs);
        return $this->locked($input->tenantId, function (PDO $pdo, string $now) use ($d): mixed {
            if ($d->operation === 'seal') {
                $this->owner($pdo, $d->scope);
                $pdo->prepare('UPDATE credit_execution_route SET completion_requested_at=?,completion_due_at=?
                    WHERE usage_id=? AND completion_requested_at IS NULL')->execute([$now, $now, $d->scope->usageId]);
                return null;
            }
            if ($d->operation === 'load') {
                $this->owner($pdo, $d->scope);
                $row = $this->attempt($pdo, $d->scope, $d->requestKey);
                if (!$row) throw new NotFound('Unknown execution attempt.');
                return (object) ['intent' => $this->decode($row['intent']), 'requestId' => $row['request_id']];
            }
            $intent = clone ($d->operation === 'claimIntent' ? $d->intent : $d->binding);
            $requestId = $intent->requestId ?? null;
            unset($intent->requestId);
            $usage = $this->owner($pdo, $intent->scope);
            $json = DispatchInput::json($intent);
            $row = $this->attempt($pdo, $intent->scope, $intent->requestKey);
            if ($row && DispatchInput::json($this->decode($row['intent'])) !== $json) throw new Conflict('Conflicting prepared request.');
            if ($d->operation === 'claimIntent') {
                if ($row) return false;
                ExecutionRouting::requireDispatchOpenLocked($pdo, $intent->scope->usageId);
                if (!in_array($usage['state'], ['admitted', 'pendingReconciliation'], true)) throw new Conflict('Closed operation cannot claim requests.');
                $pdo->prepare('INSERT INTO credit_execution_attempt (id,tenant_id,usage_id,request_key,intent,created_at)
                    VALUES (?,?,?,?,?,?)')->execute([RecordId::generate(), $intent->scope->tenantId, $intent->scope->usageId,
                        $intent->requestKey, $json, $now]);
                return true;
            }
            if (!$row) throw new Conflict('A durable attempt is required.');
            $this->request($pdo, $intent, $requestId);
            if ($d->operation === 'bind') {
                if ($row['request_id'] !== null && $row['request_id'] !== $requestId) throw new Conflict('Conflicting CRM request binding.');
                // A bind acknowledgement is the worker's final dispatch prerequisite.
                // Even matching replay must not succeed after completion was sealed.
                ExecutionRouting::requireDispatchOpenLocked($pdo, $intent->scope->usageId);
                $pdo->prepare('UPDATE credit_execution_attempt SET request_id=? WHERE id=?')->execute([$requestId, $row['id']]);
                return null;
            }
            if ($row['request_id'] !== $requestId) throw new Conflict('Outcome requires a committed request binding.');
            $facts = DispatchInput::json($d->facts);
            $existing = $this->one($pdo, 'SELECT * FROM credit_outcome_delivery WHERE attempt_id=? AND phase=?', [$row['id'], $d->phase]);
            if ($existing) {
                if ($existing['deleted'] || $existing['tenant_id'] !== $intent->scope->tenantId ||
                    DispatchInput::json($this->decode($existing['facts'])) !== $facts) throw new Conflict('Conflicting delivery replay.');
                return null;
            }
            if ($d->phase === 1) {
                $prior = $this->one($pdo, 'SELECT * FROM credit_outcome_delivery WHERE attempt_id=? AND phase=0', [$row['id']]);
                $receipt = $prior && $prior['receipt'] !== null ? $this->decode($prior['receipt']) : null;
                if (!$prior || $prior['deleted'] || $prior['tenant_id'] !== $intent->scope->tenantId ||
                    $prior['state'] !== 'delivered' || ($receipt->meteringState ?? null) !== 'unknown' ||
                    $this->decode($prior['facts'])->outcome !== $d->facts->outcome ||
                    !DispatchInput::terminal($intent->scope, $d->facts)->outcome->measured()) throw new Conflict('Resolution requires acknowledged unknown usage.');
            }
            $pdo->prepare('INSERT INTO credit_outcome_delivery (id,tenant_id,attempt_id,phase,facts,state,attempts,due_at,created_at)
                VALUES (?,?,?,?,?,?,?,?,?)')->execute([RecordId::generate(), $intent->scope->tenantId, $row['id'], $d->phase,
                    $facts, 'pending', 0, $now, $now]);
            return null;
        });
    }

    /** Only explicitly sealed operations can be resumed. No timeout infers that a
     * provider stopped, no new authorization is issued, and no missing fact is zero. */
    private function recover(stdClass $scope): array
    {
        $snapshot = $this->locked($scope->tenantId, function (PDO $pdo) use ($scope): array {
            $usage = $this->owner($pdo, $scope);
            $route = $this->one($pdo, 'SELECT * FROM credit_execution_route WHERE usage_id=?', [$scope->usageId]);
            if (!$route || $route['completion_requested_at'] === null) throw new Conflict('Completion requires a durable dispatch seal.');
            if (in_array($usage['state'], ['settled', 'released'], true)) {
                return ['action' => $usage['state'] === 'settled' ? 'settle' : 'release', 'groups' => [], 'missing' => false];
            }
            $query = $pdo->prepare('SELECT * FROM credit_execution_attempt WHERE usage_id=? ORDER BY id LIMIT 101');
            $query->execute([$scope->usageId]); $attempts = $query->fetchAll(PDO::FETCH_ASSOC);
            $query = $pdo->prepare('SELECT * FROM credit_request WHERE usage_id=? ORDER BY id LIMIT 101');
            $query->execute([$scope->usageId]); $requests = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($attempts) > 100 || count($requests) > 100) throw new Conflict('Completion recovery exceeds its bounded request batch.');
            $missing = false; $covered = []; $groups = [];
            foreach ($attempts as $attempt) {
                $intent = $this->decode($attempt['intent']); DispatchInput::intent($intent);
                if ($attempt['deleted'] || $attempt['tenant_id'] !== $scope->tenantId ||
                    $attempt['request_key'] !== $intent->requestKey || DispatchInput::json($intent->scope) !== DispatchInput::json($scope)) {
                    throw new Conflict('Invalid completion attempt ownership.');
                }
                if ($attempt['request_id'] === null) {
                    // Recover identity only from the original scoped key and immutable
                    // accounting policy. This grants no provider dispatch permission.
                    $saved = $this->one($pdo, 'SELECT id FROM credit_request WHERE usage_id=? AND request_key=?',
                        [$scope->usageId, $intent->requestKey]);
                    if (!$saved) {
                        // M1: a denied authorization leaves a durable claim. Under
                        // this tenant lock the seal fences all first authorizations
                        // and bindings. With no accounting request there could be
                        // no dispatch prerequisite, so this is unstarted, not an
                        // unknown provider outcome. Never infer this before sealing.
                        if ($this->one($pdo, 'SELECT id FROM credit_outcome_delivery WHERE attempt_id=?', [$attempt['id']])) {
                            throw new Conflict('Unstarted completion attempt has outcome facts.');
                        }
                        continue;
                    }
                    $this->request($pdo, $intent, $saved['id']);
                    $pdo->prepare('UPDATE credit_execution_attempt SET request_id=? WHERE id=?')
                        ->execute([$saved['id'], $attempt['id']]);
                    $attempt['request_id'] = $saved['id'];
                }
                $this->request($pdo, $intent, $attempt['request_id']);
                $covered[$attempt['request_id']] = true;
                $query = $pdo->prepare('SELECT * FROM credit_outcome_delivery WHERE attempt_id=? ORDER BY phase LIMIT 3');
                $query->execute([$attempt['id']]); $deliveries = $query->fetchAll(PDO::FETCH_ASSOC);
                if (!$deliveries) { $missing = true; continue; }
                if (count($deliveries) > 2) throw new Conflict('Invalid completion delivery count.');
                $group = [];
                foreach ($deliveries as $phase => $delivery) {
                    if ($delivery['deleted'] || $delivery['tenant_id'] !== $scope->tenantId || (int) $delivery['phase'] !== $phase) {
                        throw new Conflict('Invalid completion delivery ownership or ordering.');
                    }
                    $facts = $this->decode($delivery['facts']);
                    if ($facts->requestId !== $attempt['request_id']) throw new Conflict('Invalid completion outcome binding.');
                    $group[] = DispatchInput::terminal($scope, $facts);
                }
                $groups[] = $group;
            }
            foreach ($requests as $request) {
                if ($request['deleted'] || $request['tenant_id'] !== $scope->tenantId) throw new Conflict('Invalid completion accounting request.');
                if (!isset($covered[$request['id']])) $missing = true;
            }
            return ['action' => !$requests ? 'release' : 'settle', 'groups' => $groups, 'missing' => $missing];
        });
        $deliveryFailed = $meteringPending = false;
        foreach ($snapshot['groups'] as $group) {
            $receipt = null;
            foreach ($group as $terminal) {
                try { $receipt = $this->outcomes->record($terminal->outcome, $terminal->execution); }
                catch (Throwable) { $deliveryFailed = true; break; }
            }
            if (($receipt['billingState'] ?? null) === 'pending') $meteringPending = true;
        }
        if ($snapshot['missing']) return ['state' => 'pending', 'reason' => 'request_recovery'];
        if ($deliveryFailed) return ['state' => 'pending', 'reason' => 'outcome_delivery'];
        if ($meteringPending) return ['state' => 'pending', 'reason' => 'metering'];
        $identity = new ExecutionIdentity($scope->runId, $scope->workflowRunId, $scope->executionId);
        $release = $snapshot['action'] === 'release';
        try {
            $receipt = $release
                ? $this->reservations->release($scope->tenantId, $scope->usageId, $scope->executionId, $identity)
                : $this->settlements->settle($scope->tenantId, $scope->usageId, $scope->executionId, $identity);
        } catch (Throwable) { return ['state' => 'pending', 'reason' => $release ? 'release' : 'settlement']; }
        // Separate acknowledgement: a crash here replays the original financial
        // receipt through the existing locked, idempotent terminal services.
        $this->locked($scope->tenantId, function (PDO $pdo, string $now) use ($scope): void {
            $this->owner($pdo, $scope);
            $pdo->prepare('UPDATE credit_execution_route SET completion_finished_at=COALESCE(completion_finished_at,?),completion_due_at=NULL
                WHERE usage_id=?')->execute([$now, $scope->usageId]);
        });
        return ['state' => $release ? 'released' : 'settled', 'receipt' => $receipt];
    }

    /** One explicitly configured tenant, one due operation per poll. Leases and
     * delayed retries prevent ambiguous old work from starving newer completion. */
    private function recoverOne(string $tenantId, int $leaseMs, int $retryMs): string
    {
        $job = $this->locked($tenantId, function (PDO $pdo, string $now) use ($tenantId, $leaseMs): ?array {
            $row = $this->one($pdo, 'SELECT * FROM credit_execution_route WHERE tenant_id=? AND deleted=FALSE
                AND completion_finished_at IS NULL AND completion_due_at<=? ORDER BY completion_due_at,id LIMIT 1', [$tenantId, $now]);
            if (!$row) return null;
            if ($row['billing_regime'] !== ExecutionRouting::UNIFIED || $row['completion_requested_at'] === null) {
                throw new Conflict('Invalid completion schedule.');
            }
            $scope = (object) ['billingRegime' => $row['billing_regime'], 'tenantId' => $tenantId, 'usageId' => $row['usage_id'],
                'runId' => $row['run_id'], 'workflowRunId' => $row['workflow_run_id'], 'executionId' => $row['execution_id']];
            DispatchInput::scope($scope); $this->owner($pdo, $scope);
            $lease = bin2hex(random_bytes(24));
            $pdo->prepare('UPDATE credit_execution_route SET completion_lease_token=?,completion_due_at=? WHERE id=?')
                ->execute([$lease, $this->after($now, $leaseMs), $row['id']]);
            return ['id' => $row['id'], 'lease' => $lease, 'scope' => $scope];
        });
        if (!$job) return 'empty';
        try { $this->recover($job['scope']); }
        catch (Throwable) { /* Keep the durable completion due for a delayed retry. */ }
        return $this->locked($tenantId, function (PDO $pdo, string $now) use ($job, $retryMs): string {
            $row = $this->one($pdo, 'SELECT * FROM credit_execution_route WHERE id=?', [$job['id']]);
            if (!$row || $row['deleted'] || $row['completion_lease_token'] !== $job['lease']) return 'leaseLost';
            $this->owner($pdo, $job['scope']);
            $completed = $row['completion_finished_at'] !== null;
            $pdo->prepare('UPDATE credit_execution_route SET completion_lease_token=NULL,completion_due_at=? WHERE id=?')
                ->execute([$completed ? null : $this->after($now, $retryMs), $row['id']]);
            return $completed ? 'completed' : 'pending';
        });
    }

    private function deliver(string $tenantId, int $leaseMs, int $retryMs): string
    {
        $job = $this->locked($tenantId, function (PDO $pdo, string $now) use ($tenantId, $leaseMs): ?array {
            $row = $this->one($pdo, 'SELECT * FROM credit_outcome_delivery WHERE tenant_id=? AND state=? AND deleted=FALSE
                AND due_at<=? ORDER BY due_at,id LIMIT 1', [$tenantId, 'pending', $now]);
            if (!$row) return null;
            if ($row['receipt'] !== null || !in_array((int) $row['phase'], [0, 1], true) ||
                (int) $row['attempts'] < 0 || (int) $row['attempts'] >= 2147483647) throw new Conflict('Invalid delivery state.');
            $attempt = $this->one($pdo, 'SELECT * FROM credit_execution_attempt WHERE id=?', [$row['attempt_id']]);
            if (!$attempt || $attempt['deleted'] || $attempt['tenant_id'] !== $tenantId) throw new Conflict('Invalid delivery parent.');
            $intent = $this->decode($attempt['intent']);
            DispatchInput::intent($intent);
            $this->owner($pdo, $intent->scope);
            if ($intent->scope->tenantId !== $tenantId || $intent->scope->usageId !== $attempt['usage_id'] ||
                $intent->requestKey !== $attempt['request_key']) throw new Conflict('Invalid attempt ownership.');
            $this->request($pdo, $intent, $attempt['request_id']);
            $facts = $this->decode($row['facts']);
            $terminal = DispatchInput::terminal($intent->scope, $facts);
            if ($facts->requestId !== $attempt['request_id']) throw new Conflict('Invalid outcome ownership.');
            if ((int) $row['phase'] === 1) {
                $prior = $this->one($pdo, 'SELECT * FROM credit_outcome_delivery WHERE attempt_id=? AND phase=0', [$attempt['id']]);
                if (!$prior || $prior['deleted'] || $prior['tenant_id'] !== $tenantId || $prior['state'] !== 'delivered' ||
                    $prior['receipt'] === null) throw new Conflict('Resolution cannot precede original delivery.');
            }
            $lease = bin2hex(random_bytes(24));
            $pdo->prepare('UPDATE credit_outcome_delivery SET lease_token=?,attempts=attempts+1,due_at=? WHERE id=?')
                ->execute([$lease, $this->after($now, $leaseMs), $row['id']]);
            return ['id' => $row['id'], 'lease' => $lease, 'terminal' => $terminal];
        });
        if ($job === null) return 'empty';
        try {
            $receipt = $this->outcomes->record($job['terminal']->outcome, $job['terminal']->execution);
        } catch (Throwable) {
            return $this->finish($tenantId, $job, null, $retryMs);
        }
        // A crash after accounting commit leaves a retryable lease. Existing
        // Outcomes idempotency returns the same financial effect on replay.
        return $this->finish($tenantId, $job, (object) $receipt, $retryMs);
    }

    private function finish(string $tenantId, array $job, ?stdClass $receipt, int $retryMs): string
    {
        return $this->locked($tenantId, function (PDO $pdo, string $now) use ($tenantId, $job, $receipt, $retryMs): string {
            $row = $this->one($pdo, 'SELECT * FROM credit_outcome_delivery WHERE id=? AND tenant_id=?', [$job['id'], $tenantId]);
            if (!$row || $row['deleted'] || $row['lease_token'] !== $job['lease'] || $row['state'] !== 'pending') return 'leaseLost';
            if ($receipt !== null) {
                $pdo->prepare('UPDATE credit_outcome_delivery SET receipt=?,state=?,lease_token=NULL WHERE id=?')
                    ->execute([DispatchInput::json($receipt), 'delivered', $row['id']]);
                return 'delivered';
            }
            $pdo->prepare('UPDATE credit_outcome_delivery SET lease_token=NULL,due_at=? WHERE id=?')
                ->execute([$this->after($now, $retryMs), $row['id']]);
            return 'retry';
        });
    }

    private function owner(PDO $pdo, stdClass $scope): array
    {
        $usage = $this->one($pdo, 'SELECT * FROM credit_usage WHERE id=?', [$scope->usageId]);
        if (!$usage || $usage['deleted'] || $usage['id'] !== $scope->usageId) throw new Conflict('Missing execution usage.');
        ExecutionRouting::requireUnifiedLocked($pdo, $scope->tenantId,
            new ExecutionIdentity($scope->runId, $scope->workflowRunId, $scope->executionId), $usage);
        return $usage;
    }

    private function attempt(PDO $pdo, stdClass $scope, string $key): ?array
    {
        $row = $this->one($pdo, 'SELECT * FROM credit_execution_attempt WHERE usage_id=? AND request_key=?', [$scope->usageId, $key]);
        if ($row && ($row['deleted'] || $row['tenant_id'] !== $scope->tenantId || $row['usage_id'] !== $scope->usageId ||
            $row['request_key'] !== $key || DispatchInput::json($this->decode($row['intent'])->scope) !== DispatchInput::json($scope))) {
            throw new Conflict('Invalid execution attempt ownership.');
        }
        return $row;
    }

    private function request(PDO $pdo, stdClass $intent, ?string $id): void
    {
        if ($id === null) throw new Conflict('Missing request binding.');
        $row = $this->one($pdo, 'SELECT * FROM credit_request WHERE id=?', [$id]);
        if (!$row || $row['deleted'] || $row['id'] !== $id || $row['tenant_id'] !== $intent->scope->tenantId ||
            $row['usage_id'] !== $intent->scope->usageId || $row['request_key'] !== $intent->requestKey) throw new Conflict('Invalid accounting request binding.');
        $reservation = $this->one($pdo, 'SELECT * FROM credit_reservation WHERE id=?', [$row['reservation_id']]);
        if (!$reservation || $reservation['deleted'] || $reservation['tenant_id'] !== $intent->scope->tenantId ||
            $reservation['usage_id'] !== $intent->scope->usageId || $reservation['execution_id'] !== $intent->scope->executionId) {
            throw new Conflict('Invalid request reservation ownership.');
        }
        $snapshot = $this->decode($row['pricing_snapshot']);
        foreach (['modelRateId' => 'id', 'provider' => 'provider', 'model' => 'model', 'inputTokenBound' => 'inputTokenBound',
            'outputTokenLimit' => 'outputTokenLimit', 'boundProfile' => 'boundProfile'] as $key => $field) {
            if (($snapshot->$field ?? null) !== $intent->policy->$key) throw new Conflict('Request policy differs from prepared policy.');
        }
    }

    private function locked(string $tenantId, callable $callback): mixed
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction() || !in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) throw new LogicException('Mailbox requires its own supported transaction.');
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare('SELECT id FROM tenant WHERE id=? AND deleted=FALSE FOR UPDATE');
            $query->execute([$tenantId]);
            if ($query->fetchColumn() !== $tenantId) throw new NotFound('Unknown execution tenant.');
            $result = $callback($pdo, $this->clock->now());
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function one(PDO $pdo, string $sql, array $params): ?array
    {
        $query = $pdo->prepare($sql); $query->execute($params);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function decode(string $json): stdClass
    {
        $value = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        if (!$value instanceof stdClass) throw new Conflict('Invalid stored dispatch record.');
        return $value;
    }

    private function after(string $now, int $ms): string
    {
        return (new DateTimeImmutable($now, new DateTimeZone('UTC')))->modify('+' . (int) ceil($ms / 1000) . ' seconds')->format('Y-m-d H:i:s');
    }
}
