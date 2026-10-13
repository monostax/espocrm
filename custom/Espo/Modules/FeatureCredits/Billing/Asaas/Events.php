<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing\Asaas;

use Espo\Core\Exceptions\{BadRequest, Conflict, NotFound, ServiceUnavailable};
use Espo\Modules\FeatureCredits\Accounting\{Clock, GrantInput, RecordId};
use Espo\Modules\FeatureCredits\Billing\Payments;
use Espo\ORM\EntityManager;
use PDO;

/** Durable minimal inbox: authenticate, persist, acknowledge. Processing performs authoritative GETs separately. */
final class Events
{
    public function __construct(private EntityManager $entityManager, private Connections $connections, private Clock $clock, private Payments $payments) {}

    public function receive(string $integration, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $raw): array
    {
        $environment = $this->connections->authenticate($integration, $token);
        try {
            GrantInput::identity($integration);
            if (strlen($raw) > 1048576) throw new \InvalidArgumentException();
            $data = Json::decode($raw);
            if (!is_array($data) || !is_string($data['id'] ?? null) || !preg_match('/^evt_[\x21-\x7e]{1,196}$/D', $data['id']) ||
                !is_string($data['event'] ?? null) || !preg_match('/^PAYMENT_[A-Z_]{1,80}$/D', $data['event']) ||
                !is_array($data['payment'] ?? null) || !is_string($data['payment']['id'] ?? null)) throw new \InvalidArgumentException();
            Command::externalId('payment', $data['payment']['id']);
            $subscription = $data['payment']['subscription'] ?? null;
            if ($subscription !== null) {
                if (!is_string($subscription)) throw new \InvalidArgumentException();
                Command::externalId('subscription', $subscription);
            }
        } catch (\InvalidArgumentException | \JsonException) { throw new BadRequest('Invalid Asaas payment event.'); }
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) throw new \LogicException('Webhook inbox requires an independent commit.');
        $hash = Payments::hash(['asaas', $integration, $environment, $data['id'], $data['event'], $data['payment']['id'], $subscription]);
        $id = RecordId::generate(); $now = $this->clock->now();
        $sql = 'INSERT INTO credit_billing_event (id,provider,integration_id,environment,external_id,external_hash,input_hash,event_type,payment_id,subscription_hint,state,received_at,next_attempt_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $sql .= $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ON DUPLICATE KEY UPDATE id=id' : ' ON CONFLICT (provider,integration_id,external_hash) DO NOTHING';
        $pdo->prepare($sql)->execute([$id, 'asaas', $integration, $environment, $data['id'], hash('sha256', $data['id']), $hash, $data['event'],
            $data['payment']['id'], $subscription, 'pending', $now, $now]);
        $q = $pdo->prepare("SELECT * FROM credit_billing_event WHERE provider='asaas' AND integration_id=? AND external_hash=?");
        $q->execute([$integration, hash('sha256', $data['id'])]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['deleted'] || $row['input_hash'] !== $hash) throw new Conflict('Asaas event identity has different input.');
        return ['accepted' => true, 'eventId' => $row['id']];
    }

    /** Bounded job/recovery entrypoint. No billing POSTs; failed/unmatched events remain durable and retryable. */
    public function processBatch(): int
    {
        $pdo = $this->entityManager->getPDO();
        $q = $pdo->prepare("SELECT * FROM credit_billing_event WHERE state='pending' AND deleted=FALSE AND next_attempt_at<=? ORDER BY next_attempt_at,id LIMIT 20");
        $q->execute([$this->clock->now()]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $processed = 0;
        foreach ($rows as $row) {
            $claimedAt = $this->clock->now(); $lease = RecordId::generate();
            $leaseEnd = (new \DateTimeImmutable($claimedAt, new \DateTimeZone('UTC')))->modify('+2 minutes')->format('Y-m-d H:i:s');
            $claim = $pdo->prepare("UPDATE credit_billing_event SET lease_token=?, next_attempt_at=?
                WHERE id=? AND state='pending' AND deleted=FALSE AND next_attempt_at<=?");
            $claim->execute([$lease, $leaseEnd, $row['id'], $claimedAt]);
            if (!$claim->rowCount()) continue;
            $processed++;
            $state = 'pending'; $reason = 'lookup_unresolved';
            try {
                if ($row['external_hash'] !== hash('sha256', $row['external_id']) || $row['input_hash'] !== Payments::hash([
                    $row['provider'], $row['integration_id'], $row['environment'], $row['external_id'], $row['event_type'], $row['payment_id'], $row['subscription_hint'],
                ])) throw new Conflict('Inconsistent original event.');
                $result = $this->payments->reconcile($row['integration_id'], $row['environment'], $row['payment_id'], $row['subscription_hint']);
                $reason = $result['state'];
                if (in_array($reason, ['pending', 'funded', 'reversed', 'reversed_unfunded'], true)) $state = 'processed';
                if ($reason === 'review_required') $state = 'review';
            } catch (NotFound) { $reason = 'unmatched'; }
            catch (Conflict) { $reason = 'verification_conflict'; }
            catch (ServiceUnavailable) { $reason = 'lookup_unresolved'; }
            $next = ($result['retryAt'] ?? null) ?? (new \DateTimeImmutable($this->clock->now(), new \DateTimeZone('UTC')))->modify('+2 minutes')->format('Y-m-d H:i:s');
            // Concurrent processors cannot undo an already completed delivery; accounting has its own read fence.
            $pdo->prepare("UPDATE credit_billing_event SET state=?, last_reason=?, next_attempt_at=?, lease_token=NULL
                WHERE id=? AND state='pending' AND lease_token=?")
                ->execute([$state, $reason, $next, $row['id'], $lease]);
            unset($result);
        }
        return $processed;
    }
}
