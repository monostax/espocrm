<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Espo\Core\Exceptions\{Conflict, NotFound, ServiceUnavailable};
use Espo\Modules\FeatureCredits\Accounting\{Funding, FundingRefunds, GrantInput, PurchaseReversals, RecordId, WalletLock};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Command, Connections, PaymentVerification, Transport};
use Espo\ORM\EntityManager;
use PDO;

/** Internal payment reconciliation. New-sale routing and webhook financial fields are never consulted. */
final class Payments
{
    public function __construct(
        private EntityManager $entityManager,
        private Accounts $accounts,
        private CreationStore $creations,
        private Connections $connections,
        private Transport $transport,
        private WalletLock $walletLock,
        private Funding $funding,
    ) {}

    public function reconcile(string $integration, string $environment, string $paymentId, ?string $subscriptionHint = null): array
    {
        GrantInput::identity($integration); Command::externalId('payment', $paymentId);
        if ($subscriptionHint !== null) Command::externalId('subscription', $subscriptionHint);
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) throw new \LogicException('Payment I/O cannot run inside a transaction.');
        // Existing payment bindings win over a delayed/incorrect webhook hint.
        $q = $pdo->prepare("SELECT * FROM credit_billing_payment WHERE provider='asaas' AND integration_id=? AND external_hash=?");
        $q->execute([$integration, hash('sha256', $paymentId)]); $saved = $q->fetch(PDO::FETCH_ASSOC);
        if ($saved) {
            $this->verifyBinding($saved);
            $creationId = $saved['creation_id']; $tenant = $saved['tenant_id'];
        } else {
            $q = $pdo->prepare("SELECT * FROM credit_billing_creation WHERE provider='asaas' AND integration_id=? AND kind=? AND external_hash=?");
            $q->execute([$integration, 'payment', hash('sha256', $paymentId)]);
            $creation = $q->fetch(PDO::FETCH_ASSOC);
            if (!$creation) {
                // A subscription hint is never binding authority. Discover its owner from Asaas itself.
                $connection = $this->connections->get($integration);
                if ($connection->environment !== $environment) throw new Conflict('Original integration environment changed.');
                $response = $this->transport->send($connection, 'GET', '/payments/' . $paymentId);
                if ($response->status !== 200 || !is_array($response->data)) throw new ServiceUnavailable('Payment discovery is unresolved.');
                $subscription = $response->data['subscription'] ?? null;
                if (($response->data['id'] ?? null) !== $paymentId || !is_string($subscription)) throw new NotFound('Payment has no original subscription binding.');
                try { Command::externalId('subscription', $subscription); }
                catch (\InvalidArgumentException) { throw new NotFound('Payment has no original subscription binding.'); }
                $q->execute([$integration, 'subscription', hash('sha256', $subscription)]);
                $creation = $q->fetch(PDO::FETCH_ASSOC);
            }
            if (!$creation) throw new NotFound('Payment has no original provider binding.');
            $creationId = $creation['id']; $tenant = $creation['tenant_id'];
        }
        $claim = $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $creationId, $integration, $environment, $paymentId): array {
            [$creation, $command, $accepted] = $this->original($pdo, $tenant, $creationId);
            if ($creation['integration_id'] !== $integration || $creation['environment'] !== $environment) throw new Conflict('Original payment integration changed.');
            $q = $pdo->prepare("SELECT * FROM credit_billing_payment WHERE provider='asaas' AND integration_id=? AND external_hash=? FOR UPDATE");
            $q->execute([$integration, hash('sha256', $paymentId)]); $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $row = ['id' => RecordId::generate(), 'tenant_id' => $tenant, 'obligation_id' => $creation['obligation_id'],
                    'creation_id' => $creationId, 'provider' => 'asaas', 'integration_id' => $integration, 'environment' => $environment,
                    'external_id' => $paymentId, 'external_hash' => hash('sha256', $paymentId), 'created_at' => $now];
                $row['binding_hash'] = self::hash($row);
                $pdo->prepare('INSERT INTO credit_billing_payment
                    (id,tenant_id,obligation_id,creation_id,provider,integration_id,environment,external_id,external_hash,created_at,binding_hash,revision,state)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?)')->execute([...array_values($row), 'unverified']);
                $row['revision'] = 0;
            } else {
                $this->verifyBinding($row);
                if ($row['creation_id'] !== $creationId || $row['tenant_id'] !== $tenant || $row['environment'] !== $environment || $row['external_id'] !== $paymentId) {
                    throw new Conflict('Payment binding cannot be replaced.');
                }
            }
            // A fresh attempt supersedes an interrupted/older read. Only the newest read can commit.
            $revision = (int) $row['revision'] + 1;
            $pdo->prepare('UPDATE credit_billing_payment SET revision=? WHERE id=?')->execute([$revision, $row['id']]);
            return [$row, $revision, $command, $creation['external_id']];
        });
        [$payment, $revision, $command, $external] = $claim;
        $connection = $this->connections->get($integration);
        if ($connection->environment !== $environment) throw new Conflict('Original integration environment changed.');
        $response = $this->transport->send($connection, 'GET', '/payments/' . $paymentId);
        if ($response->status !== 200 || !is_array($response->data)) throw new ServiceUnavailable('Authoritative payment lookup is unresolved.');
        $verified = PaymentVerification::verify($response->data, $paymentId, $command, $external);
        return $this->walletLock->run($tenant, function (PDO $pdo, array $wallet, string $now) use ($payment, $revision, $verified, $tenant, $creationId): array {
            [$creation, , $accepted] = $this->original($pdo, $tenant, $creationId);
            $q = $pdo->prepare('SELECT * FROM credit_billing_payment WHERE id=? FOR UPDATE'); $q->execute([$payment['id']]);
            $row = $q->fetch(PDO::FETCH_ASSOC); $this->verifyBinding($row);
            if ((int) $row['revision'] !== $revision) {
                return $this->recordVerification($pdo, $row, $revision, $verified, $now, ['state' => 'superseded', 'paymentId' => $row['id']]);
            }
            $identity = self::hash([$row['binding_hash'], $verified['customer'], $verified['subscription'], $verified['originalDueDate'], $verified['value'], $verified['period']]);
            if ($row['identity_hash'] !== null && $row['identity_hash'] !== $identity) throw new Conflict('Verified payment period or amount changed.');
            $period = $this->period($pdo, $row, $accepted, $verified['period'], $now);
            $loss = $this->originalLoss($pdo, $row, $verified);
            $receipt = null; $reversal = null; $state = $verified['review'] ? 'review_required' : 'pending';
            if ($period['receipt'] !== null) {
                $receipt = json_decode($period['receipt'], true, 32, JSON_THROW_ON_ERROR);
                if (self::hash($receipt) !== $period['receipt_hash']) throw new Conflict('Inconsistent billing funding receipt.');
                if ($receipt['grantId'] !== null) $reversal = PurchaseReversals::receiptLocked($pdo, $tenant, $receipt['grantId']) ??
                    FundingRefunds::latest($pdo, $tenant, $receipt['grantId']);
            }
            $purchase = $accepted['terms']['operation'] === 'purchase';
            $fullLoss = $loss !== null && \Brick\Math\BigDecimal::of($loss['value'])->compareTo($verified['value']) === 0;
            if ($purchase && !isset($reversal['cumulativeCredits']) && ($reversal !== null || $row['state'] === 'reversed_unfunded' || $fullLoss)) {
                $state = $receipt === null ? 'reversed_unfunded' : 'reversed';
                if ($receipt !== null && $reversal === null) {
                    if ($period['paid_payment_id'] !== $row['id'] || $receipt['grantId'] === null) throw new Conflict('Reversal does not own original purchase funding.');
                    $q = $pdo->prepare('SELECT source_key, source_snapshot FROM credit_grant WHERE id=? AND tenant_id=?');
                    $q->execute([$receipt['grantId'], $tenant]); $grant = $q->fetch(PDO::FETCH_ASSOC);
                    $source = $grant ? json_decode($grant['source_snapshot'], true, 32, JSON_THROW_ON_ERROR) : [];
                    if (!$grant || $grant['source_key'] !== 'billing:' . $period['id'] || ($source['paymentId'] ?? null) !== $row['id'] ||
                        ($source['periodId'] ?? null) !== $period['id'] || ($source['externalPaymentId'] ?? null) !== $row['external_id']) {
                        throw new Conflict('Reversal grant has different original payment provenance.');
                    }
                    $reversal = PurchaseReversals::reverseLocked($pdo, $wallet, $now, $receipt['grantId'], [
                        'paymentId' => $row['id'], 'periodId' => $period['id'], 'provider' => $row['provider'],
                        'integrationId' => $row['integration_id'], 'environment' => $row['environment'],
                         'externalPaymentId' => $row['external_id'], 'verificationRevision' => $revision, 'reversal' => $loss]);
                }
                $this->accounts->paymentCompletedLocked($pdo, $tenant, $row['obligation_id'], 'purchase', $now);
            } elseif ($fullLoss && $receipt === null) {
                $state = 'reversed_unfunded';
            } elseif ($fullLoss && $receipt['grantId'] === null) {
                $state = 'reversed';
            } elseif ($verified['paid']) {
                if ($period['starts_at'] > $now) {
                    $state = 'awaiting_period';
                } else {
                    $state = 'funded';
                    if ($period['receipt'] !== null) {
                        $receipt = json_decode($period['receipt'], true, 32, JSON_THROW_ON_ERROR);
                        if (self::hash($receipt) !== $period['receipt_hash']) throw new Conflict('Inconsistent billing funding receipt.');
                    } else {
                        $terms = $accepted['terms'];
                        $receipt = $terms['credits'] === '0.0000' ? ['grantId' => null, 'transactionId' => null, 'grantedCredits' => '0.0000', 'expiresAt' => $period['ends_at']] :
                            $this->funding->grantLocked($pdo, $wallet, $now, new GrantInput($tenant, $terms['operation'], 'billing:' . $period['id'],
                                $terms['credits'], $period['starts_at'], $period['ends_at'], (object) [
                                    'obligationId' => $row['obligation_id'], 'periodId' => $period['id'], 'paymentId' => $row['id'],
                                    'provider' => 'asaas', 'integrationId' => $row['integration_id'], 'environment' => $row['environment'],
                                    'externalPaymentId' => $row['external_id'], 'termsId' => $terms['termsId'],
                                    'verificationRevision' => $revision, 'verifiedAt' => $now,
                                    'paymentEvidence' => json_decode(json_encode($verified, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR),
                                ]));
                        $pdo->prepare('UPDATE credit_billing_period SET receipt=?, receipt_hash=?, paid_payment_id=? WHERE id=?')
                            ->execute([json_encode($receipt, JSON_THROW_ON_ERROR), self::hash($receipt), $row['id'], $period['id']]);
                        $period['paid_payment_id'] = $row['id'];
                    }
                    $this->accounts->paymentCompletedLocked($pdo, $tenant, $row['obligation_id'], $accepted['terms']['operation'], $now);
                }
            }
            if ($loss !== null && $receipt !== null && $receipt['grantId'] !== null &&
                ($reversal === null || isset($reversal['cumulativeCredits']))) {
                if ($period['paid_payment_id'] !== $row['id']) throw new Conflict('Refund does not own original period funding.');
                $q = $pdo->prepare('SELECT * FROM credit_grant WHERE id=? AND tenant_id=?');
                $q->execute([$receipt['grantId'], $tenant]); $grant = $q->fetch(PDO::FETCH_ASSOC);
                $source = $grant ? json_decode($grant['source_snapshot'], true, 32, JSON_THROW_ON_ERROR) : [];
                if (!$grant || $grant['source_key'] !== 'billing:' . $period['id'] || ($source['paymentId'] ?? null) !== $row['id'] ||
                    ($source['periodId'] ?? null) !== $period['id'] || ($source['externalPaymentId'] ?? null) !== $row['external_id']) {
                    throw new Conflict('Refund grant has different original payment provenance.');
                }
                if (!$purchase && $grant['reserved_credits'] !== '0.0000') {
                    // Await real completion/release; elapsed time never releases an AI hold.
                    $state = 'awaiting_refund_release';
                } else {
                    $q = $pdo->prepare('SELECT * FROM tenant_credit_balance WHERE id=?'); $q->execute([$wallet['id']]);
                    $reversal = FundingRefunds::reverseLocked($pdo, $q->fetch(PDO::FETCH_ASSOC), $now, $receipt['grantId'],
                        FundingRefunds::credits($accepted['terms']['credits'], $verified['value'], $loss['value']),
                        ['paymentId' => $row['id'], 'periodId' => $period['id'], 'provider' => $row['provider'],
                            'integrationId' => $row['integration_id'], 'environment' => $row['environment'],
                            'externalPaymentId' => $row['external_id'], 'verificationRevision' => $revision, 'reversal' => $loss], $this->funding);
                    $state = $fullLoss ? 'reversed' : 'partially_reversed';
                }
            }
            $pdo->prepare('UPDATE credit_billing_payment SET identity_hash=?, period_id=?, state=?, verified_at=?, evidence=? WHERE id=?')
                ->execute([$identity, $period['id'], $state, $now, json_encode($verified, JSON_THROW_ON_ERROR), $row['id']]);
            return $this->recordVerification($pdo, $row, $revision, $verified, $now, [
                'state' => $state, 'paymentId' => $row['id'], 'periodId' => $period['id'], 'funding' => $receipt, 'reversal' => $reversal,
                'retryAt' => $state === 'awaiting_period' ? $period['starts_at'] : null,
                'endedPeriod' => $period['ends_at'] !== null && $period['ends_at'] <= $now]);
        });
    }

    /** Completed loss observations are monotonic; a later stale paid/partial GET cannot erase them. */
    private function originalLoss(PDO $pdo, array $payment, array $verified): ?array
    {
        $loss = $verified['fullReversal'] ?? $verified['refund'] ?? null;
        $q = $pdo->prepare('SELECT * FROM credit_billing_verification WHERE payment_id=? AND tenant_id=? ORDER BY revision');
        $q->execute([$payment['id'], $payment['tenant_id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $evidence = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR);
            if ($row['deleted'] || self::hash($evidence) !== $row['evidence_hash'] ||
                ($evidence['bindingHash'] ?? null) !== $payment['binding_hash']) throw new Conflict('Invalid historical payment verification.');
            if ($row['state'] === 'superseded') continue;
            $candidate = $evidence['payment']['fullReversal'] ?? $evidence['payment']['refund'] ?? null;
            if ($candidate !== null && ($loss === null || \Brick\Math\BigDecimal::of($candidate['value'])->isGreaterThan($loss['value']))) $loss = $candidate;
        }
        return $loss;
    }

    private function recordVerification(PDO $pdo, array $payment, int $revision, array $verified, string $now, array $result): array
    {
        $evidence = ['bindingHash' => $payment['binding_hash'], 'revision' => $revision, 'verifiedAt' => $now,
            'payment' => $verified, 'result' => $result];
        $pdo->prepare('INSERT INTO credit_billing_verification (id,tenant_id,payment_id,revision,state,verified_at,evidence,evidence_hash)
            VALUES (?,?,?,?,?,?,?,?)')->execute([RecordId::generate(), $payment['tenant_id'], $payment['id'], $revision,
                $result['state'], $now, json_encode($evidence, JSON_THROW_ON_ERROR), self::hash($evidence)]);
        return $result;
    }

    private function original(PDO $pdo, string $tenant, string $id): array
    {
        $q = $pdo->prepare('SELECT * FROM credit_billing_creation WHERE id=? AND tenant_id=?'); $q->execute([$id, $tenant]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Conflict('Missing original payment creation.');
        $command = $this->creations->verify($row);
        if ($row['state'] !== 'bound' || !in_array($row['kind'], ['payment', 'subscription'], true)) throw new Conflict('Payment creation is not bound.');
        $q = $pdo->prepare('SELECT * FROM credit_billing_obligation WHERE id=? AND tenant_id=?'); $q->execute([$row['obligation_id'], $tenant]);
        $parent = $q->fetch(PDO::FETCH_ASSOC);
        if (!$parent || $parent['deleted'] || !in_array($parent['state'], ['pending', 'active', 'completed', 'cancelled'], true)) throw new Conflict('Original payment obligation is unavailable.');
        $accepted = $this->accounts->originalAcceptance($parent);
        if ($accepted['provider'] !== $row['provider'] || $accepted['integrationId'] !== $row['integration_id']) throw new Conflict('Original provider binding mismatch.');
        return [$row, $command, $accepted];
    }

    private function period(PDO $pdo, array $payment, array $accepted, ?array $bounds, string $now): array
    {
        $key = $bounds === null ? 'purchase' : $bounds['start'];
        $q = $pdo->prepare('SELECT * FROM credit_billing_period WHERE obligation_id=? AND period_key=? FOR UPDATE');
        $q->execute([$payment['obligation_id'], $key]); $row = $q->fetch(PDO::FETCH_ASSOC);
        $binding = ['tenant_id' => $payment['tenant_id'], 'obligation_id' => $payment['obligation_id'], 'period_key' => $key,
            'starts_at' => $bounds['start'] ?? $accepted['acceptedAt'], 'ends_at' => $bounds['end'] ?? null, 'terms_id' => $accepted['terms']['termsId']];
        if ($row) {
            if ($row['deleted'] || $row['binding_hash'] !== self::hash($binding) ||
                self::hash(array_intersect_key($row, $binding)) !== self::hash($binding)) throw new Conflict('Original billing period is inconsistent.');
            return $row;
        }
        $row = ['id' => RecordId::generate()] + $binding + ['binding_hash' => self::hash($binding), 'created_at' => $now];
        $pdo->prepare('INSERT INTO credit_billing_period (id,tenant_id,obligation_id,period_key,starts_at,ends_at,terms_id,binding_hash,created_at)
            VALUES (?,?,?,?,?,?,?,?,?)')->execute(array_values($row));
        return $row + ['receipt' => null, 'receipt_hash' => null];
    }

    private function verifyBinding(array $row): void
    {
        $binding = array_intersect_key($row, array_flip(['id', 'tenant_id', 'obligation_id', 'creation_id', 'provider', 'integration_id',
            'environment', 'external_id', 'external_hash', 'created_at']));
        if ($row['deleted'] || $row['binding_hash'] !== self::hash($binding) || hash('sha256', $row['external_id']) !== $row['external_hash']) {
            throw new Conflict('Original payment binding is inconsistent.');
        }
    }

    public static function hash(array $value): string
    {
        return hash('sha256', json_encode(GrantInput::canonical(json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR));
    }
}
