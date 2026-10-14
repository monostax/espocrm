<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\FixedSettlement;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\RecordId;
use Espo\Modules\FeatureCredits\Accounting\WalletLock;
use Espo\Modules\FeatureCredits\Enrichment\Providers\Registry;
use Espo\Modules\FeatureCredits\Enrichment\Providers\Result;
use Espo\ORM\EntityManager;
use PDO;

/** CRM-owned at-most-once paid dispatch. A lost claim becomes a waiver, never a retry. */
class Execution
{
    public function __construct(private EntityManager $entityManager, private WalletLock $lock, private ActorAccess $actors,
        private Registry $providers, private FixedSettlement $settlement, private Crypt $crypt, private Clock $clock) {}

    /** Job entry. Persist every permission before supplier I/O, never hold database locks over HTTP. */
    public function run(string $tenantId, string $quoteId): void
    {
        for ($step = 0; $step < 12; $step++) {
            $claim = $this->claim($tenantId, $quoteId);
            if ($claim === null) return;
            $timeout = $this->dispatchWindow($tenantId, $quoteId, $claim['id'], $claim['token']);
            if ($timeout === null) continue;
            try {
                $provider = $this->providers->get($claim['provider']);
                $result = $claim['action'] === 'poll' ?
                    $provider->poll($claim['requestId'], $claim['subjectId'], $timeout) :
                    $provider->lookup($claim['type'], $claim['product'], $claim['identity'], $timeout);
            } catch (\Throwable) {
                $result = new Result('ambiguous');
            }
            $this->record($tenantId, $quoteId, $claim['id'], $claim['token'], $result);
            if ($result->state === 'pending') return;
        }
    }

