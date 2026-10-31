<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Espo\Core\Exceptions\{Conflict, NotFound, ServiceUnavailable};
use Espo\Modules\FeatureCredits\Accounting\{Clock, GrantInput, RecordId};
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;

/** Internal billing state service; HTTP callers require financial-admin/override authorization first. */
final class Accounts
{
    private const OPEN = ['accepted', 'creating', 'unknown', 'pending', 'active'];
    private const CLOSED = ['completed', 'cancelled', 'failed'];

    public function __construct(
        private EntityManager $entityManager,
        private Clock $clock,
        private Catalog $catalog,
        private Providers $providers,
    ) {}

    /** Read-only context under the tenant lock. Absence does not create a billing account or wallet. */
    public function context(string $tenantId): array
    {
        return $this->locked($tenantId, function (PDO $pdo, string $now) use ($tenantId): array {
            $account = $this->account($pdo, $tenantId);
            $obligations = $this->obligations($pdo, $account);
            $result = $this->view($account, $obligations);
            try {
                $catalog = $this->catalog->current();
            } catch (ServiceUnavailable) {
                return $result + ['catalogId' => null, 'catalogUnavailable' => true, 'offers' => []];
            }
            $offers = [];
            foreach ($catalog->offers as $terms) {
                if (!$terms->availableToTenant($tenantId) || !in_array($account['billing_country'], $terms->countries, true)) continue;
                $offers[] = ['terms' => $terms->jsonSerialize(), 'eligibility' => $this->eligibility($account, $obligations, $terms)];
            }
            return $result + ['catalogId' => $catalog->id, 'catalogUnavailable' => false, 'offers' => $offers];
        });
    }

    public function changeCountry(string $tenantId, string $key, int $revision, ?string $country, string $actor): array
    {
        Routing::assignment($country, null, null, false);
        return $this->change($tenantId, $key, $revision, 'country', $country, $actor);
    }

    public function changeOverride(string $tenantId, string $key, int $revision, ?string $override, string $actor): array
    {
        if ($override !== null) Routing::provider($override);
        return $this->change($tenantId, $key, $revision, 'override', $override, $actor);
    }

    private function change(string $tenantId, string $key, int $revision, string $kind, ?string $value, string $actor): array
    {
        $this->command($key, $revision, $actor);
        $hash = $this->hash(['billing-change-v1', $tenantId, $key, $revision, $kind, $value, $actor]);
        return $this->locked($tenantId, function (PDO $pdo, string $now) use ($tenantId, $key, $revision, $kind, $value, $actor, $hash): array {
            if ($receipt = $this->changeReplay($pdo, $tenantId, $key, $hash)) return $receipt;
            $account = $this->account($pdo, $tenantId);
            $obligations = $this->obligations($pdo, $account);
            $this->revision($account, $revision);
            $before = $this->view($account, $obligations);
            if ($kind === 'override') {
                Routing::assertOverrideChange($account['billing_country'], $value, $account['effective_provider'], (bool) $obligations);
                $account['provider_override'] = $value;
            } else {
                $account['billing_country'] = $value;
            }
            $this->saveAccount($pdo, $account, $obligations, $now);
            return $this->recordChange($pdo, $tenantId, $key, $hash, $kind, $actor, $before, $this->view($account, $obligations), $now);
        });
    }

