<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\{Conflict, NotFound, ServiceUnavailable};
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Billing\Asaas\{Connections, Transport};
use PDO;

/** Original-provider operations. Cancellation stops recurrence, never refunds paid credits. */
final class Subscriptions
{
    public function __construct(private Accounts $accounts, private CreationStore $creations,
        private Connections $connections, private Transport $transport) {}

    public function cancel(string $tenant, string $obligation, string $key, string $actor): array
    {
        GrantInput::identity($actor);
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $key)) throw new \InvalidArgumentException('Invalid cancellation key.');
        return $this->run($tenant, $obligation, ['requestKey' => $key, 'actorId' => $actor]);
    }

    public function reconcile(string $tenant, string $obligation): array { return $this->run($tenant, $obligation, null); }

    private function run(string $tenant, string $obligation, ?array $cancel): array
    {
        GrantInput::identity($obligation);
        $claim = $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $obligation, $cancel): array {
            [$parent, $creation, $command, $record] = $this->original($pdo, $tenant, $obligation);
            $dispatch = false;
            if ($cancel !== null && $record['cancellation'] !== null &&
                array_intersect_key($record['cancellation'], $cancel) !== $cancel) throw new Conflict('Subscription cancellation already has an original command.');
            if ($parent['state'] === 'cancelled') return ['receipt' => $this->receipt($parent, $record)];
            // Resolve credentials before claiming. Lost replies and process death only permit GET recovery.
            $connection = $this->connections->get($creation['integration_id']);
            if ($connection->environment !== $creation['environment']) throw new Conflict('Original subscription environment changed.');
            if ($cancel !== null && $record['cancellation'] === null) {
                $record['cancellation'] = $cancel + ['requestedAt' => $now]; $dispatch = true;
            }
            $record['revision']++;
            $this->save($pdo, $parent, $record);
            return compact('parent', 'creation', 'command', 'record', 'connection', 'dispatch');
        });
        if (isset($claim['receipt'])) return $claim['receipt'];
        $creation = $claim['creation'];
        $method = $claim['dispatch'] ? 'DELETE' : 'GET';
        $response = $this->transport->send($claim['connection'], $method, '/subscriptions/' . $creation['external_id']);
        if ($response->status !== 200 || !is_array($response->data)) throw new ServiceUnavailable('Subscription operation is unresolved; reconcile the original subscription.');
        $data = $response->data;
        if (($data['id'] ?? null) !== $creation['external_id'] || !is_bool($data['deleted'] ?? null)) throw new Conflict('Subscription response has a different identity.');
        if ($method === 'DELETE') {
            if ($data['deleted'] !== true) throw new Conflict('Subscription removal is unconfirmed.');
            $status = 'DELETED';
        } else {
            $body = $claim['command']->body;
            if (($data['customer'] ?? null) !== $body['customer'] || ($data['externalReference'] ?? null) !== $body['externalReference'] ||
                ($data['cycle'] ?? null) !== 'MONTHLY' || !is_string($data['value'] ?? null) ||
                !preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $data['value']) ||
                BigDecimal::of($data['value'])->compareTo($body['value']) !== 0) throw new Conflict('Subscription terms differ from the original accepted terms.');
            $status = $data['deleted'] ? 'DELETED' : ($data['status'] ?? null);
            if (!in_array($status, ['ACTIVE', 'INACTIVE', 'EXPIRED', 'DELETED'], true)) throw new Conflict('Unknown subscription status.');
        }
        return $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $obligation, $claim, $status, $method): array {
            [$parent, $creation, , $record] = $this->original($pdo, $tenant, $obligation);
            // Only the latest read can change the projection. A verified removal is permanent.
            $applied = $record['revision'] === $claim['record']['revision'] && $parent['state'] !== 'cancelled';
            $this->accounts->subscriptionObservationLocked($pdo, $tenant, $obligation, [
                'revision' => $claim['record']['revision'], 'bindingHash' => $creation['result_hash'],
                'cancellation' => $claim['record']['cancellation'], 'status' => $status, 'method' => $method, 'applied' => $applied,
            ], $now);
            if (!$applied) return $this->receipt($parent, $record);
            $record['observation'] = ['status' => $status, 'method' => $method, 'observedAt' => $now];
            if ($status === 'DELETED') {
                $this->accounts->subscriptionRemovedLocked($pdo, $tenant, $obligation, $now);
                $parent['state'] = 'cancelled';
            }
            // INACTIVE/EXPIRED can retain unpaid charges; they do not unpin provider routing.
            $this->save($pdo, $parent, $record);
            return $this->receipt($parent, $record);
        });
    }

    private function original(PDO $pdo, string $tenant, string $obligation): array
    {
        $q = $pdo->prepare('SELECT * FROM credit_billing_obligation WHERE tenant_id=? AND id=?'); $q->execute([$tenant, $obligation]);
        $parent = $q->fetch(PDO::FETCH_ASSOC);
        if (!$parent || $parent['deleted']) throw new NotFound('Subscription is unavailable.');
        $accepted = $this->accounts->originalAcceptance($parent);
        if ($accepted['provider'] !== 'asaas' || $accepted['terms']['operation'] !== 'subscription' ||
            !in_array($parent['state'], ['pending', 'active', 'cancelled'], true)) throw new Conflict('Subscription has no bound lifecycle.');
        $q = $pdo->prepare("SELECT * FROM credit_billing_creation WHERE tenant_id=? AND obligation_id=? AND kind='subscription'");
        $q->execute([$tenant, $obligation]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) throw new Conflict('Subscription creation is unavailable.');
        $creation = $rows[0]; $command = $this->creations->verify($creation);
        if ($creation['state'] !== 'bound' || $creation['integration_id'] !== $accepted['integrationId'] ||
            $creation['provider'] !== $accepted['provider']) throw new Conflict('Original subscription binding mismatch.');
        return [$parent, $creation, $command, self::record($parent, $creation)];
    }

    /** Caller has already validated the original acceptance and creation binding. */
    public static function projection(array $parent, array $creation): array { return self::receipt($parent, self::record($parent, $creation)); }

    private static function record(array $parent, array $creation): array
    {
        $record = ['revision' => 0, 'cancellation' => null, 'observation' => null, 'bindingHash' => $creation['result_hash']];
        if ($parent['subscription_record'] !== null) {
            try { $saved = json_decode($parent['subscription_record'], true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new Conflict('Invalid subscription lifecycle evidence.'); }
            if (!is_array($saved)) throw new Conflict('Invalid subscription lifecycle evidence.');
            $hash = $saved['hash'] ?? null; unset($saved['hash']);
            if ($hash !== Payments::hash($saved) || ($saved['bindingHash'] ?? null) !== $creation['result_hash'] ||
                !is_int($saved['revision'] ?? null) || $saved['revision'] < 1) throw new Conflict('Invalid subscription lifecycle evidence.');
            $record = $saved;
        }
        return $record;
    }

    private function save(PDO $pdo, array $parent, array $record): void
    {
        $record['hash'] = Payments::hash($record);
        $pdo->prepare('UPDATE credit_billing_obligation SET subscription_record=? WHERE id=? AND tenant_id=?')
            ->execute([json_encode($record, JSON_THROW_ON_ERROR), $parent['id'], $parent['tenant_id']]);
    }

    private static function receipt(array $parent, array $record): array
    {
        return ['tenantId' => $parent['tenant_id'], 'obligationId' => $parent['id'], 'state' => $parent['state'],
            'cancellationPending' => $record['cancellation'] !== null && $parent['state'] !== 'cancelled',
            'providerStatus' => $record['observation']['status'] ?? null, 'observedAt' => $record['observation']['observedAt'] ?? null];
    }
}
