<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\{Conflict,NotFound};
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureCredits\Accounting\{Amount,GrantInput,RecordId,WalletLock};
use InvalidArgumentException;
use PDO;

/** Durable one-shot supplier work. All methods are internal; dispatch envelopes contain private inputs. */
final class DiscoveryCalls
{
    public function __construct(private WalletLock $lock,private CandidateCache $cache,
        private DiscoverySuppliers $suppliers,private Crypt $crypt) {}

    public function page(string $tenant,string $quote,string $provider,int $page,?string $cursor = null): array
    {
        if ($page < 1 || $page > 5 || ($page === 1 && $cursor !== null) ||
            ($page > 1 && ($cursor === null || $cursor === '')) || ($cursor !== null && strlen($cursor) > 2048)) {
            throw new InvalidArgumentException('Invalid discovery page.');
        }
        return $this->lock->run($tenant,function (PDO $pdo,array $wallet,string $now) use ($tenant,$quote,$provider,$page,$cursor): array {
            [$row,$terms,$input] = $this->cache->contextLocked($pdo,$tenant,$quote);
            $this->cache->assertLiveLocked($pdo,$row,$terms,$input,$provider,$now);
            if ($input->data['products'] === ['cnpj'] && $page !== 1) throw new Conflict('Registrant discovery has one response.');
            if ($page > 1) {
                $q = $pdo->prepare("SELECT * FROM enrichment_discovery_call WHERE quote_id=? AND provider=? AND kind='page' AND slot_key=?");
                $q->execute([$quote,$provider,(string) ($page-1)]); $previous = $q->fetch(PDO::FETCH_ASSOC);
                if (!$previous || $previous['deleted'] || $previous['state'] !== 'succeeded' ||
                    ($this->payload($previous,$now)['nextCursor'] ?? null) !== $cursor) throw new Conflict('Page continuation is not durably established.');
            }
            return $this->claimLocked($pdo,$now,$row,$terms,$input,$provider,'page',(string) $page,['cursor' => $cursor]);
        });
    }

    public function completion(string $tenant,string $quote,string $pageId,int $itemIndex): array
    {
        if ($itemIndex < 0) throw new InvalidArgumentException('Invalid completion item.');
        return $this->lock->run($tenant,function (PDO $pdo,array $wallet,string $now) use ($tenant,$quote,$pageId,$itemIndex): array {
            [$row,$terms,$input] = $this->cache->contextLocked($pdo,$tenant,$quote);
            $parent = $this->load($pdo,$tenant,$quote,$pageId);
            $this->cache->assertLiveLocked($pdo,$row,$terms,$input,$parent['provider'],$now);
            if ($parent['kind'] !== 'page' || $parent['state'] !== 'succeeded') throw new Conflict('Completion requires a saved search result.');
            $item = $this->payload($parent,$now)['items'][$itemIndex] ?? null;
            if (!is_array($item) || !isset($item['completionToken'],$item['candidateKey'])) throw new Conflict('Missing completion identity.');
            return $this->claimLocked($pdo,$now,$row,$terms,$input,$parent['provider'],'completion',$item['candidateKey'],
                ['candidateKey' => $item['candidateKey'],'completionToken' => $item['completionToken']]);
        });
    }

