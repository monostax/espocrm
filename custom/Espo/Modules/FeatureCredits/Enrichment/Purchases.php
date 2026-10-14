<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureCredits\Accounting\{Amount,FixedSettlement,GrantInput,RecordId,WalletLock};
use InvalidArgumentException;
use PDO;

/** Internal incremental accounting and cache-backed candidate qualification.
 * Direct purchase() is a trusted primitive; purchaseCached() owns distinct/useful/conflict checks.
 * Public discovery admission remains gated on verified supplier activation.
 */
final class Purchases
{
    public function __construct(private WalletLock $lock, private FixedSettlement $settlement,
        private ActorAccess $actors, private ProviderAvailability $availability, private Crypt $crypt) {}

    /** Stable server contribution key, purchased-visible projection only. Prices never come from the caller. */
    public function purchase(string $tenant, string $quote, string $key, string $provider, array $values, string $acquiredAt): array
    {
        GrantInput::identity($quote); GrantInput::timestamp($acquiredAt);
        if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) throw new InvalidArgumentException('Server contribution key required.');
        return $this->lock->run($tenant, function (PDO $pdo, array $wallet, string $now) use ($tenant,$quote,$key,$provider,$values,$acquiredAt): array {
            return $this->purchaseLocked($pdo,$wallet,$now,$tenant,$quote,$key,$provider,$values,$acquiredAt);
        });
    }

    private function purchaseLocked(PDO $pdo,array $wallet,string $now,string $tenant,string $quote,string $key,
        string $provider,array $values,string $acquiredAt,?array $candidate = null): array
    {
            [$row,$terms,$input,$usage,$hold] = $this->load($pdo,$tenant,$quote);
            $access = $this->actors->forActor($terms['actorId']);
            $this->lockTarget($pdo,$input);
            $target = $access->target($input);
            $product = $input->data['products'][0];
            $values = $this->projection($product,$values);
            $steps = array_values(array_filter($terms['routes'][$product],static fn ($step) => $step['provider'] === $provider));
            if (count($steps) !== 1 || $steps[0]['version'] !== $terms['policyId']) throw new Conflict('Supplier is outside accepted discovery route.');
            $price = Amount::fromString($steps[0]['credits']);
            $hash = Quotes::hash(['quoteId' => $quote,'provider' => $provider,'product' => $product,
                'policyId' => $terms['policyId'],'acquiredAt' => $acquiredAt,'values' => $values]);
            $q = $pdo->prepare('SELECT * FROM enrichment_purchase WHERE quote_id=? AND purchase_key=?'); $q->execute([$quote,$key]);
            if ($saved = $q->fetch(PDO::FETCH_ASSOC)) {
                if ($saved['deleted'] || $saved['tenant_id'] !== $tenant || $saved['usage_id'] !== $usage['id'] ||
                    $saved['provider'] !== $provider || $saved['product'] !== $product || $saved['policy_id'] !== $terms['policyId'] ||
                    $saved['credits'] !== (string) $price || $saved['acquired_at'] !== $acquiredAt ||
                    !hash_equals($saved['input_hash'],$hash)) throw new Conflict('Contribution identity already has different input.');
                return self::receipt($saved);
            }
            if ($usage['state'] !== 'admitted' || $hold['state'] !== 'held' ||
                $now >= self::after($row['admitted_at'],300) || $acquiredAt > $now || $acquiredAt <= self::after($now,-90*86400)) {
                throw new Conflict('Discovery purchase is closed, late or expired.');
            }
            $identity = $access->discoveryIdentity($input,$target);
            $permissions = $access->discoveryPermissions($input,$target);
            if (Quotes::hash($identity) !== Quotes::hash($terms['identity']) || Quotes::hash($permissions) !== Quotes::hash($terms['permissions'])) {
                throw new Conflict('Discovery input or purchaser permissions changed.');
            }
            foreach (['cpf','linkedinUrl','dateOfBirth'] as $field) {
                if (isset($values[$field]) && !($permissions['candidateRead'][$field] ?? false)) throw new Conflict('Purchased field is not readable.');
            }
            $available = $this->availability->inspect($tenant,'Account',[$product],$identity,$input->data['waterfall']);
            $revision = $available[$provider][$product] ?? null;
            if ($revision === null || $revision !== ($terms['availability'][$provider][$product] ?? null)) {
                throw new Conflict('Discovery supplier capability changed.');
            }
            $spent = Amount::fromString($usage['settled_credits'] ?? '0');
            if ($spent->plus(Amount::fromString($hold['reserved_credits']))->compareTo(Amount::fromString($terms['maximum'])) !== 0) {
                throw new Conflict('Discovery funded cap is inconsistent.');
            }
            if ($price->compareTo(Amount::fromString($hold['reserved_credits'])) > 0) {
                throw $candidate === null ? new Conflict('Discovery purchase exceeds funded cap.') : PurchaseDeferred::forReason('budget');
            }
            $q = $pdo->prepare('SELECT COUNT(*) FROM enrichment_purchase WHERE quote_id=? AND provider=?'); $q->execute([$quote,$provider]);
            if ((int) $q->fetchColumn() >= ($input->data['count'] ?? 1)) {
                throw $candidate === null ? new Conflict('Discovery provider count exhausted.') : PurchaseDeferred::forReason('provider_limit');
            }
            $q = $pdo->prepare('SELECT COUNT(*) FROM enrichment_attempt WHERE quote_id=?'); $q->execute([$quote]);
            if ((int) $q->fetchColumn() !== 0) throw new Conflict('Discovery cannot mix with field-only execution.');
            $receipt = ['id' => RecordId::generate(),'tenant_id' => $tenant,'quote_id' => $quote,'usage_id' => $usage['id'],
                'purchase_key' => $key,'input_hash' => $hash,'provider' => $provider,'product' => $product,'policy_id' => $terms['policyId'],
                'credits' => (string) $price,'values_ciphertext' => $this->crypt->encrypt(json_encode($values,JSON_THROW_ON_ERROR)),
                'acquired_at' => $acquiredAt,'purchased_at' => $now];
            $pdo->prepare('INSERT INTO enrichment_purchase (id,tenant_id,quote_id,usage_id,purchase_key,input_hash,provider,product,
                policy_id,credits,values_ciphertext,acquired_at,purchased_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_values($receipt));
            if ($candidate !== null) {
                $pdo->prepare('UPDATE enrichment_purchase SET candidate_cache_id=?,candidate_group=?,purchase_kind=? WHERE id=?')
                    ->execute([$candidate['id'],$candidate['group'],$candidate['kind'],$receipt['id']]);
            }
            $this->settlement->purchaseLocked($pdo,$wallet,$now,$usage,$hold,$price,$receipt['id']);
            return self::receipt($receipt);
    }

    /** Server-only cache purchase. Qualification, original-price debit and access share one tenant transaction. */
    public function purchaseCached(string $tenant,string $quote,string $cacheId,bool $deferSupplements = false): array
    {
        GrantInput::identity($cacheId);
        $result = $this->lock->run($tenant,function (PDO $pdo,array $wallet,string $now) use ($tenant,$quote,$cacheId,$deferSupplements): array|Conflict {
            [, $terms,$input] = $this->load($pdo,$tenant,$quote);
            $this->lockTarget($pdo,$input);
            $access = $this->actors->forActor($terms['actorId']); $target = $access->target($input);
            if (Quotes::hash($access->discoveryIdentity($input,$target)) !== Quotes::hash($terms['identity']) ||
                Quotes::hash($access->discoveryPermissions($input,$target)) !== Quotes::hash($terms['permissions'])) {
                throw new Conflict('Discovery input or purchaser permissions changed.');
            }
            $q = $pdo->prepare('SELECT p.* FROM enrichment_purchase p JOIN enrichment_candidate_cache c ON c.id=p.candidate_cache_id
                WHERE c.id=? AND c.tenant_id=? AND c.scope_hash=? AND c.deleted=FALSE AND p.tenant_id=c.tenant_id AND p.deleted=FALSE
                ORDER BY p.purchased_at,p.id');
            $q->execute([$cacheId,$tenant,CandidateCache::scope($terms)]);
            // A purchased projection survives hidden expiry; a free receipt is not a new reveal.
            if ($saved = $q->fetch(PDO::FETCH_ASSOC)) {
                if ($input->data['refresh'] && $saved['quote_id'] !== $quote) throw new Conflict('Fresh discovery cannot buy an earlier acquisition.');
                return $this->verifiedReceipt($pdo,$saved);
            }
            $cache = new CandidateCache($this->lock,$this->crypt,$this->actors,$this->availability);
            [$source,$values] = $cache->loadLocked($pdo,$terms,$quote,$cacheId,$now);
            $q = $pdo->prepare('SELECT p.*,c.candidate_key,c.provider AS source_provider FROM enrichment_purchase p
                JOIN enrichment_candidate_cache c ON c.id=p.candidate_cache_id WHERE p.tenant_id=? AND c.tenant_id=p.tenant_id
                AND c.identity_scope_hash=? AND p.deleted=FALSE ORDER BY p.purchased_at,p.id');
            $q->execute([$tenant,$source['identity_scope_hash']]); $prior = $q->fetchAll(PDO::FETCH_ASSOC);
            $matched = []; $groups = []; $contradiction = false;
            foreach ($prior as $purchase) {
                $this->verifiedReceipt($pdo,$purchase);
                if (($purchase['erased_at'] ?? null) !== null) {
                    if ($purchase['source_provider'] === $source['provider'] && $purchase['candidate_key'] === $source['candidate_key']) {
                        throw new Conflict('Candidate history was erased; cached reappearance is blocked.');
                    }
                    continue;
                }
                $facts = json_decode($this->crypt->decrypt($purchase['values_ciphertext']),true,32,JSON_THROW_ON_ERROR);
                $same = $purchase['source_provider'] === $source['provider'] && $purchase['candidate_key'] === $source['candidate_key'];
                foreach (['cpf','linkedinUrl','cnpj'] as $field) {
                    if (isset($facts[$field],$values[$field]) && $facts[$field] === $values[$field]) $same = true;
                }
                if (!$same) continue; // Never match a name or unrelated provider ID.
                foreach (['cpf','linkedinUrl','cnpj'] as $field) {
                    if (isset($facts[$field],$values[$field]) && $facts[$field] !== $values[$field]) {
                        $contradiction = true;
                    }
                }
                $groups[$purchase['candidate_group']] = true;
                $matched[] = [$purchase,$facts];
            }
            if ($contradiction || count($groups) > 1) return CandidateConflicts::blockLocked($pdo,$tenant,
                [$source['id'],...array_keys($groups)],$now,$contradiction ? 'identity_contradiction' : 'identity_bridge');
            $group = $groups ? array_key_first($groups) : $source['id'];
            if (CandidateConflicts::statusLocked($pdo,$tenant,$group) !== null) {
                return CandidateConflicts::blockLocked($pdo,$tenant,[$source['id']],$now,'identity_contradiction');
            }
            // Include every contribution already attached to the matched person, even if it supplied no identifier.
            if ($groups) {
                $matched = [];
                foreach ($prior as $purchase) {
                    if ($purchase['candidate_group'] !== $group) continue;
                    $facts = json_decode($this->crypt->decrypt($purchase['values_ciphertext']),true,32,JSON_THROW_ON_ERROR);
                    foreach (['cpf','linkedinUrl','cnpj'] as $field) {
                        if (isset($facts[$field],$values[$field]) && $facts[$field] !== $values[$field]) {
                            return CandidateConflicts::blockLocked($pdo,$tenant,[$source['id'],$group],$now,'identity_contradiction');
                        }
                    }
                    $matched[] = [$purchase,$facts];
                }
            }
            $kind = $matched ? 'supplement' : 'candidate';
            $refreshNew = $input->data['refresh'] && !array_filter($matched,static fn ($item) => $item[0]['quote_id'] === $quote);
            if ($matched && !$refreshNew) {
                $additions = [];
                foreach ($values as $field => $value) {
                    $old = [];
                    foreach ($matched as [, $facts]) if (array_key_exists($field,$facts)) $old[] = $facts[$field];
                    if (!$old || ($field === 'role' && !array_filter($old,static fn ($v) => CandidateProjection::equivalent($v,$value)))) $additions[$field] = $value;
                }
                if (!$additions || $source['product'] !== 'socios') {
                    foreach ($matched as [$purchase]) if ($purchase['quote_id'] === $quote) return self::receipt($purchase);
                    return self::receipt($matched[0][0]);
                }
                // Preserve field provenance: a supplement buys additions, never a conflicting replacement.
                $values = $additions;
            }
            if ($refreshNew) {
                // A fresh confirmation may buy an unchanged candidate once; useful supplements remain separate.
                $kind = 'candidate';
            }
            $q = $pdo->prepare("SELECT COUNT(*) FROM enrichment_purchase WHERE quote_id=? AND purchase_kind='candidate' AND deleted=FALSE");
            $q->execute([$quote]); $count = (int) $q->fetchColumn(); $goal = $input->data['count'] ?? 1;
            if ($kind === 'candidate' && $count >= $goal) throw PurchaseDeferred::forReason('candidate_limit');
            if ($kind === 'supplement' && ($count < $goal || $deferSupplements)) throw PurchaseDeferred::forReason('supplement_priority');
            if ($kind === 'supplement') {
                $q = $pdo->prepare("SELECT id FROM enrichment_purchase WHERE quote_id=? AND candidate_group=? AND purchase_kind='candidate' AND deleted=FALSE");
                $q->execute([$quote,$group]);
                if (!$q->fetchColumn()) throw PurchaseDeferred::forReason('candidate_not_selected');
            }
            return $this->purchaseLocked($pdo,$wallet,$now,$tenant,$quote,hash('sha256','cache:' . $cacheId),$source['provider'],
                $values,$source['acquired_at'],['id' => $cacheId,'group' => $group,'kind' => $kind]);
        });
        // Throw only after commit: rolling back a rejected purchase must not erase its quarantine.
        if ($result instanceof Conflict) throw $result;
        return $result;
    }

    /** Internal purchased-only read. Callers must authorize the current actor before exposing values. */
    public function readLocked(PDO $pdo,string $tenant,string $id): array
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Purchased review requires the tenant transaction.');
        GrantInput::identity($id);
        $q = $pdo->prepare('SELECT * FROM enrichment_purchase WHERE tenant_id=? AND id=? AND deleted=FALSE');
        $q->execute([$tenant,$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Unknown purchased contribution.');
        $receipt = $this->verifiedReceipt($pdo,$row);
        [, $terms] = $this->load($pdo,$tenant,$row['quote_id']);
        $conflict = null;
        foreach (array_unique(array_filter([$row['candidate_cache_id'],$row['candidate_group']])) as $anchor) {
            $status = CandidateConflicts::statusLocked($pdo,$tenant,$anchor);
            $q = $pdo->prepare('SELECT identity_scope_hash FROM enrichment_candidate_cache WHERE tenant_id=? AND id=?');
            $q->execute([$tenant,$anchor]);
            $scope = $q->fetchColumn();
            if ($scope === false || (!(($row['erased_at'] ?? null) !== null && $terms['identity'] === []) &&
                $scope !== CandidateCache::identityScope($terms))) throw new Conflict('Purchased candidate scope differs.');
            $conflict ??= $status;
        }
        $erased = ($row['erased_at'] ?? null) !== null;
        $acquisitionQuote = $row['quote_id'];
        if ($row['candidate_cache_id'] !== null) {
            $q = $pdo->prepare('SELECT quote_id FROM enrichment_candidate_cache WHERE tenant_id=? AND id=?');
            $q->execute([$tenant,$row['candidate_cache_id']]); $acquisitionQuote = $q->fetchColumn();
            if (!is_string($acquisitionQuote)) throw new Conflict('Acquisition provenance is unavailable.');
        }
        return ['receipt' => $receipt,'terms' => $terms,'conflict' => $conflict,'erased' => $erased,'applicationStatus' => ApplicationStatus::fromRow($row),
            'acquisitionQuoteId' => $acquisitionQuote,'delivery' => $acquisitionQuote === $row['quote_id'] ? 'live' : 'cache',
            'candidateGroup' => $row['candidate_group'],'kind' => $row['purchase_kind'],
            'values' => $erased ? [] : json_decode($this->crypt->decrypt($row['values_ciphertext']),true,32,JSON_THROW_ON_ERROR)];
    }

    private function verifiedReceipt(PDO $pdo,array $saved): array
    {
        [, $terms,, $usage] = $this->load($pdo,$saved['tenant_id'],$saved['quote_id']);
        $routes = array_values(array_filter($terms['routes'][$saved['product']] ?? [],
            static fn ($step) => $step['provider'] === $saved['provider']));
        $erased = ($saved['erased_at'] ?? null) !== null;
        $values = $erased ? [] : json_decode($this->crypt->decrypt($saved['values_ciphertext']),true,32,JSON_THROW_ON_ERROR);
        $hash = Quotes::hash(['quoteId' => $saved['quote_id'],'provider' => $saved['provider'],'product' => $saved['product'],
            'policyId' => $saved['policy_id'],'acquiredAt' => $saved['acquired_at'],'values' => $values]);
        if (count($routes) !== 1 || $routes[0]['credits'] !== $saved['credits'] || $saved['policy_id'] !== $terms['policyId'] ||
            $saved['usage_id'] !== $usage['id'] || $saved['deleted'] ||
            ($erased ? $saved['values_ciphertext'] !== '' : !hash_equals($saved['input_hash'],$hash))) {
            throw new Conflict('Purchased contribution integrity mismatch.');
        }
        return self::receipt($saved);
    }

    /** Recovery/cancellation closes remaining work without refunding already purchased contributions. */
    public function close(string $tenant, string $quote, ?string $reason = null): array
    {
        GrantInput::identity($quote);
        if ($reason !== null && !in_array($reason,['complete','route_exhausted','budget','deadline','page_limit','completion_limit',
            'unsafe_paid_repeat','provider_failed','ambiguous','authorization_changed','unavailable','cancelled','continuation_expired'],true)) {
            throw new InvalidArgumentException('Invalid discovery stop reason.');
        }
        return $this->lock->run($tenant,function (PDO $pdo,array $wallet,string $now) use ($tenant,$quote,$reason): array {
            [$row,$terms,$input,$usage,$hold] = $this->load($pdo,$tenant,$quote);
            if ($usage['state'] !== 'settled') {
                $this->settlement->settleLocked($pdo,$wallet,$now,$usage,$hold,Amount::fromString('0'));
                $usage['state'] = 'settled'; $usage['settled_at'] = $now; $usage['settled_credits'] ??= '0.0000';
                if ($reason !== null) {
                    $pdo->prepare('UPDATE enrichment_quote SET discovery_stop_reason=? WHERE id=?')->execute([$reason,$quote]);
                    $row['discovery_stop_reason'] = $reason;
                }
            }
            $pdo->prepare("UPDATE enrichment_discovery_call SET state='waived',completed_at=?,request_ciphertext=NULL
                WHERE quote_id=? AND tenant_id=? AND state='claimed'")->execute([$now,$quote,$tenant]);
            return ['quoteId' => $quote,'usageId' => $usage['id'],'state' => $usage['state'],
                'settledCredits' => $usage['settled_credits'],'settledAt' => $usage['settled_at']] +
                (($row['discovery_stop_reason'] ?? null) !== null ? ['stopReason' => $row['discovery_stop_reason']] : []);
        });
    }

    private function load(PDO $pdo,string $tenant,string $quote): array
    {
        $q = $pdo->prepare('SELECT * FROM enrichment_quote WHERE id=? AND tenant_id=? FOR UPDATE'); $q->execute([$quote,$tenant]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Unknown discovery quote.');
        $terms = Quotes::decode($row); $input = new QuoteInput((object) $terms['request']);
        if (!$input->isDiscovery() || $row['confirmed_at'] === null || $row['usage_id'] === null || $row['admitted_at'] === null) {
            throw new Conflict('Discovery is not admitted.');
        }
        $q = $pdo->prepare('SELECT * FROM credit_usage WHERE id=? AND tenant_id=?'); $q->execute([$row['usage_id'],$tenant]); $usage = $q->fetch(PDO::FETCH_ASSOC);
        $q = $pdo->prepare('SELECT * FROM credit_reservation WHERE usage_id=? AND tenant_id=?'); $q->execute([$row['usage_id'],$tenant]); $hold = $q->fetch(PDO::FETCH_ASSOC);
        if (!$usage || !$hold || $usage['deleted'] || $hold['deleted'] || $usage['operation_type'] !== 'enrichment' ||
            $usage['operation_key'] !== 'enrichment:' . $quote || $usage['execution_id'] !== 'enrichment:' . $quote ||
            $hold['execution_id'] !== $usage['execution_id'] || !in_array($usage['state'],['admitted','settled'],true) ||
            $hold['state'] !== ($usage['state'] === 'admitted' ? 'held' : 'settled')) throw new Conflict('Invalid discovery financial binding.');
        return [$row,$terms,$input,$usage,$hold];
    }

    private function lockTarget(PDO $pdo,QuoteInput $input): void
    {
        $q = $pdo->prepare('SELECT id FROM account WHERE id=? AND tenant_id=? AND deleted=FALSE FOR UPDATE');
        $q->execute([$input->data['targetId'],$input->data['tenantId']]);
        if (!$q->fetchColumn()) throw new NotFound('Unknown discovery Account.');
    }

    /** A projection allowlist, not supplier qualification or useful-contribution detection. */
    private function projection(string $product,array $values): array
    {
        return CandidateProjection::normalize($product,$values);
    }

    private static function receipt(array $row): array
    {
        return ['id' => $row['id'],'quoteId' => $row['quote_id'],'usageId' => $row['usage_id'],'provider' => $row['provider'],
            'product' => $row['product'],'policyId' => $row['policy_id'],'credits' => $row['credits'],
            'acquiredAt' => $row['acquired_at'],'purchasedAt' => $row['purchased_at']];
    }

    private static function after(string $time,int $seconds): string
    {
        return (new \DateTimeImmutable($time,new \DateTimeZone('UTC')))->modify("$seconds seconds")->format('Y-m-d H:i:s');
    }
}