    /** Persist accepted terms only. Every replay is dispatch-denied; no adapter I/O or funding here. */
    public function accept(
        string $tenantId, string $key, int $revision, string $catalogId, string $offerId, string $termsId, string $actor,
    ): array {
        $this->command($key, $revision, $actor);
        foreach ([$catalogId, $termsId] as $id) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new InvalidArgumentException('Exact catalog and offer references are required.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,23}$/D', $offerId)) throw new InvalidArgumentException('Invalid offer identity.');
        $hash = $this->hash(['billing-acceptance-v1', $tenantId, $key, $revision, $catalogId, $offerId, $termsId, $actor]);
        return $this->locked($tenantId, function (PDO $pdo, string $now) use ($tenantId, $key, $revision, $catalogId, $offerId, $termsId, $actor, $hash): array {
            $q = $pdo->prepare('SELECT * FROM credit_billing_obligation WHERE tenant_id=? AND request_key=?');
            $q->execute([$tenantId, $key]);
            if ($existing = $q->fetch(PDO::FETCH_ASSOC)) {
                if ($existing['deleted'] || $existing['input_hash'] !== $hash) throw new Conflict('Billing request identity already has different terms.');
                return $this->acceptanceReceipt($existing);
            }
            $account = $this->account($pdo, $tenantId);
            $obligations = $this->obligations($pdo, $account);
            $this->revision($account, $revision);
            $catalog = $this->catalog->currentForAcceptance();
            $terms = $catalog->offers[$offerId] ?? null;
            if ($catalog->id !== $catalogId || !$terms || !$terms->availableToTenant($tenantId) || $terms->termsId !== $termsId) {
                throw new Conflict('Commercial terms changed; review the current offer.');
            }
            $eligibility = $this->eligibility($account, $obligations, $terms);
            if (!$eligibility['eligible']) throw new Conflict($eligibility['blockedReason']);
            if ($account['effective_provider'] !== $eligibility['effectiveProvider']) throw new Conflict('Billing assignment is inconsistent.');
            if ($terms->operation === 'subscription') {
                foreach ($obligations as $obligation) {
                    $accepted = $this->acceptanceReceipt($obligation)['terms'];
                    if ($accepted['operation'] === 'subscription' && $accepted['productId'] === $terms->productId) {
                        throw new Conflict('A subscription for this product is already pending or active.');
                    }
                }
            }
            $id = RecordId::generate();
            $row = [
                'id' => $id, 'tenant_id' => $tenantId, 'request_key' => $key, 'input_hash' => $hash,
                'provider' => $eligibility['effectiveProvider'], 'integration_id' => $eligibility['integrationId'],
                'catalog_id' => $catalogId, 'terms_id' => $termsId, 'terms_snapshot' => json_encode($terms, JSON_THROW_ON_ERROR),
                'operation' => $terms->operation, 'billing_country' => $account['billing_country'], 'assignment_revision' => $revision,
                'state' => 'accepted', 'created_at' => $now, 'created_by_id' => $actor,
            ];
            $row['acceptance_hash'] = $this->acceptanceHash($row, $this->acceptanceReceipt($row, false));
            $pdo->prepare('INSERT INTO credit_billing_obligation
                (id, tenant_id, request_key, input_hash, provider, integration_id, catalog_id, terms_id, terms_snapshot,
                 operation, billing_country, assignment_revision, state, created_at, created_by_id, acceptance_hash)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_values($row));
            // Catch scheduled catalog cutover during acceptance with a fresh locking selection.
            if ($this->catalog->currentForAcceptance()->id !== $catalogId) throw new Conflict('Commercial catalog changed during acceptance.');
            return $this->acceptanceReceipt($row);
        });
    }

    /** Safe local cancellation only while no provider-creation claim exists. */
    public function cancel(string $tenantId, string $key, string $obligationId, string $actor): array
    {
        $this->command($key, 0, $actor); GrantInput::identity($obligationId);
        $hash = $this->hash(['billing-cancel-v1', $tenantId, $key, $obligationId, $actor]);
        return $this->locked($tenantId, function (PDO $pdo, string $now) use ($tenantId, $key, $obligationId, $actor, $hash): array {
            if ($receipt = $this->changeReplay($pdo, $tenantId, $key, $hash)) return $receipt;
            $account = $this->account($pdo, $tenantId);
            $before = $this->view($account, $this->obligations($pdo, $account));
            $q = $pdo->prepare('SELECT * FROM credit_billing_obligation WHERE id=? AND tenant_id=?');
            $q->execute([$obligationId, $tenantId]); $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['deleted']) throw new NotFound('Billing obligation is unavailable.');
            if ($row['state'] !== 'accepted') throw new Conflict('Only unstarted billing acceptance can be cancelled locally.');
            $pdo->prepare("UPDATE credit_billing_obligation SET state='cancelled' WHERE id=? AND tenant_id=?")->execute([$obligationId, $tenantId]);
            $remaining = $this->obligations($pdo, $account);
            $this->saveAccount($pdo, $account, $remaining, $now);
            return $this->recordChange($pdo, $tenantId, $key, $hash, 'cancel', $actor,
                $before + ['obligationId' => $obligationId, 'state' => 'accepted'],
                $this->view($account, $remaining) + ['obligationId' => $obligationId, 'state' => 'cancelled'], $now);
        });
    }

    public function readObligation(string $tenantId, string $obligationId): array
    {
        GrantInput::identity($obligationId);
        return $this->locked($tenantId, function (PDO $pdo) use ($tenantId, $obligationId): array {
            $q = $pdo->prepare('SELECT * FROM credit_billing_obligation WHERE id=? AND tenant_id=?');
            $q->execute([$obligationId, $tenantId]); $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['deleted']) throw new NotFound('Billing obligation is unavailable.');
            return ['state' => $row['state'], 'acceptance' => $this->acceptanceReceipt($row)];
        });
    }

    private function eligibility(array $account, array $obligations, OfferTerms $terms): array
    {
        $route = Routing::assignment($account['billing_country'], $account['provider_override'], $account['effective_provider'], (bool) $obligations);
        $capability = $this->providers->capability($route['effectiveProvider']);
        $result = Routing::checkout($terms, $account['billing_country'], $account['provider_override'], $account['effective_provider'], (bool) $obligations, $capability, $account['tenant_id']);
        if ($result['eligible'] && $obligations && $obligations[0]['integration_id'] !== $capability->integrationId) {
            $result['eligible'] = false; $result['blockedReason'] = 'billing_integration_pinned'; $result['integrationId'] = null;
        }
        return $result;
    }

    private function account(PDO $pdo, string $tenantId): array
    {
        $q = $pdo->prepare('SELECT * FROM tenant_billing_assignment WHERE tenant_id=?'); $q->execute([$tenantId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['deleted']) throw new Conflict('Deleted billing assignment.');
        return $row ?: ['id' => null, 'tenant_id' => $tenantId, 'billing_country' => null, 'provider_override' => null,
            'effective_provider' => null, 'revision' => 0];
    }

    private function obligations(PDO $pdo, array $account): array
    {
        $q = $pdo->prepare("SELECT * FROM credit_billing_obligation
            WHERE tenant_id=? AND (state NOT IN ('completed','cancelled','failed') OR deleted=TRUE) ORDER BY id");
        $q->execute([$account['tenant_id']]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($row['deleted'] || !in_array($row['state'], self::OPEN, true) ||
                $row['provider'] !== $account['effective_provider'] || $row['integration_id'] !== $rows[0]['integration_id']) {
                throw new Conflict('Unresolved billing obligations have inconsistent bindings.');
            }
            $this->acceptanceReceipt($row);
        }
        return $rows;
    }

    private function view(array $account, array $obligations): array
    {
        return ['tenantId' => $account['tenant_id'], 'billingCountry' => $account['billing_country'],
            'providerOverride' => $account['provider_override'], 'revision' => (int) $account['revision'],
            'openObligations' => count($obligations)] + Routing::assignment($account['billing_country'],
                $account['provider_override'], $account['effective_provider'], (bool) $obligations);
    }

    private function saveAccount(PDO $pdo, array &$account, array $obligations, string $now): void
    {
        $route = Routing::assignment($account['billing_country'], $account['provider_override'], $account['effective_provider'], (bool) $obligations);
        $account['effective_provider'] = $route['effectiveProvider'];
        $account['revision'] = (int) $account['revision'] + 1;
        if ($account['id'] === null) {
            $account['id'] = RecordId::generate();
            $pdo->prepare('INSERT INTO tenant_billing_assignment (id, tenant_id, billing_country, provider_override, effective_provider, revision, created_at, modified_at)
                VALUES (?,?,?,?,?,?,?,?)')->execute([$account['id'], $account['tenant_id'], $account['billing_country'],
                    $account['provider_override'], $account['effective_provider'], $account['revision'], $now, $now]);
        } else {
            $pdo->prepare('UPDATE tenant_billing_assignment SET billing_country=?, provider_override=?, effective_provider=?, revision=?, modified_at=? WHERE id=? AND tenant_id=?')
                ->execute([$account['billing_country'], $account['provider_override'], $account['effective_provider'],
                    $account['revision'], $now, $account['id'], $account['tenant_id']]);
        }
    }

    private function acceptanceReceipt(array $row, bool $verify = true): array
    {
        try {
            $snapshot = json_decode($row['terms_snapshot'], false, 32, JSON_THROW_ON_ERROR);
            if (!$snapshot instanceof \stdClass) throw new InvalidArgumentException('Invalid accepted terms.');
            $terms = OfferTerms::fromSnapshot($snapshot);
            if (!$terms->availableToTenant($row['tenant_id']) || $terms->termsId !== $row['terms_id'] || $terms->operation !== $row['operation'] ||
                !in_array($row['state'], [...self::OPEN, ...self::CLOSED], true)) throw new InvalidArgumentException('Inconsistent accepted terms.');
        } catch (InvalidArgumentException | \JsonException | \OverflowException | \Brick\Math\Exception\MathException) {
            throw new Conflict('Stored billing acceptance is invalid.');
        }
        $receipt = ['obligationId' => $row['id'], 'tenantId' => $row['tenant_id'], 'requestKey' => $row['request_key'],
            'provider' => $row['provider'], 'integrationId' => $row['integration_id'], 'catalogId' => $row['catalog_id'],
            'terms' => $terms->jsonSerialize(), 'billingCountry' => $row['billing_country'],
            'assignmentRevision' => (int) $row['assignment_revision'], 'acceptedAt' => $row['created_at'], 'dispatchAllowed' => false];
        if ($verify && $row['acceptance_hash'] !== $this->acceptanceHash($row, $receipt)) throw new Conflict('Billing acceptance binding is inconsistent.');
        return $receipt;
    }

    /** Internal execution collaborator: verify the full original binding, without a current catalog lookup. */
    public function originalAcceptance(array $row): array { return $this->acceptanceReceipt($row); }

    /** Internal billing collaborators share the exact same parent lock and transaction contract. */
    public function withTenantLock(string $tenantId, callable $callback): mixed { return $this->locked($tenantId, $callback); }

    /** Internal funding collaborator: caller already owns the tenant/wallet transaction. */
    public function paymentCompletedLocked(PDO $pdo, string $tenantId, string $obligationId, string $operation, string $now): void
    {
        if (!$pdo->inTransaction() || $pdo !== $this->entityManager->getPDO()) throw new LogicException('Payment completion requires the original transaction.');
        $state = $operation === 'purchase' ? 'completed' : 'active';
        $q = $pdo->prepare("UPDATE credit_billing_obligation SET state=? WHERE id=? AND tenant_id=? AND state<>? AND state NOT IN ('cancelled','failed')");
        $q->execute([$state, $obligationId, $tenantId, $state]);
        if ($q->rowCount() && $operation === 'purchase') {
            // A country edit while payment was pending may now become effective. Completion and routing are atomic.
            $account = $this->account($pdo, $tenantId);
            $this->saveAccount($pdo, $account, $this->obligations($pdo, $account), $now);
        }
    }

    /** Append-only subscription observations reuse the existing billing change audit. */
    public function subscriptionObservationLocked(PDO $pdo, string $tenantId, string $obligationId, array $evidence, string $now): void
    {
        if (!$pdo->inTransaction() || $pdo !== $this->entityManager->getPDO()) throw new LogicException('Subscription evidence requires the original transaction.');
        $key = 'subscription:' . $obligationId . ':' . $evidence['revision'];
        $this->recordChange($pdo, $tenantId, $key, $this->hash($evidence), 'subscription',
            $evidence['cancellation']['actorId'] ?? 'system', ['obligationId' => $obligationId], $evidence, $now);
    }

    /** Verified permanent provider removal; paid grants and historical payment bindings survive. */
    public function subscriptionRemovedLocked(PDO $pdo, string $tenantId, string $obligationId, string $now): void
    {
        if (!$pdo->inTransaction() || $pdo !== $this->entityManager->getPDO()) throw new LogicException('Subscription removal requires the original transaction.');
        $q = $pdo->prepare("UPDATE credit_billing_obligation SET state='cancelled' WHERE id=? AND tenant_id=? AND operation='subscription' AND state<>'cancelled'");
        $q->execute([$obligationId, $tenantId]);
        if ($q->rowCount()) {
            $account = $this->account($pdo, $tenantId);
            $this->saveAccount($pdo, $account, $this->obligations($pdo, $account), $now);
        }
    }

    private function acceptanceHash(array $row, array $receipt): string
    {
        return $this->hash(['billing-binding-v1', $row['input_hash'], $row['created_by_id'], $receipt]);
    }

    private function changeReplay(PDO $pdo, string $tenantId, string $key, string $hash): ?array
    {
        $q = $pdo->prepare('SELECT * FROM credit_billing_change WHERE tenant_id=? AND request_key=?'); $q->execute([$tenantId, $key]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ($row['deleted'] || $row['input_hash'] !== $hash) throw new Conflict('Billing change identity already has different input.');
        try { $receipt = json_decode($row['receipt'], true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new Conflict('Invalid billing change receipt.'); }
        if (!is_array($receipt) || ($receipt['tenantId'] ?? null) !== $tenantId || ($receipt['changeId'] ?? null) !== $row['id']) {
            throw new Conflict('Invalid billing change receipt.');
        }
        if ($row['receipt_hash'] !== $this->hash(['billing-change-receipt-v1', $receipt])) throw new Conflict('Inconsistent billing change receipt.');
        return $receipt;
    }

    private function recordChange(PDO $pdo, string $tenantId, string $key, string $hash, string $kind, string $actor, array $before, array $after, string $now): array
    {
        $id = RecordId::generate();
        $receipt = ['changeId' => $id, 'tenantId' => $tenantId, 'kind' => $kind, 'before' => $before, 'after' => $after, 'changedAt' => $now];
        $pdo->prepare('INSERT INTO credit_billing_change (id, tenant_id, request_key, input_hash, kind, receipt, receipt_hash, created_at, created_by_id)
            VALUES (?,?,?,?,?,?,?,?,?)')->execute([$id, $tenantId, $key, $hash, $kind, json_encode($receipt, JSON_THROW_ON_ERROR),
                $this->hash(['billing-change-receipt-v1', $receipt]), $now, $actor]);
        return $receipt;
    }

    private function revision(array $account, int $revision): void
    {
        if ((int) $account['revision'] !== $revision) throw new Conflict('Billing assignment changed; refresh before confirming.');
    }

    private function command(string $key, int $revision, string $actor): void
    {
        GrantInput::identity($actor);
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $key) || $revision < 0 || $revision >= 2147483647) {
            throw new InvalidArgumentException('A canonical request key and nonnegative billing revision are required.');
        }
    }

    private function hash(array $input): string
    {
        $canonical = GrantInput::canonical(json_decode(json_encode($input, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR));
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /** Same parent lock as credit accounting, without creating an unfunded wallet. */
    private function locked(string $tenantId, callable $callback): mixed
    {
        GrantInput::identity($tenantId);
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction() || !in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) throw new LogicException('Billing requires its own MySQL/PostgreSQL transaction.');
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT id FROM tenant WHERE id=? AND deleted=FALSE FOR UPDATE'); $q->execute([$tenantId]);
            if ($q->fetchColumn() !== $tenantId) throw new NotFound('Unknown billing tenant.');
            $result = $callback($pdo, $this->clock->now());
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