    private function claimLocked(PDO $pdo,string $now,array $quote,array $terms,QuoteInput $input,string $provider,
        string $kind,string $slot,array $request): array
    {
        $tenant = $input->data['tenantId']; $product = $input->data['products'][0];
        $scope = CandidateCache::scope($terms);
        $work = Quotes::hash([$kind,$request,$input->data['refresh'] ? $quote['id'] : null]);
        $q = $pdo->prepare('SELECT * FROM enrichment_discovery_call WHERE quote_id=? AND provider=? AND kind=? AND slot_key=?');
        $q->execute([$quote['id'],$provider,$kind,$slot]);
        if ($saved = $q->fetch(PDO::FETCH_ASSOC)) {
            if ($saved['deleted'] || $saved['tenant_id'] !== $tenant || $saved['work_key'] !== $work) throw new Conflict('Discovery work identity changed.');
            return self::receipt($saved); // Never return the dispatch token twice.
        }
        $supplier = $this->suppliers->get($provider);
        $revision = $terms['availability'][$provider][$product];
        if ($supplier->revision($tenant,'Account',$product,$terms['identity']) !== $revision) throw new Conflict('Discovery adapter revision changed.');
        $retention = $supplier->retentionSeconds($product);
        if ($retention < 1 || $retention > 90*86400) throw new Conflict('Discovery retention contract is not verified.');
        $q = $pdo->prepare("SELECT COUNT(*) FROM enrichment_discovery_call WHERE quote_id=? AND state='claimed'");
        $q->execute([$quote['id']]);
        if ((int) $q->fetchColumn() > 0) return ['state' => 'waiting','reason' => 'supplier_work_pending'];
        // Check all earlier attempts, including failed/lost requests. Absence of a result never authorizes redispatch.
        $q = $pdo->prepare('SELECT * FROM enrichment_discovery_call WHERE tenant_id=? AND scope_hash=? AND provider=? AND work_key=?
            AND reused_from_id IS NULL ORDER BY claimed_at,id');
        $q->execute([$tenant,$scope,$provider,$work]); $prior = $q->fetch(PDO::FETCH_ASSOC);
        $payload = null;
        if ($prior) {
            if ($prior['deleted'] || $prior['capability_revision'] !== $revision || (int) $prior['retention_seconds'] !== $retention ||
                !in_array($prior['state'],['succeeded','no_match'],true) || $prior['expires_at'] <= $now ||
                $prior['result_ciphertext'] === null || $prior['quote_id'] === $quote['id']) {
                return ['state' => 'stopped','reason' => 'unsafe_paid_repeat'];
            }
            $payload = $this->payload($prior,$now);
        }
        if (!$prior && $kind === 'completion') {
            $q = $pdo->prepare("SELECT COUNT(*) FROM enrichment_discovery_call WHERE quote_id=? AND kind='completion' AND reused_from_id IS NULL");
            $q->execute([$quote['id']]);
            if ((int) $q->fetchColumn() >= ($input->data['count'] ?? 1)) return ['state' => 'stopped','reason' => 'completion_limit'];
        }
        $q = $pdo->prepare('SELECT reserved_credits FROM credit_reservation WHERE usage_id=? AND tenant_id=? AND deleted=FALSE');
        $q->execute([$quote['usage_id'],$tenant]); $remaining = Amount::fromString($q->fetchColumn());
        $steps = array_values(array_filter($terms['routes'][$product],static fn ($step) => $step['provider'] === $provider));
        if (count($steps) !== 1 || $remaining->compareTo(Amount::fromString($steps[0]['credits'])) < 0) {
            return ['state' => 'stopped','reason' => 'budget'];
        }
        $id = RecordId::generate(); $token = bin2hex(random_bytes(32));
        $deadline = min(self::after($now,min(30,$retention)),self::after($quote['admitted_at'],300));
        $saved = ['id' => $id,'tenant_id' => $tenant,'quote_id' => $quote['id'],'reused_from_id' => $prior['id'] ?? null,
            'scope_hash' => $scope,'provider' => $provider,'product' => $product,'kind' => $kind,'slot_key' => $slot,'work_key' => $work,
            'request_hash' => Quotes::hash($request),'request_ciphertext' => $prior ? null : $this->encrypt($request),
            'capability_revision' => $revision,'retention_seconds' => $retention,'claim_token' => $token,
            'state' => $prior['state'] ?? 'claimed','claimed_at' => $now,
            'deadline_at' => $deadline,
            'completed_at' => $prior ? $now : null,'expires_at' => $prior['expires_at'] ?? $deadline,
            'result_hash' => $prior['result_hash'] ?? null,'payload_hash' => $prior['payload_hash'] ?? null,
            'result_ciphertext' => $payload === null ? null : $this->encrypt($payload)];
        $pdo->prepare('INSERT INTO enrichment_discovery_call (' . implode(',',array_keys($saved)) . ') VALUES (' .
            implode(',',array_fill(0,count($saved),'?')) . ')')->execute(array_values($saved));
        return self::receipt($saved) + ($prior ? [] : ['token' => $token]);
    }

    /** One-shot dispatch acknowledgement immediately before HTTP. A crashed acknowledged request is never retried. */
    public function dispatch(string $tenant,string $quote,string $id,string $token): ?array
    {
        return $this->lock->run($tenant,function (PDO $pdo,array $wallet,string $now) use ($tenant,$quote,$id,$token): ?array {
            $call = $this->load($pdo,$tenant,$quote,$id);
            if ($call['state'] !== 'claimed' || $call['dispatched_at'] !== null || !hash_equals($call['claim_token'],$token)) return null;
            if ($now >= $call['deadline_at']) { $this->waive($pdo,$id,$now); return null; }
            [$row,$terms,$input] = $this->cache->contextLocked($pdo,$tenant,$quote);
            $this->cache->assertLiveLocked($pdo,$row,$terms,$input,$call['provider'],$now);
            $supplier = $this->suppliers->get($call['provider']);
            if ($supplier->revision($tenant,'Account',$call['product'],$terms['identity']) !== $call['capability_revision'] ||
                $supplier->retentionSeconds($call['product']) !== (int) $call['retention_seconds']) throw new Conflict('Supplier contract changed before dispatch.');
            $request = json_decode($this->crypt->decrypt($call['request_ciphertext']),true,32,JSON_THROW_ON_ERROR);
            if (!hash_equals($call['request_hash'],Quotes::hash($request))) throw new Conflict('Discovery request integrity mismatch.');
            $pdo->prepare('UPDATE enrichment_discovery_call SET dispatched_at=? WHERE id=?')->execute([$now,$id]);
            $seconds = (new \DateTimeImmutable($call['deadline_at'],new \DateTimeZone('UTC')))->getTimestamp() -
                (new \DateTimeImmutable($now,new \DateTimeZone('UTC')))->getTimestamp();
            return ['provider' => $call['provider'],'product' => $call['product'],'kind' => $call['kind'],
                'identity' => $terms['identity'],'filters' => $input->data['filters'] ?? [],'count' => $input->data['count'] ?? 1,
                'input' => $request,'timeoutMs' => min(30000,$seconds*1000)];
        });
    }

    /** Outcome + every qualifying acquisition commit atomically; no customer charge occurs here. */
    public function record(string $tenant,string $quote,string $id,string $token,DiscoveryResult $result): array
    {
        return $this->lock->run($tenant,function (PDO $pdo,array $wallet,string $now) use ($tenant,$quote,$id,$token,$result): array {
            $call = $this->load($pdo,$tenant,$quote,$id);
            if (!hash_equals($call['claim_token'],$token)) throw new Conflict('Discovery claim token mismatch.');
            if ($call['state'] !== 'claimed') {
                if ($call['result_hash'] !== null && !hash_equals($call['result_hash'],$result->hash())) throw new Conflict('Discovery outcome replay differs.');
                return self::receipt($call);
            }
            if ($call['dispatched_at'] === null) throw new Conflict('Discovery result has no dispatch acknowledgement.');
            if ($now >= $call['deadline_at']) { $this->waive($pdo,$id,$now); return ['id' => $id,'state' => 'waived']; }
            [$row,$terms,$input] = $this->cache->contextLocked($pdo,$tenant,$quote);
            $this->cache->assertLiveLocked($pdo,$row,$terms,$input,$call['provider'],$now);
            if ($call['kind'] === 'completion' && (count($result->items) > 1 || $result->nextCursor !== null)) throw new Conflict('Completion returned unrelated work.');
            if ($call['product'] === 'cnpj' && (count($result->items) > 1 || $result->nextCursor !== null)) throw new Conflict('Registrant response is not singular.');
            $expires = self::after($now,(int) $call['retention_seconds']); $items = [];
            foreach ($result->items as $index => $item) {
                if ($call['kind'] === 'completion' && ($item['candidateKey'] !== $call['slot_key'] || isset($item['completionToken']))) {
                    throw new Conflict('Completion identity does not match its claimed candidate.');
                }
                if (isset($item['completionToken'])) {
                    if ($call['product'] !== 'employees') throw new Conflict('Only Employee discovery has paid demographic completion.');
                    $items[] = $item; // Still hidden, encrypted and bounded by original acquisition expiry.
                    continue;
                }
                $candidate = $this->cache->storeLocked($pdo,$now,$tenant,$quote,$call['provider'],hash('sha256',$id . ':' . $index),
                    $item['candidateKey'],$now,$expires,$item['values'],$item['incidental'] ?? [],$item['naturalPerson'] ?? false);
                $items[] = ['cacheId' => $candidate['id']];
            }
            $state = $result->state === 'unknown' ? 'waived' : $result->state;
            $envelope = ['items' => $items,'nextCursor' => $result->nextCursor];
            $pdo->prepare('UPDATE enrichment_discovery_call SET state=?,completed_at=?,expires_at=?,result_hash=?,payload_hash=?,result_ciphertext=?,request_ciphertext=NULL WHERE id=?')
                ->execute([$state,$now,$expires,$result->hash(),Quotes::hash($envelope),$this->encrypt($envelope),$id]);
            return ['id' => $id,'state' => $state,'provider' => $call['provider'],'kind' => $call['kind']];
        });
    }

    /** Internal-only continuation envelope: contains encrypted-at-rest supplier cursor/completion IDs, never raw values. */
    public function result(string $tenant,string $quote,string $id): ?array
    {
        return $this->lock->run($tenant,function (PDO $pdo,array $wallet,string $now) use ($tenant,$quote,$id): ?array {
            $this->cache->contextLocked($pdo,$tenant,$quote);
            $call = $this->load($pdo,$tenant,$quote,$id);
            if (!in_array($call['state'],['succeeded','no_match'],true) || $call['expires_at'] <= $now || $call['result_ciphertext'] === null) return null;
            return $this->payload($call,$now);
        });
    }

    /** No actor/supplier authorization required to fence timed-out work. Does not refund purchased contributions. */
    public function expire(string $tenant,string $quote): int
    {
        GrantInput::identity($quote);
        return $this->lock->run($tenant,function (PDO $pdo,array $wallet,string $now) use ($tenant,$quote): int {
            $q = $pdo->prepare("UPDATE enrichment_discovery_call SET state='waived',completed_at=?,request_ciphertext=NULL
                WHERE tenant_id=? AND quote_id=? AND state='claimed' AND deadline_at<=?");
            $q->execute([$now,$tenant,$quote,$now]); return $q->rowCount();
        });
    }

    /** Internal execution snapshot. Contains frozen identity; never use as an API/job response. */
    public function snapshot(string $tenant,string $quote): array
    {
        return $this->lock->run($tenant,function (PDO $pdo,array $wallet,string $now) use ($tenant,$quote): array {
            [$row,$terms] = $this->cache->contextLocked($pdo,$tenant,$quote);
            $q = $pdo->prepare('SELECT u.state,r.reserved_credits FROM credit_usage u JOIN credit_reservation r ON r.usage_id=u.id
                AND r.tenant_id=u.tenant_id WHERE u.id=? AND u.tenant_id=? AND u.deleted=FALSE AND r.deleted=FALSE');
            $q->execute([$row['usage_id'],$tenant]); $financial = $q->fetch(PDO::FETCH_ASSOC);
            if (!$financial) throw new Conflict('Missing discovery reservation.');
            $q = $pdo->prepare("SELECT COUNT(*) FROM enrichment_purchase WHERE quote_id=? AND purchase_kind='candidate' AND deleted=FALSE");
            $q->execute([$quote]); $count = (int) $q->fetchColumn();
            $q = $pdo->prepare('SELECT provider,COUNT(*) AS total FROM enrichment_purchase WHERE quote_id=? AND deleted=FALSE GROUP BY provider');
            $q->execute([$quote]); $providerCounts = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) $providerCounts[$p['provider']] = (int) $p['total'];
            return ['terms' => $terms,'state' => $financial['state'],'remaining' => $financial['reserved_credits'],
                'candidates' => $count,'providerCounts' => $providerCounts,'expired' => $now >= self::after($row['admitted_at'],300)];
        });
    }

    private function payload(array $call,string $now): array
    {
        if ($call['result_ciphertext'] === null || $call['expires_at'] <= $now) throw new Conflict('Discovery continuation expired.');
        $payload = json_decode($this->crypt->decrypt($call['result_ciphertext']),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !is_string($call['payload_hash']) || !hash_equals($call['payload_hash'],Quotes::hash($payload))) {
            throw new Conflict('Discovery continuation integrity mismatch.');
        }
        return $payload;
    }

    private function load(PDO $pdo,string $tenant,string $quote,string $id): array
    {
        GrantInput::identity($id);
        $q = $pdo->prepare('SELECT * FROM enrichment_discovery_call WHERE id=? AND tenant_id=? AND quote_id=?');
        $q->execute([$id,$tenant,$quote]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['deleted']) throw new NotFound('Unknown discovery call.');
        return $row;
    }

    private function encrypt(array $value): string { return $this->crypt->encrypt(json_encode($value,JSON_THROW_ON_ERROR)); }
    private function waive(PDO $pdo,string $id,string $now): void
    {
        $pdo->prepare("UPDATE enrichment_discovery_call SET state='waived',completed_at=?,request_ciphertext=NULL WHERE id=?")->execute([$now,$id]);
    }
    private static function receipt(array $row): array
    {
        return ['id' => $row['id'],'state' => $row['state'],'provider' => $row['provider'],'kind' => $row['kind']];
    }
    private static function after(string $time,int $seconds): string
    {
        return (new \DateTimeImmutable($time,new \DateTimeZone('UTC')))->modify("$seconds seconds")->format('Y-m-d H:i:s');
    }
}