    /** Internal claim includes protected identity. Never return this payload from a user API. */
    public function claim(string $tenantId, string $quoteId, bool $dispatch = true): ?array
    {
        GrantInput::identity($quoteId);
        return $this->lock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use ($tenantId, $quoteId, $dispatch): ?array {
            [$row, $terms, $usage, $hold] = $this->load($pdo, $tenantId, $quoteId);
            if ($usage['state'] !== 'admitted') return null;
            if (!$this->authorized($pdo, $terms)) {
                $this->waiveAll($pdo, $quoteId, $now);
                $this->close($pdo, $wallet, $now, $row, $usage, $hold);
                return null;
            }
            $q = $pdo->prepare('SELECT * FROM enrichment_attempt WHERE quote_id=? ORDER BY claimed_at,id');
            $q->execute([$quoteId]);
            $attempts = $q->fetchAll(PDO::FETCH_ASSOC);
            $spent = Amount::fromString('0');
            foreach ($attempts as $attempt) {
                if ($attempt['deleted'] || $attempt['tenant_id'] !== $tenantId) throw new Conflict('Invalid enrichment attempt ownership.');
                if ($attempt['state'] === 'matched') $spent = $spent->plus(Amount::fromString($attempt['credits']));
            }
            $remaining = Amount::fromString($terms['maximum'])->minus($spent);
            if ($remaining->compareTo(Amount::fromString('0')) < 0) throw new Conflict('Enrichment shared budget exceeded.');
            $allSelected = isset($terms['request']['sources']);
            foreach ($terms['request']['products'] as $product) {
                $children = array_values(array_filter($attempts, static fn ($a) => $a['product'] === $product));
                if (!$allSelected && array_intersect(array_column($children, 'state'), ['matched', 'waived', 'reused'])) continue;
                foreach ($children as $attempt) {
                    if (!in_array($attempt['state'], ['claimed', 'pending'], true)) continue;
                    if ($attempt['deadline_at'] <= $now) {
                        $this->waive($pdo, $attempt['id'], $now);
                        if ($allSelected) continue; // Each selected provider has independent consent and funding.
                        continue 2; // An ambiguous/deadline child never falls back to another provider.
                    }
                    if ($attempt['state'] === 'claimed' || $attempt['lease_until'] > $now || $attempt['poll_after'] > $now) return null;
                    if (!$dispatch) return null;
                    $token = bin2hex(random_bytes(24));
                    $pdo->prepare('UPDATE enrichment_attempt SET claim_token=?, lease_until=? WHERE id=?')->execute([
                        $token, min(self::after($now, 30), $attempt['deadline_at']), $attempt['id']]);
                    return $this->claimReceipt($attempt, $terms, $token, 'poll');
                }
                // A job that never started (or a long queue gap) cannot hold funding indefinitely.
                $last = $children ? max(array_column($children, 'completed_at')) : $row['admitted_at'];
                if ($last !== null && self::after($last, 300) <= $now) continue;
                foreach ($terms['routes'][$product] as $route) {
                    if (in_array($route['provider'], array_column($children, 'provider'), true) ||
                        Amount::fromString($route['credits'])->compareTo($remaining) > 0) continue;
                    $supplier = $this->providers->get($route['provider']);
                    $revision = $supplier->revision($tenantId, $terms['request']['targetType'], $product, $terms['identity']);
                    $available = $revision !== null && $revision === ($terms['availability'][$route['provider']][$product] ?? null);
                    $cached = $available ? (new FieldCache($this->crypt))->find($pdo,$terms,$product,$route['provider'],$revision,$now) : null;
                    if ($cached !== null) {
                        $id = RecordId::generate();
                        if (isset($cached['purchaseId'])) {
                            $pdo->prepare('INSERT INTO enrichment_attempt
                                (id,tenant_id,quote_id,product,provider,state,claim_token,claimed_at,deadline_at,lease_until,credits,completed_at,source_attempt_id)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$tenantId,$quoteId,$product,$route['provider'],
                                    'reused',bin2hex(random_bytes(24)),$now,$now,$now,'0.0000',$now,$cached['purchaseId']]);
                            if ($allSelected) continue;
                            continue 2;
                        }
                        $binding = ['source_attempt_id' => $cached['sourceAttemptId'], 'acquisition_quote_id' => $cached['acquisitionQuoteId'], 'acquired_at' => $cached['acquiredAt']];
                        $pdo->prepare('INSERT INTO enrichment_attempt
                            (id,tenant_id,quote_id,product,provider,state,claim_token,claimed_at,deadline_at,lease_until,credits,
                            result_ciphertext,result_hash,result_expires_at,completed_at,source_attempt_id,acquisition_quote_id,acquired_at)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$tenantId,$quoteId,$product,$route['provider'],
                                'matched',bin2hex(random_bytes(24)),$now,$now,$now,$route['credits'],
                                $this->crypt->encrypt(json_encode($cached['values'],JSON_THROW_ON_ERROR)),
                                FieldCache::resultHash($tenantId,$quoteId,$id,$route['provider'],$product,$cached['values'],$binding),
                                $cached['expiresAt'],$now,$cached['sourceAttemptId'],$cached['acquisitionQuoteId'],$cached['acquiredAt']]);
                        $remaining = $remaining->minus(Amount::fromString($route['credits']));
                        if ($allSelected) continue;
                        continue 2;
                    }
                    if ($available && !$dispatch) return null;
                    $id = RecordId::generate(); $token = bin2hex(random_bytes(24)); $deadline = self::after($now, 30);
                    $pdo->prepare('INSERT INTO enrichment_attempt
                        (id,tenant_id,quote_id,product,provider,state,claim_token,claimed_at,deadline_at,lease_until,credits)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$tenantId,$quoteId,$product,$route['provider'],
                            $available ? 'claimed' : 'skipped',$token,$now,$deadline,$deadline,$route['credits']]);
                    if (!$available) {
                        $pdo->prepare('UPDATE enrichment_attempt SET completed_at=? WHERE id=?')->execute([$now,$id]);
                        continue;
                    }
                    return $this->claimReceipt(['id' => $id, 'product' => $product, 'provider' => $route['provider']], $terms, $token, 'lookup');
                }
            }
            $this->close($pdo, $wallet, $now, $row, $usage, $hold);
            return null;
        });
    }

    /** Result replay/late delivery cannot mutate an already completed attempt or reopen a closed hold. */
    public function record(string $tenantId, string $quoteId, string $attemptId, string $token, Result $result): void
    {
        $this->lock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use ($tenantId, $quoteId, $attemptId, $token, $result): void {
            [$row, $terms, $usage, $hold] = $this->load($pdo, $tenantId, $quoteId);
            if ($usage['state'] !== 'admitted') return;
            $q = $pdo->prepare('SELECT * FROM enrichment_attempt WHERE id=? AND quote_id=? AND tenant_id=?');
            $q->execute([$attemptId,$quoteId,$tenantId]);
            $attempt = $q->fetch(PDO::FETCH_ASSOC);
            if (!$attempt || $attempt['deleted']) throw new NotFound('Unknown enrichment attempt.');
            if (!hash_equals($attempt['claim_token'], $token) || !in_array($attempt['state'], ['claimed','pending'], true)) return;
            if (!$this->authorized($pdo, $terms)) {
                $this->waiveAll($pdo, $quoteId, $now);
                $this->close($pdo, $wallet, $now, $row, $usage, $hold);
                return;
            }
            if ($attempt['deadline_at'] <= $now || $attempt['lease_until'] <= $now) {
                $this->waive($pdo, $attemptId, $now);
                return;
            }
            $provider = $this->providers->get($attempt['provider']);
            if ($provider->revision($tenantId, $terms['request']['targetType'], $attempt['product'], $terms['identity']) !==
                ($terms['availability'][$attempt['provider']][$attempt['product']] ?? null)) {
                $this->waive($pdo, $attemptId, $now);
                return;
            }
            if ($result->state === 'pending') {
                if ($attempt['provider'] !== 'apollo' || $terms['request']['targetType'] !== 'Contact' || $attempt['product'] !== 'phone' ||
                    $result->subjectId === null || ($attempt['request_id'] !== null &&
                    ($attempt['request_id'] !== $result->requestId || $attempt['subject_id'] !== $result->subjectId))) {
                    $this->waive($pdo, $attemptId, $now);
                    return;
                }
                $deadline = self::after($attempt['claimed_at'], 300);
                $pdo->prepare("UPDATE enrichment_attempt SET state='pending', deadline_at=?, lease_until=?, poll_after=?, request_id=?, subject_id=? WHERE id=?")
                    ->execute([$deadline,$now,min(self::after($now,$result->retryAfter),$deadline),$result->requestId,$result->subjectId,$attemptId]);
                return;
            }
            $state = match ($result->state) { 'match' => 'matched', 'no_match' => 'no_match', 'failed' => 'failed', default => 'waived' };
            $cipher = $state === 'matched' ? $this->crypt->encrypt(json_encode($result->values, JSON_THROW_ON_ERROR)) : null;
            $hash = $cipher === null ? null : Quotes::hash([$tenantId,$quoteId,$attemptId,$attempt['provider'],$attempt['product'],$result->values]);
            $pdo->prepare('UPDATE enrichment_attempt SET state=?,result_ciphertext=?,result_expires_at=?,completed_at=?,result_hash=? WHERE id=?')
                ->execute([$state,$cipher,$cipher === null ? null : self::after($now,$result->retentionSeconds),$now,$hash,$attemptId]);
            $this->applyContactDateOfBirth($pdo, $terms, $result->dateOfBirth, $now);
            (new FieldCache($this->crypt))->store($pdo,$terms,$attempt,$result,$this->providers,$now);
        });
    }

    /** Bounded scheduled discovery; no supplier payload or identifiers in job data. */
    public function due(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) throw new \InvalidArgumentException('Invalid enrichment job batch.');
        $q = $this->entityManager->getPDO()->query("SELECT q.id,q.tenant_id FROM enrichment_quote q JOIN credit_usage u
            ON u.id=q.usage_id AND u.tenant_id=q.tenant_id WHERE q.deleted=FALSE AND u.deleted=FALSE AND u.state='admitted'
            ORDER BY q.admitted_at,q.id LIMIT " . $limit);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Remove expired value material while retaining immutable financial/provenance receipts. */
    public function expireResults(): void
    {
        $this->entityManager->getPDO()->prepare('UPDATE enrichment_attempt SET cache_ciphertext=NULL
            WHERE cache_expires_at<=? AND cache_ciphertext IS NOT NULL')->execute([$this->clock->now()]);
        $this->entityManager->getPDO()->prepare("UPDATE enrichment_attempt SET result_ciphertext=NULL,
            state=CASE WHEN purchased_at IS NULL AND state='matched' THEN 'waived' ELSE state END
            WHERE purchased_at IS NULL AND result_expires_at<=? AND result_ciphertext IS NOT NULL")->execute([$this->clock->now()]);
        $this->entityManager->getPDO()->prepare('UPDATE enrichment_candidate_cache SET payload_ciphertext=NULL
            WHERE expires_at<=? AND payload_ciphertext IS NOT NULL')->execute([$this->clock->now()]);
        $this->entityManager->getPDO()->prepare('UPDATE enrichment_discovery_call SET result_ciphertext=NULL,request_ciphertext=NULL
            WHERE expires_at<=? AND (result_ciphertext IS NOT NULL OR request_ciphertext IS NOT NULL)')->execute([$this->clock->now()]);
    }

    /** Recheck after any scheduler/process pause and cap the HTTP timeout to the durable lease. */
    public function dispatchWindow(string $tenant, string $quote, string $attemptId, string $token): ?int
    {
        return $this->lock->run($tenant, function (PDO $pdo, array $wallet, string $now) use ($tenant,$quote,$attemptId,$token): ?int {
            [$row,$terms,$usage,$hold] = $this->load($pdo,$tenant,$quote);
            if ($usage['state'] !== 'admitted') return null;
            $q = $pdo->prepare('SELECT * FROM enrichment_attempt WHERE id=? AND quote_id=? AND tenant_id=?');
            $q->execute([$attemptId,$quote,$tenant]); $attempt = $q->fetch(PDO::FETCH_ASSOC);
            if (!$attempt || $attempt['deleted'] || !hash_equals($attempt['claim_token'],$token) ||
                !in_array($attempt['state'],['claimed','pending'],true)) return null;
            if (!$this->authorized($pdo,$terms)) {
                $this->waiveAll($pdo,$quote,$now); $this->close($pdo,$wallet,$now,$row,$usage,$hold); return null;
            }
            $end = min($attempt['deadline_at'],$attempt['lease_until']);
            if ($end <= $now) { $this->waive($pdo,$attemptId,$now); return null; }
            if ($this->providers->get($attempt['provider'])->revision($tenant,$terms['request']['targetType'],$attempt['product'],$terms['identity']) !==
                ($terms['availability'][$attempt['provider']][$attempt['product']] ?? null)) {
                $this->waive($pdo,$attemptId,$now); return null;
            }
            $zone = new \DateTimeZone('UTC');
            return min(30000, ((new \DateTimeImmutable($end,$zone))->getTimestamp() - (new \DateTimeImmutable($now,$zone))->getTimestamp()) * 1000);
        });
    }

    private function load(PDO $pdo, string $tenant, string $quote): array
    {
        $q = $pdo->prepare('SELECT * FROM enrichment_quote WHERE id=? AND tenant_id=? FOR UPDATE');
        $q->execute([$quote,$tenant]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Unknown enrichment operation.');
        $terms = Quotes::decode($row);
        if ((new QuoteInput((object) $terms['request']))->isDiscovery()) throw new Conflict('Discovery requires its own incremental executor.');
        if ($row['confirmed_at'] === null || $row['admitted_at'] === null || $row['usage_id'] === null) throw new Conflict('Enrichment is not admitted.');
        $q = $pdo->prepare('SELECT * FROM credit_usage WHERE id=? AND tenant_id=?'); $q->execute([$row['usage_id'],$tenant]);
        $usage = $q->fetch(PDO::FETCH_ASSOC);
        $q = $pdo->prepare('SELECT * FROM credit_reservation WHERE usage_id=? AND tenant_id=?'); $q->execute([$row['usage_id'],$tenant]);
        $hold = $q->fetch(PDO::FETCH_ASSOC);
        if (!$usage || !$hold || $usage['deleted'] || $hold['deleted'] || $usage['operation_type'] !== 'enrichment' ||
            $usage['operation_key'] !== 'enrichment:' . $quote || $usage['execution_id'] !== 'enrichment:' . $quote ||
            $hold['execution_id'] !== $usage['execution_id'] || ($usage['state'] === 'admitted' && $hold['state'] !== 'held')) {
            throw new Conflict('Invalid enrichment financial binding.');
        }
        return [$row,$terms,$usage,$hold];
    }

    private function authorized(PDO $pdo, array $terms): bool
    {
        $input = new QuoteInput((object) $terms['request']);
        $table = $input->data['targetType'] === 'Contact' ? 'contact' : 'account';
        $q = $pdo->prepare("SELECT id FROM $table WHERE id=? AND tenant_id=? AND deleted=FALSE FOR UPDATE");
        $q->execute([$input->data['targetId'],$input->data['tenantId']]);
        if (!$q->fetchColumn()) return false;
        try {
            $access = $this->actors->forActor($terms['actorId']);
            return Quotes::hash($access->identity($access->target($input))) === Quotes::hash($terms['identity']);
        } catch (\Throwable) {
            // No authoritative purchaser access means no reveal/charge; waive undelivered work.
            return false;
        }
    }

    private function applyContactDateOfBirth(PDO $pdo, array $terms, ?string $dateOfBirth, string $now): void
    {
        if ($dateOfBirth === null || ($terms['request']['targetType'] ?? null) !== 'Contact' ||
            $dateOfBirth > substr($now, 0, 10)) {
            return;
        }
        $input = new QuoteInput((object) $terms['request']);
        $access = $this->actors->forActor($terms['actorId']);
        $columns = $access->writableContactDateOfBirthColumns($access->target($input));
        if (!$columns) return;
        $assignments = array_map(static fn (string $col) => "$col=?", $columns);
        $pdo->prepare('UPDATE contact SET ' . implode(',', $assignments) . ' WHERE id=? AND tenant_id=? AND deleted=FALSE')
            ->execute([...array_fill(0, count($columns), $dateOfBirth), $input->data['targetId'], $input->data['tenantId']]);
    }

    private function close(PDO $pdo, array $wallet, string $now, array $row, array $usage, array $hold): void
    {
        $q = $pdo->prepare('SELECT * FROM enrichment_attempt WHERE quote_id=?'); $q->execute([$row['id']]);
        $charge = Amount::fromString('0'); $products = [];
        $terms = Quotes::decode($row);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $attempt) {
            if ($attempt['deleted'] || $attempt['tenant_id'] !== $row['tenant_id']) throw new Conflict('Invalid enrichment closure ownership.');
            if (in_array($attempt['state'], ['claimed','pending'], true)) throw new Conflict('Enrichment work is still active.');
            if ($attempt['state'] !== 'matched') continue;
            $routes = array_values(array_filter($terms['routes'][$attempt['product']] ?? [], static fn ($r) => $r['provider'] === $attempt['provider']));
            if (count($routes) !== 1 || $routes[0]['credits'] !== $attempt['credits']) throw new Conflict('Enrichment purchase differs from accepted rate.');
            if ($attempt['result_expires_at'] <= $now) {
                $this->waive($pdo,$attempt['id'],$now);
                continue;
            }
            $key = $attempt['product'] . (isset($terms['request']['sources']) ? ':' . $attempt['provider'] : '');
            if (isset($products[$key]) || $attempt['result_ciphertext'] === null) {
                throw new Conflict('Invalid enrichment purchase result.');
            }
            $products[$key] = true;
            $charge = $charge->plus(Amount::fromString($attempt['credits']));
        }
        $this->settlement->settleLocked($pdo,$wallet,$now,$usage,$hold,$charge);
        // The protected purchased marker and charge become visible in the same commit.
        $pdo->prepare("UPDATE enrichment_attempt SET purchased_at=? WHERE quote_id=? AND state='matched'")->execute([$now,$row['id']]);
    }

    private function waive(PDO $pdo, string $id, string $now): void
    {
        $pdo->prepare("UPDATE enrichment_attempt SET state='waived',result_ciphertext=NULL,result_expires_at=NULL,completed_at=? WHERE id=?")
            ->execute([$now,$id]);
    }

    private function waiveAll(PDO $pdo, string $quote, string $now): void
    {
        $pdo->prepare("UPDATE enrichment_attempt SET state='waived',result_ciphertext=NULL,result_expires_at=NULL,cache_ciphertext=NULL,completed_at=?
            WHERE quote_id=? AND purchased_at IS NULL AND state IN ('claimed','pending','matched')")->execute([$now,$quote]);
    }

    private function claimReceipt(array $attempt, array $terms, string $token, string $action): array
    {
        return ['id' => $attempt['id'], 'token' => $token, 'action' => $action, 'provider' => $attempt['provider'],
            'product' => $attempt['product'], 'type' => $terms['request']['targetType'], 'identity' => $terms['identity'],
            'requestId' => $attempt['request_id'] ?? null, 'subjectId' => $attempt['subject_id'] ?? null];
    }

    private static function after(string $time, int $seconds): string
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('UTC')))->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }
}
