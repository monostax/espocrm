<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\RecordId;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\WalletLock;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/** Durable accepted terms. Financial admission/holds and execution are separate, mandatory steps. */
final class Quotes
{
    public function __construct(private EntityManager $entityManager, private Clock $clock, private Access $access,
        private Catalog $catalog, private ProviderAvailability $availability, private RoutePlanner $planner,
        private WalletLock $walletLock, private Reservations $reservations) {}

    public function quote(QuoteInput $input): array
    {
        $this->access->target($input);
        return $this->locked($input->data['tenantId'], function (PDO $pdo) use ($input): array {
            $target = $this->lockedTarget($pdo, $input);
            $q = $pdo->prepare('SELECT * FROM enrichment_quote WHERE tenant_id=? AND created_by_id=? AND request_key=?');
            $q->execute([$input->data['tenantId'], $this->access->actorId(), $input->data['requestKey']]);
            if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $saved = $this->decode($row);
                if (self::hash(['request' => $saved['request']]) !== self::hash(['request' => $input->data])) {
                    throw new Conflict('Quote request already has different settings; request a new quote.');
                }
                return $this->receipt($row, $saved);
            }
            $snapshot = $this->snapshot($input, $target);
            $hash = self::hash($snapshot);
            $id = RecordId::generate();
            $now = $this->clock->now();
            $pdo->prepare('INSERT INTO enrichment_quote
                (id, tenant_id, target_type, target_id, request_key, snapshot_hash, snapshot, created_at, created_by_id)
                VALUES (?,?,?,?,?,?,?,?,?)')->execute([$id, $input->data['tenantId'], $input->data['targetType'],
                    $input->data['targetId'], $input->data['requestKey'], $hash,
                    json_encode($snapshot, JSON_THROW_ON_ERROR), $now, $this->access->actorId()]);
            return $this->receipt(['id' => $id, 'created_at' => $now, 'confirmed_at' => null], $snapshot);
        });
    }

    /** Same quote ID is the idempotency identity; callers cannot submit prices or altered settings. */
    public function confirm(string $tenantId, string $quoteId): array
    {
        GrantInput::identity($tenantId); GrantInput::identity($quoteId);
        $actor = $this->access->actorId();
        // Scope before decoding identity/settings. No cross-user or cross-tenant quote lookup.
        $pdo = $this->entityManager->getPDO();
        $q = $pdo->prepare('SELECT * FROM enrichment_quote WHERE id=? AND tenant_id=? AND created_by_id=?');
        $q->execute([$quoteId, $tenantId, $actor]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Unknown enrichment quote.');
        $snapshot = $this->decode($row);
        $input = new QuoteInput((object) $snapshot['request']);
        $this->access->target($input);
        return $this->locked($tenantId, function (PDO $pdo) use ($input, $quoteId, $tenantId, $actor): array {
            $q = $pdo->prepare('SELECT * FROM enrichment_quote WHERE id=? AND tenant_id=? AND created_by_id=? FOR UPDATE');
            $q->execute([$quoteId, $tenantId, $actor]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new NotFound('Unknown enrichment quote.');
            $saved = $this->decode($row);
            $target = $this->lockedTarget($pdo, $input);
            if ($row['confirmed_at'] !== null) return $this->receipt($row, $saved);
            $current = $this->snapshot($input, $target);
            if (!hash_equals($row['snapshot_hash'], self::hash($current))) {
                throw new Conflict('Enrichment quote changed; request and confirm a new quote.');
            }
            if (!array_filter($saved['routes'])) throw new Conflict('No eligible enrichment provider is available within this budget.');
            $row['confirmed_at'] = $this->clock->now();
            $pdo->prepare('UPDATE enrichment_quote SET confirmed_at=? WHERE id=?')->execute([$row['confirmed_at'], $quoteId]);
            return $this->receipt($row, $saved);
        });
    }

    /** Accepted terms + shared funds + active-product ownership commit together. Never dispatches. */
    public function admit(string $tenantId, string $quoteId): array
    {
        GrantInput::identity($tenantId); GrantInput::identity($quoteId);
        $actor = $this->access->actorId();
        return $this->walletLock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use ($tenantId, $quoteId, $actor): array {
            $q = $pdo->prepare('SELECT * FROM enrichment_quote WHERE id=? AND tenant_id=? AND created_by_id=? FOR UPDATE');
            $q->execute([$quoteId, $tenantId, $actor]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new NotFound('Unknown enrichment quote.');
            $saved = $this->decode($row);
            $input = new QuoteInput((object) $saved['request']);
            $target = $this->lockedTarget($pdo, $input);
            if ($row['usage_id'] !== null) return $this->receipt($row, $saved);
            if ($row['confirmed_at'] === null) throw new Conflict('Confirm enrichment terms before admission.');
            $identity = $input->isDiscovery() ? $this->access->discoveryIdentity($input, $target) : $this->access->identity($target);
            $availability = $this->availability->inspect($tenantId, $input->data['targetType'],
                $input->data['products'], $identity, $input->data['waterfall']);
            if (!$input->isDiscovery() && !$input->data['waterfall']) $availability = array_intersect_key($availability, ['apollo' => true]);
            if ($input->isDiscovery()) $availability = array_intersect_key($availability, $saved['availability']);
            if ($input->isDiscovery() && self::hash($this->access->discoveryPermissions($input, $target)) !== self::hash($saved['permissions'])) {
                throw new Conflict('Discovery permissions changed; request and confirm a new quote.');
            }
            if (self::hash($identity) !== self::hash($saved['identity']) ||
                self::hash($availability) !== self::hash($saved['availability'])) {
                throw new Conflict('Enrichment identity or capability changed; request and confirm a new quote.');
            }
            // Tenant lock serializes this predicate, including refresh and different actors/quote IDs.
            // Use accounting terminal state, not a separate mutable active flag that could strand a guard.
            $q = $pdo->prepare('SELECT q.*, u.state AS usage_state, u.deleted AS usage_deleted
                FROM enrichment_quote q LEFT JOIN credit_usage u ON u.id=q.usage_id AND u.tenant_id=q.tenant_id
                WHERE q.tenant_id=? AND q.target_type=? AND q.target_id=? AND q.usage_id IS NOT NULL');
            $q->execute([$tenantId, $input->data['targetType'], $input->data['targetId']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $active) {
                $terms = $this->decode($active);
                if ($active['usage_state'] === null || $active['usage_deleted']) throw new Conflict('Invalid enrichment operation binding.');
                if (!in_array($active['usage_state'], ['released', 'settled'], true) &&
                    array_intersect($terms['request']['products'], $input->data['products'])) {
                    throw Conflict::createWithBody('An enrichment operation is already active for this record and product.',
                        json_encode(['reason' => 'enrichment_operation_active', 'usageId' => $active['usage_id'],
                            'products' => array_values(array_intersect($terms['request']['products'], $input->data['products']))], JSON_THROW_ON_ERROR));
                }
            }
            $q = $pdo->prepare('SELECT id FROM tenant_credit_billing_rate WHERE tenant_id=? AND deleted=FALSE
                AND effective_from<=? AND (effective_until IS NULL OR effective_until>?)');
            $q->execute([$tenantId, $now, $now]);
            $agreements = $q->fetchAll(PDO::FETCH_COLUMN);
            if (count($agreements) !== 1) throw new Conflict('Admission requires exactly one active tenant agreement.');
            $hold = $this->reservations->reserveLocked($pdo, $wallet, $now, new ReservationInput(
                $tenantId, 'enrichment', 'enrichment:' . $quoteId, 'enrichment:' . $quoteId,
                $agreements[0], $saved['maximum']));
            $row['usage_id'] = $hold['usageId'];
            $row['admitted_at'] = $now;
            $pdo->prepare('UPDATE enrichment_quote SET usage_id=?, admitted_at=? WHERE id=?')
                ->execute([$row['usage_id'], $now, $quoteId]);
            return $this->receipt($row, $saved);
        });
    }

    private function snapshot(QuoteInput $input, Entity $target): array
    {
        $request = $input->data;
        $card = $this->catalog->current();
        if ($input->isDiscovery()) {
            $identity = $this->access->discoveryIdentity($input, $target);
            $availability = $this->availability->inspect($request['tenantId'], $request['targetType'], $request['products'], $identity, $request['waterfall']);
            $plan = (new DiscoveryPlanner())->plan($input, $card, $availability);
            return ['request' => $request, 'actorId' => $this->access->actorId(), 'identity' => $identity,
                'policyId' => $card->id, 'routingPolicy' => $card->definition['routingPolicy'],
                'permissions' => $this->access->discoveryPermissions($input, $target), ...$plan];
        }
        $identity = $this->access->identity($target);
        $availability = $this->availability->inspect($request['tenantId'], $request['targetType'], $request['products'], $identity, $request['waterfall']);
        if (!$request['waterfall']) $availability = array_intersect_key($availability, ['apollo' => true]);
        $allRates = $card->ratesFor($request['targetType']);
        $rates = [];
        foreach ($availability as $provider => $products) {
            foreach ($products as $product => $revision) $rates[$provider][$product] = $allRates[$provider][$product];
        }
        $budget = $request['budget'] === null ? Amount::fromString('0') : Amount::fromString($request['budget']);
        if ($request['budget'] === null) {
            foreach ($request['products'] as $product) $budget = $budget->plus(Amount::fromString($allRates['apollo'][$product]['credits']));
        }
        $plan = $this->planner->plan($target, $request['products'], $request['waterfall'], $budget, $rates, $card->definition['routingPolicy']);
        return ['request' => $request, 'actorId' => $this->access->actorId(), 'identity' => $identity,
            'policyId' => $card->id, 'routingPolicy' => $card->definition['routingPolicy'],
            'availability' => $availability, 'budget' => (string) $budget, ...$plan];
    }

    private function locked(string $tenantId, callable $callback): array
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) throw new LogicException('Quote command owns its transaction.');
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT id FROM tenant WHERE id=? AND deleted=FALSE FOR UPDATE');
            $q->execute([$tenantId]);
            if (!$q->fetchColumn()) throw new NotFound('Unknown enrichment tenant.');
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function lockedTarget(PDO $pdo, QuoteInput $input): Entity
    {
        $table = $input->data['targetType'] === 'Contact' ? 'contact' : 'account';
        $q = $pdo->prepare("SELECT id FROM $table WHERE id=? AND tenant_id=? AND deleted=FALSE FOR UPDATE");
        $q->execute([$input->data['targetId'], $input->data['tenantId']]);
        if (!$q->fetchColumn()) throw new NotFound('Unknown enrichment target.');
        return $this->access->target($input);
    }

    public static function decode(array $row): array
    {
        if ($row['deleted']) throw new Conflict('Enrichment quote is unavailable.');
        try { $snapshot = json_decode($row['snapshot'], true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new Conflict('Invalid enrichment quote.'); }
        $erased = ($row['erased_at'] ?? null) !== null;
        if (($erased && (($snapshot['identity'] ?? null) !== [] || !is_string($row['erasure_hash'] ?? null))) ||
            !is_array($snapshot) || !hash_equals($erased ? $row['erasure_hash'] : $row['snapshot_hash'], self::hash($snapshot)) ||
            ($snapshot['request']['tenantId'] ?? null) !== $row['tenant_id'] ||
            ($snapshot['request']['targetType'] ?? null) !== $row['target_type'] ||
            ($snapshot['request']['targetId'] ?? null) !== $row['target_id'] ||
            ($snapshot['request']['requestKey'] ?? null) !== $row['request_key'] ||
            ($snapshot['actorId'] ?? null) !== $row['created_by_id']) throw new Conflict('Invalid enrichment quote binding.');
        return $snapshot;
    }

    public static function hash(array $snapshot): string
    {
        $object = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        return hash('sha256', json_encode(GrantInput::canonical($object), JSON_THROW_ON_ERROR));
    }

    /** Deliberately excludes all input values, identity hashes and internal availability revisions. */
    private function receipt(array $row, array $snapshot): array
    {
        $admission = ['admission' => 'not_admitted', 'dispatchAllowed' => false];
        if (($row['usage_id'] ?? null) !== null) {
            $q = $this->entityManager->getPDO()->prepare('SELECT u.operation_type, u.operation_key, u.execution_id,
                u.deleted AS usage_deleted, r.* FROM credit_usage u JOIN credit_reservation r
                ON r.usage_id=u.id AND r.tenant_id=u.tenant_id AND r.execution_id=u.execution_id
                WHERE u.id=? AND u.tenant_id=?');
            $q->execute([$row['usage_id'], $snapshot['request']['tenantId']]);
            $hold = $q->fetch(PDO::FETCH_ASSOC);
            if (!$hold || $row['confirmed_at'] === null || $row['admitted_at'] === null ||
                $hold['deleted'] || $hold['usage_deleted'] || $hold['operation_type'] !== 'enrichment' ||
                $hold['operation_key'] !== 'enrichment:' . $row['id'] || $hold['execution_id'] !== 'enrichment:' . $row['id']) {
                throw new Conflict('Invalid enrichment reservation binding.');
            }
            $admission = ['admission' => $hold['state'] === 'held' ? 'admitted' : $hold['state'],
                'dispatchAllowed' => false, 'usageId' => $row['usage_id'], 'reservationId' => $hold['id'],
                'reservedCredits' => $hold['reserved_credits'], 'admittedAt' => $row['admitted_at']];
            $q = $this->entityManager->getPDO()->prepare('SELECT product,provider,state,purchased_at,source_attempt_id FROM enrichment_attempt WHERE quote_id=? AND tenant_id=? AND deleted=FALSE ORDER BY claimed_at,id');
            $q->execute([$row['id'],$snapshot['request']['tenantId']]); $attempts = $q->fetchAll(PDO::FETCH_ASSOC);
            $admission['executionProducts'] = [];
            foreach ($snapshot['request']['products'] as $product) {
                $children = array_values(array_filter($attempts,static fn ($a) => $a['product'] === $product));
                $states = array_column($children,'state');
                $state = match (true) {
                    in_array('matched',$states,true) => $hold['state'] === 'settled' ? 'purchased' : 'pending_purchase',
                    in_array('reused',$states,true) => 'purchased',
                    in_array('waived',$states,true) => 'waived',
                    $hold['state'] === 'held' => $children ? 'running' : 'queued',
                    in_array('no_match',$states,true) => 'no_match',
                    in_array('failed',$states,true) => 'failed',
                    default => 'unavailable',
                };
                $admission['executionProducts'][$product] = ['state' => $state,
                    'attempts' => array_map(static fn ($a) => ['provider' => $a['provider'],'state' => $a['state'],
                        'delivery' => $a['source_attempt_id'] === null ? 'live' : 'cache',
                        ...($a['state'] === 'reused' ? ['purchaseId' => $a['source_attempt_id']] : [])],$children)];
            }
            if (isset($snapshot['discovery'])) {
                $q = $this->entityManager->getPDO()->prepare('SELECT purchase_kind FROM enrichment_purchase WHERE quote_id=? AND tenant_id=? AND deleted=FALSE');
                $q->execute([$row['id'],$snapshot['request']['tenantId']]); $kinds = $q->fetchAll(PDO::FETCH_COLUMN);
                $candidates = count(array_filter($kinds,static fn ($kind) => $kind === 'candidate'));
                $q = $this->entityManager->getPDO()->prepare('SELECT provider,kind,state,dispatched_at,reused_from_id FROM enrichment_discovery_call
                    WHERE quote_id=? AND tenant_id=? AND deleted=FALSE ORDER BY claimed_at,id');
                $q->execute([$row['id'],$snapshot['request']['tenantId']]); $calls = $q->fetchAll(PDO::FETCH_ASSOC);
                $reason = $row['discovery_stop_reason'] ?? null;
                $state = match (true) {
                    $hold['state'] === 'held' => $calls ? 'running' : 'queued',
                    $candidates >= ($snapshot['request']['count'] ?? 1) => 'complete',
                    $candidates > 0 => 'partial',
                    in_array($reason,['ambiguous','deadline','cancelled','authorization_changed'],true) => 'waived',
                    $reason === 'provider_failed' || in_array('failed',array_column($calls,'state'),true) => 'failed',
                    in_array($reason,['route_exhausted','page_limit','completion_limit'],true) => 'no_match',
                    default => 'unavailable',
                };
                $admission['executionProducts'][$snapshot['request']['products'][0]] = [
                    'state' => $state,'candidateCount' => $candidates,'purchaseCount' => count($kinds),'stopReason' => $reason,
                    'calls' => array_map(static fn ($call) => ['provider' => $call['provider'],'kind' => $call['kind'],
                        'state' => $call['state'],'dispatched' => $call['dispatched_at'] !== null,'reused' => $call['reused_from_id'] !== null],$calls),
                ];
            }
        }
        $discovery = isset($snapshot['discovery']) ? ['discovery' => $snapshot['discovery'],
            'permissionsAtQuote' => $snapshot['permissions'],
            ...(isset($snapshot['request']['count']) ? ['count' => $snapshot['request']['count'], 'filters' => $snapshot['request']['filters']] : [])] : [];
        return ['id' => $row['id'], 'tenantId' => $snapshot['request']['tenantId'],
            'targetType' => $snapshot['request']['targetType'], 'targetId' => $snapshot['request']['targetId'],
            'products' => $snapshot['request']['products'], 'waterfall' => $snapshot['request']['waterfall'],
            'refresh' => $snapshot['request']['refresh'], 'budget' => $snapshot['budget'],
            'maximum' => $snapshot['maximum'], 'routes' => $snapshot['routes'], 'policyId' => $snapshot['policyId'],
            'createdAt' => $row['created_at'], 'confirmedAt' => $row['confirmed_at'],
            'state' => $row['confirmed_at'] === null ? 'quoted' : 'terms_confirmed',
            'confirmable' => (bool) array_filter($snapshot['routes']), ...$admission, ...$discovery];
    }
}
